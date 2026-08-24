<?php
/**
 * Conversion of ComfyUI editor graphs into the API prompt format.
 *
 * Every workflow ComfyUI publishes is an editor graph: nodes carry positional
 * `widgets_values`, connections live in a separate link table, and whole
 * regions can be collapsed into reusable subgraphs. ComfyUI's executor accepts
 * none of that; it wants a flat `{id: {class_type, inputs}}` map. The editor
 * normally does the translation in the browser, which is why "Save (API
 * Format)" exists and why a downloaded template cannot be submitted as-is.
 *
 * This class performs that translation server-side so a published template can
 * be discovered, converted, and stored as a World Graph Studio Template without
 * a human ever opening the ComfyUI editor. Positional widget values can only be
 * named by consulting the target instance's `/object_info`, so conversion is
 * deliberately bound to a specific ComfyUI: a graph that converts is a graph
 * that instance can actually run.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ComfyUI editor-graph to API-prompt converter.
 */
class Comfy_Graph {

	/**
	 * Socket types that a widget can supply a value for. Anything else is a
	 * link-only socket and never consumes a `widgets_values` slot.
	 */
	const WIDGET_TYPES = [ 'INT', 'FLOAT', 'STRING', 'BOOLEAN', 'COMBO' ];

	/**
	 * Node types that only pass a value through and never execute.
	 */
	const PASSTHROUGH_TYPES = [ 'Reroute', 'Reroute (rgthree)', 'PrimitiveNode' ];

	/**
	 * Seed inputs that must be re-rolled per job or every render repeats.
	 */
	const SEED_FIELDS = [ 'seed', 'noise_seed' ];

	/**
	 * Inputs that carry prompt text. Encoders disagree: Flux splits a prompt
	 * across `clip_l` and `t5xxl`, SDXL across `text_g` and `text_l`, and newer
	 * graphs hoist it into a standalone string node feeding several encoders.
	 */
	const PROMPT_FIELDS = [ 'text', 'prompt', 'clip_l', 't5xxl', 'text_g', 'text_l', 'string' ];

	/**
	 * Media slots whose uploaded ComfyUI input filename can be bound to a
	 * proven image-loader input. Other media types need their own reviewed
	 * loader contract instead of guessing at arbitrary string widgets.
	 */
	const IMAGE_MEDIA_SLOTS = [ 'image', 'start_frame', 'end_frame' ];

	/**
	 * Exact executable node/input pairs known to read a filename from
	 * ComfyUI's input directory.
	 *
	 * @var array<string, string>
	 */
	const MEDIA_LOADER_INPUTS = [ 'LoadImage' => 'image' ];

	/**
	 * Recursion ceiling for link resolution through subgraphs and reroutes.
	 */
	const MAX_DEPTH = 128;

	/**
	 * Decoded `/object_info` for the instance being converted against.
	 *
	 * @var array
	 */
	private static $object_info = [];

	/**
	 * Subgraph definitions, keyed by definition ID.
	 *
	 * @var array
	 */
	private static $subgraphs = [];

	/**
	 * Graph contexts (the root graph plus one per subgraph instance).
	 *
	 * @var array
	 */
	private static $contexts = [];

	/**
	 * Executable nodes discovered across every context, keyed by unique ID.
	 *
	 * @var array
	 */
	private static $nodes = [];

	/**
	 * Whether a decoded workflow is an editor graph rather than API format.
	 *
	 * @param array $graph Decoded workflow.
	 * @return bool
	 */
	public static function is_editor_graph( array $graph ): bool {
		return isset( $graph['nodes'] ) && is_array( $graph['nodes'] );
	}

	/**
	 * Convert an editor graph into an API prompt.
	 *
	 * @param array $graph       Decoded editor graph, or an already-API graph.
	 * @param array $object_info Decoded ComfyUI `/object_info` payload.
	 * @return array|WP_Error API-format workflow.
	 */
	public static function to_api( array $graph, array $object_info ) {
		if ( ! self::is_editor_graph( $graph ) ) {
			return $graph;
		}
		if ( empty( $object_info ) ) {
			return new WP_Error(
				'worldgraph_comfy_graph_no_catalog',
				__( 'Converting a published ComfyUI workflow needs the target instance\'s node catalog. Check that ComfyUI is reachable and try again.', 'worldgraph' )
			);
		}

		self::$object_info = $object_info;
		self::$subgraphs   = [];
		self::$contexts    = [];
		self::$nodes       = [];

		foreach ( (array) ( $graph['definitions']['subgraphs'] ?? [] ) as $definition ) {
			if ( is_array( $definition ) && ! empty( $definition['id'] ) ) {
				self::$subgraphs[ (string) $definition['id'] ] = $definition;
			}
		}

		self::register_context( '', $graph, null, [] );

		$api      = [];
		$is_link  = [];
		$missing  = [];
		foreach ( self::$nodes as $uid => $record ) {
			$node  = $record['node'];
			$class = (string) ( $node['type'] ?? '' );
			$mode  = (int) ( $node['mode'] ?? 0 );

			// 2 is muted and 4 is bypassed; both are removed from execution and
			// their consumers were already re-pointed during link resolution.
			if ( 2 === $mode || 4 === $mode ) {
				continue;
			}
			if ( ! isset( self::$object_info[ $class ] ) ) {
				// Notes, markdown, and other editor-only nodes are absent from
				// /object_info by design; a genuinely missing custom node is
				// reported so the operator learns what to install.
				if ( ! self::is_editor_only( $class ) ) {
					$missing[ $class ] = true;
				}
				continue;
			}

			$inputs = self::widget_inputs( $class, $node );
			foreach ( (array) ( $node['inputs'] ?? [] ) as $entry ) {
				$name = (string) ( $entry['name'] ?? '' );
				if ( '' === $name || ! isset( $entry['link'] ) || null === $entry['link'] ) {
					continue;
				}

				$resolved = self::resolve_link( $record['ctx'], $entry['link'] );
				if ( null === $resolved ) {
					continue;
				}
				if ( '__literal__' === $resolved[0] ) {
					$inputs[ $name ] = $resolved[1];
					continue;
				}

				$inputs[ $name ]   = [ (string) $resolved[0], (int) $resolved[1] ];
				$is_link[ $uid ][] = $name;
			}

			$api[ $uid ] = [
				'class_type' => $class,
				'inputs'     => $inputs,
				'_meta'      => [ 'title' => (string) ( $node['title'] ?? $class ) ],
			];
		}

		if ( empty( $api ) ) {
			return new WP_Error(
				'worldgraph_comfy_graph_empty',
				! empty( $missing )
					? sprintf(
						/* translators: %s: comma-separated node class names. */
						__( 'This workflow needs ComfyUI nodes that are not installed: %s', 'worldgraph' ),
						implode( ', ', array_keys( $missing ) )
					)
					: __( 'This workflow contained no executable ComfyUI nodes.', 'worldgraph' )
			);
		}

		$api = self::drop_dangling_links( $api, $is_link );
		$api = self::prune_unreachable( $api, $is_link );

		if ( ! empty( $missing ) ) {
			// A workflow that lost a node is a workflow that will not run, so
			// this is an error rather than a silently degraded graph.
			return new WP_Error(
				'worldgraph_comfy_graph_missing_nodes',
				sprintf(
					/* translators: %s: comma-separated node class names. */
					__( 'This workflow needs ComfyUI nodes that are not installed: %s', 'worldgraph' ),
					implode( ', ', array_keys( $missing ) )
				),
				[ 'missing_nodes' => array_keys( $missing ) ]
			);
		}

		return self::renumber( $api, $is_link );
	}

	/**
	 * Replace positive prompt text with the placeholder the generation runner
	 * substitutes per job. Negative conditioning remains a Template concern:
	 * preserving its literals also preserves distinct model/stage branches.
	 *
	 * @param array $api API-format workflow.
	 * @return array
	 */
	public static function apply_prompt_placeholders( array $api ): array {
		$assigned = [];

		foreach ( $api as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			foreach ( [ 'positive' => '{{prompt}}' ] as $socket => $placeholder ) {
				$reference = $node['inputs'][ $socket ] ?? null;
				if ( ! self::is_reference( $reference ) ) {
					continue;
				}
				$target = self::find_text_input( $api, (string) $reference[0], [] );
				if ( null !== $target && ! isset( $assigned[ $target ] ) ) {
					$assigned[ $target ] = $placeholder;
				}
			}
		}

		// A sampler-less graph (API-node workflows, for one) still has exactly
		// one text field that means "the prompt".
		if ( empty( $assigned ) ) {
			$candidates = [];
			foreach ( $api as $uid => $node ) {
				if ( ! empty( self::prompt_fields( $node ) ) ) {
					$candidates[] = (string) $uid;
				}
			}
			if ( 1 === count( $candidates ) ) {
				$assigned[ $candidates[0] ] = '{{prompt}}';
			}
		}

		foreach ( $assigned as $uid => $placeholder ) {
			foreach ( self::prompt_fields( $api[ $uid ] ) as $field ) {
				$api[ $uid ]['inputs'][ $field ] = $placeholder;
			}
		}

		return $api;
	}

	/**
	 * Bind a modality's image media slots to proven `LoadImage.image` inputs.
	 *
	 * Published and pasted workflows commonly retain a demo filename instead
	 * of a `{{slot}}` marker. A runtime upload is useless unless that literal is
	 * replaced. We only infer a binding when the target is unambiguous. An
	 * explicit placeholder is authoritative and is preserved; start/end frames
	 * can also be proven by a semantic node title or a downstream guide node's
	 * `frame_idx` (`0` for the first frame, a negative value for the last).
	 *
	 * @param array                   $api             ComfyUI API-format workflow.
	 * @param array<int, string>      $slots           Declared modality media slots.
	 * @param array<int, string>|null $requested_slots Slots that have a value for
	 *                                                  this run; all declared
	 *                                                  slots when omitted.
	 * @return array|WP_Error Workflow with media placeholders, or a closed
	 *                        failure when a safe binding cannot be proven.
	 */
	public static function apply_media_placeholders( array $api, array $slots, ?array $requested_slots = null ) {
		$declared_slots = array_values( array_unique( array_intersect( array_map( 'strval', $slots ), self::IMAGE_MEDIA_SLOTS ) ) );
		$slots          = null === $requested_slots
			? $declared_slots
			: array_values( array_unique( array_intersect( array_map( 'strval', $requested_slots ), $declared_slots ) ) );
		if ( empty( $slots ) ) {
			return $api;
		}
		if ( self::is_editor_graph( $api ) ) {
			return new WP_Error(
				'worldgraph_comfy_media_bindings_need_api_workflow',
				__( 'Media bindings can only be applied after a ComfyUI editor workflow has been converted to API format.', 'worldgraph' )
			);
		}

		$targets   = self::media_loader_targets( $api );
		$declared  = array_fill_keys( $declared_slots, true );
		$requested = array_fill_keys( $slots, true );
		$assigned  = [];
		$open      = [];

		foreach ( $targets as $key => $target ) {
			$value = $target['value'];
			if ( is_string( $value ) && preg_match_all( '/\{\{([a-z][a-z0-9_]*)\}\}/i', $value, $matches ) ) {
				$slot = 1 === count( $matches[1] ) ? (string) $matches[1][0] : '';
				if ( '' === $slot || $value !== '{{' . $slot . '}}' || ! isset( $declared[ $slot ] ) ) {
					return self::media_binding_error(
						'worldgraph_comfy_media_placeholder_unsafe',
						__( 'A media placeholder must exactly match a declared slot on a supported LoadImage input.', 'worldgraph' ),
						$slots,
						array_values( $targets )
					);
				}

				$assigned[ $slot ][] = $key;
				continue;
			}

			$open[ $key ] = $target;
		}

		if ( self::has_unsafe_media_placeholder( $api, $declared ) ) {
			return self::media_binding_error(
				'worldgraph_comfy_media_placeholder_unsafe',
				__( 'A declared media placeholder appears outside a supported LoadImage input.', 'worldgraph' ),
				$slots,
				array_values( $targets )
			);
		}

		if ( in_array( 'image', $slots, true ) ) {
			if ( ! empty( $assigned['image'] ) ) {
				return $api;
			}
			if ( 1 !== count( $open ) ) {
				return self::unresolved_media_binding_error( 'image', $slots, $targets, count( $open ) );
			}
			$key = (string) array_key_first( $open );
			self::assign_media_placeholder( $api, $open[ $key ], 'image' );

			return $api;
		}
		if ( empty( array_diff( $slots, array_keys( $assigned ) ) ) ) {
			return $api;
		}

		$role_targets = [];
		foreach ( $open as $key => $target ) {
			$role = self::frame_role_for_target( $api, (string) $target['node_id'] );
			if ( 'ambiguous' === $role ) {
				return self::unresolved_media_binding_error( 'start_frame', $slots, $targets, count( $open ) );
			}
			if ( '' !== $role && isset( $requested[ $role ] ) && ! isset( $assigned[ $role ] ) ) {
				$role_targets[ $role ][ $key ] = $target;
			}
		}

		foreach ( $role_targets as $role => $matches ) {
			if ( 1 !== count( $matches ) ) {
				return self::unresolved_media_binding_error( $role, $slots, $targets, count( $matches ) );
			}
			$key = (string) array_key_first( $matches );
			self::assign_media_placeholder( $api, $matches[ $key ], $role );
			$assigned[ $role ][] = $key;
			unset( $open[ $key ] );
		}

		// With one side explicitly or semantically proven, a sole remaining
		// loader is unambiguously the other requested side. Once every requested
		// slot is proven, untouched loaders are Template-owned auxiliary inputs.
		foreach ( [ 'start_frame', 'end_frame' ] as $slot ) {
			if ( ! isset( $requested[ $slot ] ) || isset( $assigned[ $slot ] ) ) {
				continue;
			}
			if ( 1 === count( $open ) ) {
				$key = (string) array_key_first( $open );
				self::assign_media_placeholder( $api, $open[ $key ], $slot );
				$assigned[ $slot ][] = $key;
				unset( $open[ $key ] );
				continue;
			}
			return self::unresolved_media_binding_error( $slot, $slots, $targets, count( $open ) );
		}

		return $api;
	}

	/**
	 * Declared media placeholders currently attached to supported loader inputs.
	 *
	 * @param array $api ComfyUI API-format workflow.
	 * @return array<int, string>
	 */
	public static function media_placeholders( array $api ): array {
		$slots = [];
		foreach ( self::media_loader_targets( $api ) as $target ) {
			$value = $target['value'];
			if ( ! is_string( $value ) || ! preg_match( '/^\{\{([a-z][a-z0-9_]*)\}\}$/i', $value, $match ) ) {
				continue;
			}
			if ( in_array( $match[1], self::IMAGE_MEDIA_SLOTS, true ) ) {
				$slots[] = $match[1];
			}
		}

		return array_values( array_unique( $slots ) );
	}

	/**
	 * Supported loader inputs in an API workflow.
	 *
	 * @param array $api API-format workflow.
	 * @return array<string, array{node_id: string, field: string, value: mixed}>
	 */
	private static function media_loader_targets( array $api ): array {
		$targets = [];
		foreach ( $api as $node_id => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$class = (string) ( $node['class_type'] ?? '' );
			if ( ! isset( self::MEDIA_LOADER_INPUTS[ $class ] ) ) {
				continue;
			}

			$field = self::MEDIA_LOADER_INPUTS[ $class ];
			$key   = (string) $node_id . '|' . $field;
			$targets[ $key ] = [
				'node_id' => (string) $node_id,
				'field'   => $field,
				'value'   => $node['inputs'][ $field ] ?? null,
			];
		}

		return $targets;
	}

	/**
	 * Whether a declared media placeholder occurs anywhere except as the exact
	 * value of a supported loader input.
	 *
	 * @param array               $api      API-format workflow.
	 * @param array<string, bool> $declared Declared slot lookup.
	 * @return bool
	 */
	private static function has_unsafe_media_placeholder( array $api, array $declared ): bool {
		foreach ( $api as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$class = (string) ( $node['class_type'] ?? '' );
			$field = self::MEDIA_LOADER_INPUTS[ $class ] ?? '';
			foreach ( (array) ( $node['inputs'] ?? [] ) as $name => $value ) {
				$strings = [];
				if ( is_array( $value ) ) {
					array_walk_recursive( $value, static function ( $item ) use ( &$strings ): void {
						if ( is_string( $item ) ) {
							$strings[] = $item;
						}
					} );
				} elseif ( is_string( $value ) ) {
					$strings[] = $value;
				}

				foreach ( array_unique( $strings ) as $string ) {
					foreach ( array_keys( $declared ) as $slot ) {
						if ( false === strpos( $string, '{{' . $slot . '}}' ) ) {
							continue;
						}
						if ( $field !== (string) $name || $string !== '{{' . $slot . '}}' ) {
							return true;
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * Assign a slot placeholder to one proven loader input.
	 *
	 * @param array  $api    Workflow passed by reference.
	 * @param array  $target Loader target descriptor.
	 * @param string $slot   Declared media slot.
	 */
	private static function assign_media_placeholder( array &$api, array $target, string $slot ): void {
		$api[ $target['node_id'] ]['inputs'][ $target['field'] ] = '{{' . $slot . '}}';
	}

	/**
	 * Infer whether a loader feeds the first or last frame of a video guide.
	 *
	 * @param array  $api     API-format workflow.
	 * @param string $node_id Loader node ID.
	 * @return string `start_frame`, `end_frame`, `ambiguous`, or an empty string.
	 */
	private static function frame_role_for_target( array $api, string $node_id ): string {
		$evidence = [];
		$queue    = [ $node_id ];
		$seen     = [];

		while ( ! empty( $queue ) && count( $seen ) <= self::MAX_DEPTH ) {
			$current = (string) array_shift( $queue );
			if ( isset( $seen[ $current ] ) || ! isset( $api[ $current ] ) ) {
				continue;
			}
			$seen[ $current ] = true;

			$role = self::frame_role_for_node( $api[ $current ] );
			if ( '' !== $role ) {
				$evidence[ $role ] = true;
			}

			foreach ( $api as $consumer_id => $consumer ) {
				if ( isset( $seen[ (string) $consumer_id ] ) || ! is_array( $consumer ) ) {
					continue;
				}
				foreach ( (array) ( $consumer['inputs'] ?? [] ) as $input_name => $input ) {
					if ( self::is_reference( $input ) && (string) $input[0] === $current ) {
						$socket_role = self::frame_role_for_input_name( (string) $input_name );
						if ( '' !== $socket_role ) {
							$evidence[ $socket_role ] = true;
						}
						$queue[] = (string) $consumer_id;
						break;
					}
				}
			}
		}

		if ( count( $evidence ) > 1 ) {
			return 'ambiguous';
		}

		return $evidence ? (string) array_key_first( $evidence ) : '';
	}

	/**
	 * Infer a frame role from a consumer input socket such as the core WAN
	 * `start_image` and `end_image` inputs.
	 *
	 * @param string $name Consumer input name.
	 * @return string `start_frame`, `end_frame`, or an empty string.
	 */
	private static function frame_role_for_input_name( string $name ): string {
		$name = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '_', $name ) );
		$name = trim( $name, '_' );

		if ( preg_match( '/(?:^|_)(?:start|first|initial|begin|beginning)(?:_|$)/', $name ) ) {
			return 'start_frame';
		}
		if ( preg_match( '/(?:^|_)(?:end|last|final|ending)(?:_|$)/', $name ) ) {
			return 'end_frame';
		}

		return '';
	}

	/**
	 * Frame-role evidence carried by one loader or downstream guide node.
	 *
	 * @param array $node API-format node.
	 * @return string
	 */
	private static function frame_role_for_node( array $node ): string {
		$roles = [];
		$title = (string) ( $node['_meta']['title'] ?? '' );
		if ( preg_match( '/\b(start|first|initial|begin|beginning)\b/i', $title ) ) {
			$roles['start_frame'] = true;
		}
		if ( preg_match( '/\b(end|last|final|ending)\b/i', $title ) ) {
			$roles['end_frame'] = true;
		}

		foreach ( [ 'frame_idx', 'frame_index' ] as $field ) {
			$value = $node['inputs'][ $field ] ?? null;
			if ( ! is_numeric( $value ) ) {
				continue;
			}
			if ( 0.0 === (float) $value ) {
				$roles['start_frame'] = true;
			} elseif ( (float) $value < 0 ) {
				$roles['end_frame'] = true;
			}
		}

		return 1 === count( $roles ) ? (string) array_key_first( $roles ) : ( count( $roles ) > 1 ? 'ambiguous' : '' );
	}

	/**
	 * Construct a stable, inspectable media-binding error.
	 *
	 * @param string             $code    Error code.
	 * @param string             $message Human-readable message.
	 * @param array<int, string> $slots   Declared media slots.
	 * @param array<int, array>  $targets Candidate loader targets.
	 * @return WP_Error
	 */
	private static function media_binding_error( string $code, string $message, array $slots, array $targets ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			[
				'slots'      => $slots,
				'candidates' => array_map( static function ( array $target ): array {
					return [ 'node_id' => $target['node_id'], 'field' => $target['field'] ];
				}, $targets ),
			]
		);
	}

	/**
	 * Error for a missing or ambiguous automatic binding.
	 *
	 * @param string             $slot       Slot that could not be bound.
	 * @param array<int, string> $slots      Declared slots.
	 * @param array<string,array> $targets   Candidate loader targets.
	 * @param int                $open_count Number of unresolved candidates.
	 * @return WP_Error
	 */
	private static function unresolved_media_binding_error( string $slot, array $slots, array $targets, int $open_count ): WP_Error {
		$message = 0 === $open_count
			? sprintf(
				/* translators: %s: media input slot name. */
				__( 'The workflow has no supported LoadImage input for the %s media slot.', 'worldgraph' ),
				$slot
			)
			: sprintf(
				/* translators: 1: media input slot name, 2: number of possible loader inputs. */
				__( 'The workflow has %2$d possible LoadImage inputs for %1$s; add explicit media placeholders to identify them.', 'worldgraph' ),
				$slot,
				$open_count
			);

		return self::media_binding_error( 'worldgraph_comfy_media_binding_ambiguous', $message, $slots, array_values( $targets ) );
	}

	/**
	 * The prompt-carrying string inputs a node holds a literal value for.
	 *
	 * @param array $node API-format node.
	 * @return array<int, string>
	 */
	private static function prompt_fields( array $node ): array {
		$fields = self::PROMPT_FIELDS;
		if ( 0 === strpos( (string) ( $node['class_type'] ?? '' ), 'PrimitiveString' ) ) {
			$fields[] = 'value';
		}

		return array_values( array_filter( $fields, static function ( string $field ) use ( $node ): bool {
			return is_string( $node['inputs'][ $field ] ?? null );
		} ) );
	}

	/**
	 * Re-roll every literal seed in a workflow.
	 *
	 * @param array $api API-format workflow.
	 * @return array
	 */
	public static function randomize_seeds( array $api ): array {
		foreach ( $api as $uid => $node ) {
			foreach ( self::SEED_FIELDS as $field ) {
				if ( isset( $node['inputs'][ $field ] ) && is_numeric( $node['inputs'][ $field ] ) ) {
					$api[ $uid ]['inputs'][ $field ] = wp_rand( 0, PHP_INT_MAX >> 1 );
				}
			}
		}

		return $api;
	}

	/**
	 * Register a graph body and every subgraph instance inside it.
	 *
	 * @param string      $key      Context key; the empty string is the root.
	 * @param array       $body     Graph body with `nodes` and `links`.
	 * @param string|null $parent   Parent context key, or null for the root.
	 * @param array       $instance Subgraph instance node in the parent context.
	 */
	private static function register_context( string $key, array $body, ?string $parent, array $instance ): void {
		if ( isset( self::$contexts[ $key ] ) ) {
			return;
		}

		$context = [
			'prefix'   => '' === $key ? '' : $key . ':',
			'parent'   => $parent,
			'instance' => $instance,
			'def'      => $body,
			'nodes'    => [],
			'links'    => [],
		];

		foreach ( (array) ( $body['links'] ?? [] ) as $link ) {
			$normalized = self::normalize_link( $link );
			if ( null !== $normalized ) {
				$context['links'][ $normalized['id'] ] = $normalized;
			}
		}
		foreach ( (array) ( $body['nodes'] ?? [] ) as $node ) {
			if ( is_array( $node ) && isset( $node['id'] ) ) {
				$context['nodes'][ (string) $node['id'] ] = $node;
			}
		}

		self::$contexts[ $key ] = $context;

		foreach ( $context['nodes'] as $id => $node ) {
			$type = (string) ( $node['type'] ?? '' );
			$uid  = $context['prefix'] . $id;

			if ( isset( self::$subgraphs[ $type ] ) ) {
				self::register_context( $uid, self::$subgraphs[ $type ], $key, $node );
				continue;
			}
			if ( in_array( $type, self::PASSTHROUGH_TYPES, true ) ) {
				continue;
			}

			self::$nodes[ $uid ] = [ 'ctx' => $key, 'node' => $node ];
		}
	}

	/**
	 * Normalize the two link encodings ComfyUI emits: positional arrays in the
	 * root graph and objects inside subgraph definitions.
	 *
	 * @param mixed $link Raw link.
	 * @return array|null
	 */
	private static function normalize_link( $link ): ?array {
		if ( ! is_array( $link ) ) {
			return null;
		}
		if ( isset( $link['id'] ) ) {
			return [
				'id'          => (string) $link['id'],
				'origin_id'   => (string) ( $link['origin_id'] ?? '' ),
				'origin_slot' => (int) ( $link['origin_slot'] ?? 0 ),
				'target_id'   => (string) ( $link['target_id'] ?? '' ),
				'target_slot' => (int) ( $link['target_slot'] ?? 0 ),
			];
		}
		if ( count( $link ) < 5 ) {
			return null;
		}

		return [
			'id'          => (string) $link[0],
			'origin_id'   => (string) $link[1],
			'origin_slot' => (int) $link[2],
			'target_id'   => (string) $link[3],
			'target_slot' => (int) $link[4],
		];
	}

	/**
	 * Resolve a link to the executable node and output slot that feeds it.
	 *
	 * @param string $ctx_key Context key the link belongs to.
	 * @param mixed  $link_id Link ID.
	 * @param int    $depth   Recursion depth.
	 * @return array|null `[uid, slot]`, `['__literal__', value]`, or null.
	 */
	private static function resolve_link( string $ctx_key, $link_id, int $depth = 0 ): ?array {
		if ( null === $link_id || $depth > self::MAX_DEPTH ) {
			return null;
		}
		$link = self::$contexts[ $ctx_key ]['links'][ (string) $link_id ] ?? null;
		if ( null === $link ) {
			return null;
		}

		return self::resolve_output( $ctx_key, $link['origin_id'], $link['origin_slot'], $depth + 1 );
	}

	/**
	 * Resolve an output slot, following subgraph boundaries, reroutes, and
	 * bypassed nodes until an executable node is reached.
	 *
	 * @param string $ctx_key   Context key.
	 * @param string $origin_id Origin node ID within that context.
	 * @param int    $slot      Output slot index.
	 * @param int    $depth     Recursion depth.
	 * @return array|null
	 */
	private static function resolve_output( string $ctx_key, string $origin_id, int $slot, int $depth ): ?array {
		if ( $depth > self::MAX_DEPTH ) {
			return null;
		}
		$context = self::$contexts[ $ctx_key ] ?? null;
		if ( null === $context ) {
			return null;
		}

		$input_boundary = (string) ( $context['def']['inputNode']['id'] ?? '-10' );
		if ( null !== $context['parent'] && $origin_id === $input_boundary ) {
			return self::resolve_boundary_input( $ctx_key, $slot, $depth + 1 );
		}

		$node = $context['nodes'][ $origin_id ] ?? null;
		if ( null === $node ) {
			return null;
		}

		$type = (string) ( $node['type'] ?? '' );
		$uid  = $context['prefix'] . $origin_id;

		if ( isset( self::$subgraphs[ $type ] ) ) {
			return self::resolve_subgraph_output( $uid, $slot, $depth + 1 );
		}

		$mode = (int) ( $node['mode'] ?? 0 );
		if ( 2 === $mode ) {
			return null;
		}
		if ( 4 === $mode || in_array( $type, self::PASSTHROUGH_TYPES, true ) ) {
			return self::resolve_passthrough( $ctx_key, $node, $slot, $depth + 1 );
		}

		return [ $uid, $slot ];
	}

	/**
	 * Resolve a subgraph input socket to whatever the parent bound to it.
	 *
	 * @param string $ctx_key Subgraph context key.
	 * @param int    $slot    Input slot index.
	 * @param int    $depth   Recursion depth.
	 * @return array|null
	 */
	private static function resolve_boundary_input( string $ctx_key, int $slot, int $depth ): ?array {
		$context  = self::$contexts[ $ctx_key ];
		$name     = (string) ( $context['def']['inputs'][ $slot ]['name'] ?? '' );
		$instance = $context['instance'];

		foreach ( (array) ( $instance['inputs'] ?? [] ) as $entry ) {
			if ( (string) ( $entry['name'] ?? '' ) !== $name ) {
				continue;
			}
			if ( isset( $entry['link'] ) && null !== $entry['link'] ) {
				return self::resolve_link( (string) $context['parent'], $entry['link'], $depth );
			}
			break;
		}

		$promoted = self::promoted_values( $context );
		if ( array_key_exists( $name, $promoted ) ) {
			return [ '__literal__', $promoted[ $name ] ];
		}

		// Nothing bound the socket, so the node inside the subgraph keeps the
		// widget value it was saved with.
		return null;
	}

	/**
	 * Resolve a subgraph output socket to the node inside that feeds it.
	 *
	 * @param string $ctx_key Subgraph context key.
	 * @param int    $slot    Output slot index.
	 * @param int    $depth   Recursion depth.
	 * @return array|null
	 */
	private static function resolve_subgraph_output( string $ctx_key, int $slot, int $depth ): ?array {
		$context = self::$contexts[ $ctx_key ] ?? null;
		if ( null === $context ) {
			return null;
		}

		$boundary = (string) ( $context['def']['outputNode']['id'] ?? '-20' );
		foreach ( $context['links'] as $id => $link ) {
			if ( $link['target_id'] === $boundary && $link['target_slot'] === $slot ) {
				return self::resolve_link( $ctx_key, $id, $depth );
			}
		}
		foreach ( (array) ( $context['def']['outputs'][ $slot ]['linkIds'] ?? [] ) as $id ) {
			$resolved = self::resolve_link( $ctx_key, $id, $depth );
			if ( null !== $resolved ) {
				return $resolved;
			}
		}

		return null;
	}

	/**
	 * Route around a reroute or bypassed node by finding the input carrying the
	 * same socket type as the requested output.
	 *
	 * @param string $ctx_key Context key.
	 * @param array  $node    Editor node.
	 * @param int    $slot    Output slot index.
	 * @param int    $depth   Recursion depth.
	 * @return array|null
	 */
	private static function resolve_passthrough( string $ctx_key, array $node, int $slot, int $depth ): ?array {
		$wanted = (string) ( $node['outputs'][ $slot ]['type'] ?? '' );

		foreach ( (array) ( $node['inputs'] ?? [] ) as $entry ) {
			$type = (string) ( $entry['type'] ?? '' );
			if ( '' !== $wanted && '*' !== $type && $type !== $wanted ) {
				continue;
			}
			if ( isset( $entry['link'] ) && null !== $entry['link'] ) {
				return self::resolve_link( $ctx_key, $entry['link'], $depth );
			}
		}

		return null;
	}

	/**
	 * Promoted widget values a subgraph instance overrides its interior with.
	 *
	 * @param array $context Subgraph context.
	 * @return array<string, mixed>
	 */
	private static function promoted_values( array $context ): array {
		$values = $context['instance']['widgets_values'] ?? [];
		if ( ! is_array( $values ) || empty( $values ) ) {
			return [];
		}
		if ( self::is_map( $values ) ) {
			return $values;
		}

		$promoted = [];
		foreach ( array_values( (array) ( $context['def']['inputs'] ?? [] ) ) as $index => $input ) {
			if ( ! array_key_exists( $index, $values ) ) {
				break;
			}
			$name = (string) ( $input['name'] ?? '' );
			if ( '' !== $name ) {
				$promoted[ $name ] = $values[ $index ];
			}
		}

		return $promoted;
	}

	/**
	 * Name a node's positional widget values by walking the input order that
	 * `/object_info` declares for its class.
	 *
	 * @param string $class Node class type.
	 * @param array  $node  Editor node.
	 * @return array<string, mixed>
	 */
	private static function widget_inputs( string $class, array $node ): array {
		$values = $node['widgets_values'] ?? [];
		if ( ! is_array( $values ) || empty( $values ) ) {
			return [];
		}

		$spec  = (array) ( self::$object_info[ $class ]['input'] ?? [] );
		$order = (array) ( self::$object_info[ $class ]['input_order'] ?? [] );
		$names = [];

		foreach ( [ 'required', 'optional' ] as $group ) {
			$group_spec  = (array) ( $spec[ $group ] ?? [] );
			$group_names = isset( $order[ $group ] ) && is_array( $order[ $group ] )
				? array_map( 'strval', $order[ $group ] )
				: array_map( 'strval', array_keys( $group_spec ) );

			foreach ( $group_names as $name ) {
				$definition = $group_spec[ $name ] ?? null;
				if ( ! is_array( $definition ) || ! self::is_widget_type( $definition[0] ?? null ) ) {
					continue;
				}

				$names[] = $name;

				// A seed widget is followed by an unnamed "control after
				// generate" value that has no place in the API prompt, unless
				// this ComfyUI already exposes it as a real input.
				$options = is_array( $definition[1] ?? null ) ? $definition[1] : [];
				if ( ! empty( $options['control_after_generate'] ) && ! in_array( 'control_after_generate', $group_names, true ) ) {
					$names[] = '';
				}
			}
		}

		if ( self::is_map( $values ) ) {
			return array_intersect_key( $values, array_flip( array_filter( $names ) ) );
		}

		$inputs = [];
		foreach ( array_values( $values ) as $index => $value ) {
			$name = $names[ $index ] ?? '';
			if ( '' !== $name ) {
				$inputs[ $name ] = $value;
			}
		}

		return $inputs;
	}

	/**
	 * Whether a socket type accepts a widget value.
	 *
	 * @param mixed $type Socket type from `/object_info`.
	 * @return bool
	 */
	private static function is_widget_type( $type ): bool {
		if ( is_array( $type ) ) {
			return true;
		}

		return is_string( $type ) && in_array( strtoupper( $type ), self::WIDGET_TYPES, true );
	}

	/**
	 * Node classes that exist only in the editor and never execute.
	 *
	 * @param string $class Node class type.
	 * @return bool
	 */
	private static function is_editor_only( string $class ): bool {
		if ( '' === $class || in_array( $class, self::PASSTHROUGH_TYPES, true ) ) {
			return true;
		}

		return (bool) preg_match( '/^(Note|MarkdownNote|Bookmark|Anything Everywhere.*|Fast Groups.*)$/', $class );
	}

	/**
	 * Drop input references to nodes that were removed during conversion.
	 *
	 * @param array $api     API-format workflow.
	 * @param array $is_link Map of node ID to the input names holding links.
	 * @return array
	 */
	private static function drop_dangling_links( array $api, array &$is_link ): array {
		foreach ( $is_link as $uid => $fields ) {
			foreach ( $fields as $index => $field ) {
				$reference = $api[ $uid ]['inputs'][ $field ] ?? null;
				if ( ! self::is_reference( $reference ) || ! isset( $api[ (string) $reference[0] ] ) ) {
					unset( $api[ $uid ]['inputs'][ $field ], $is_link[ $uid ][ $index ] );
				}
			}
		}

		return $api;
	}

	/**
	 * Keep only the nodes an output node depends on, so editor scratch space
	 * and disabled branches never reach the executor.
	 *
	 * @param array $api     API-format workflow.
	 * @param array $is_link Map of node ID to the input names holding links.
	 * @return array
	 */
	private static function prune_unreachable( array $api, array &$is_link ): array {
		$queue = [];
		foreach ( $api as $uid => $node ) {
			if ( ! empty( self::$object_info[ $node['class_type'] ]['output_node'] ) ) {
				$queue[] = (string) $uid;
			}
		}
		if ( empty( $queue ) ) {
			return $api;
		}

		$reached = [];
		while ( ! empty( $queue ) ) {
			$uid = array_pop( $queue );
			if ( isset( $reached[ $uid ] ) || ! isset( $api[ $uid ] ) ) {
				continue;
			}
			$reached[ $uid ] = true;

			foreach ( (array) ( $is_link[ $uid ] ?? [] ) as $field ) {
				$reference = $api[ $uid ]['inputs'][ $field ] ?? null;
				if ( self::is_reference( $reference ) ) {
					$queue[] = (string) $reference[0];
				}
			}
		}

		foreach ( array_keys( $api ) as $uid ) {
			if ( ! isset( $reached[ (string) $uid ] ) ) {
				unset( $api[ $uid ], $is_link[ $uid ] );
			}
		}

		return $api;
	}

	/**
	 * Replace namespaced subgraph IDs with sequential numeric ones.
	 *
	 * @param array $api     API-format workflow.
	 * @param array $is_link Map of node ID to the input names holding links.
	 * @return array
	 */
	private static function renumber( array $api, array $is_link ): array {
		$map    = [];
		$number = 1;
		foreach ( array_keys( $api ) as $uid ) {
			$map[ (string) $uid ] = (string) $number++;
		}

		$renumbered = [];
		foreach ( $api as $uid => $node ) {
			foreach ( (array) ( $is_link[ $uid ] ?? [] ) as $field ) {
				$reference = $node['inputs'][ $field ] ?? null;
				if ( self::is_reference( $reference ) && isset( $map[ (string) $reference[0] ] ) ) {
					$node['inputs'][ $field ] = [ $map[ (string) $reference[0] ], (int) $reference[1] ];
				}
			}
			$renumbered[ $map[ (string) $uid ] ] = $node;
		}

		return $renumbered;
	}

	/**
	 * Walk a conditioning chain back to the node holding its prompt text.
	 *
	 * @param array $api  API-format workflow.
	 * @param string $uid Node ID to inspect.
	 * @param array $seen Visited node IDs.
	 * @return string|null
	 */
	private static function find_text_input( array $api, string $uid, array $seen ): ?string {
		if ( isset( $seen[ $uid ] ) || ! isset( $api[ $uid ] ) ) {
			return null;
		}
		$seen[ $uid ] = true;

		if ( ! empty( self::prompt_fields( $api[ $uid ] ) ) ) {
			return $uid;
		}

		foreach ( (array) ( $api[ $uid ]['inputs'] ?? [] ) as $value ) {
			if ( ! self::is_reference( $value ) ) {
				continue;
			}
			$found = self::find_text_input( $api, (string) $value[0], $seen );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Whether a value is a `[node_id, slot]` link reference.
	 *
	 * @param mixed $value Input value.
	 * @return bool
	 */
	private static function is_reference( $value ): bool {
		return is_array( $value )
			&& 2 === count( $value )
			&& isset( $value[0], $value[1] )
			&& is_scalar( $value[0] )
			&& is_int( $value[1] );
	}

	/**
	 * Whether an array is keyed by name rather than position.
	 *
	 * @param array $value Array to test.
	 * @return bool
	 */
	private static function is_map( array $value ): bool {
		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}
}
