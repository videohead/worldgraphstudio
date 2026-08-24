<?php
/**
 * Discovery of the workflow templates ComfyUI publishes.
 *
 * ComfyUI ships several hundred reference workflows covering every model family
 * it supports, and keeps them in a public index rather than inside the plugin.
 * Reading that index is what lets World Graph Studio offer a modern
 * text-to-image graph instead of the minimal Stable Diffusion 1.5 fallback it
 * provisions when nothing else is known to work.
 *
 * The index is metadata only. It names models but not model files, so it can
 * rank on download weight and popularity but cannot honestly say whether a
 * given ComfyUI can run something. That answer only exists in the workflow
 * itself, so readiness is resolved on demand by fetching the graph and checking
 * the files its loaders name against what the instance reports installed.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Published ComfyUI workflow template discovery, ranking, and conversion.
 */
class Comfy_Template_Registry {

	/**
	 * Where ComfyUI publishes its template index and workflow bodies.
	 */
	const DEFAULT_BASE = 'https://cloud.comfy.org';

	/**
	 * Prefix distinguishing registry entries from MCP and built-in ones.
	 */
	const ID_PREFIX = 'registry:';

	/**
	 * Transient holding the normalized index.
	 */
	const INDEX_TRANSIENT = 'worldgraph_comfy_registry_index';

	/**
	 * Transient prefix for a single cached workflow body.
	 */
	const GRAPH_TRANSIENT = 'worldgraph_comfy_registry_graph_';

	/**
	 * Transient prefix for a cached readiness verdict.
	 */
	const READINESS_TRANSIENT = 'worldgraph_comfy_registry_ready_';

	/**
	 * How long the index stays cached, in seconds.
	 */
	const INDEX_TTL = DAY_IN_SECONDS;

	/**
	 * How long a workflow body stays cached, in seconds.
	 */
	const GRAPH_TTL = DAY_IN_SECONDS;

	/**
	 * How long a readiness verdict stays cached, in seconds.
	 */
	const READINESS_TTL = 900;

	/**
	 * Template tags mapped onto World Graph Studio modalities. Ordered, because
	 * a template tagged both "Image Edit" and "Image to Video" is a video
	 * workflow that happens to accept an edited frame.
	 */
	const TAG_MODALITIES = [
		'Text to Video'      => Generation_Modality::TEXT_TO_VIDEO,
		'Image to Video'     => Generation_Modality::TEXT_IMAGE_TO_VIDEO,
		'Reference to Video' => Generation_Modality::TEXT_IMAGE_TO_VIDEO,
		'Video Edit'         => Generation_Modality::VIDEO_TO_VIDEO,
		'Text to Music'      => Generation_Modality::TEXT_TO_MUSIC,
		'Text to Audio'      => Generation_Modality::TEXT_TO_MUSIC,
		'Text to Image'      => Generation_Modality::TEXT_TO_IMAGE,
		'Image Edit'         => Generation_Modality::IMAGE_TO_IMAGE,
		'Inpainting'         => Generation_Modality::IMAGE_TO_IMAGE,
		'Outpainting'        => Generation_Modality::IMAGE_TO_IMAGE,
		'Image Upscale'      => Generation_Modality::IMAGE_TO_IMAGE,
	];

	/**
	 * The registry base URL.
	 *
	 * @return string
	 */
	public static function base_url(): string {
		/**
		 * Filter the ComfyUI template registry base URL.
		 *
		 * @param string $base Registry base URL.
		 */
		$base = (string) apply_filters( 'worldgraph_comfy_registry_base', self::DEFAULT_BASE );

		return untrailingslashit( esc_url_raw( $base ) );
	}

	/**
	 * The normalized template index.
	 *
	 * @param bool $refresh Re-fetch instead of reading the cached index.
	 * @return array<int, array>|WP_Error
	 */
	public static function index( bool $refresh = false ) {
		if ( ! $refresh ) {
			$cached = get_transient( self::INDEX_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$groups = self::fetch_json( self::base_url() . '/templates/index.json' );
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}

		$entries = [];
		foreach ( (array) $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( (array) ( $group['templates'] ?? [] ) as $template ) {
				if ( ! is_array( $template ) || empty( $template['name'] ) ) {
					continue;
				}
				$entry = self::normalize( $template, $group );
				if ( ! isset( $entries[ $entry['id'] ] ) ) {
					$entries[ $entry['id'] ] = $entry;
				}
			}
		}

		$entries = array_values( $entries );
		set_transient( self::INDEX_TRANSIENT, $entries, self::INDEX_TTL );

		return $entries;
	}

	/**
	 * Filter the index.
	 *
	 * @param array $args {
	 *     @type string $modality   Restrict to one modality slug.
	 *     @type string $search     Match against title, description, and models.
	 *     @type bool   $local_only Exclude templates that call a paid provider API.
	 *     @type int    $limit      Maximum entries to return.
	 * }
	 * @return array<int, array>|WP_Error
	 */
	public static function search( array $args = [] ) {
		$entries = self::index();
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$modality   = (string) ( $args['modality'] ?? '' );
		$search     = strtolower( trim( (string) ( $args['search'] ?? '' ) ) );
		$local_only = ! empty( $args['local_only'] );

		$matched = array_values( array_filter( $entries, static function ( array $entry ) use ( $modality, $search, $local_only ): bool {
			if ( '' !== $modality && $entry['modality'] !== $modality ) {
				return false;
			}
			if ( $local_only && $entry['api_only'] ) {
				return false;
			}
			if ( '' === $search ) {
				return true;
			}

			$haystack = strtolower( $entry['name'] . ' ' . $entry['description'] . ' ' . implode( ' ', $entry['models'] ) . ' ' . implode( ' ', $entry['tags'] ) );

			return false !== strpos( $haystack, $search );
		} ) );

		usort( $matched, [ __CLASS__, 'compare_popularity' ] );

		$limit = (int) ( $args['limit'] ?? 0 );

		return $limit > 0 ? array_slice( $matched, 0, $limit ) : $matched;
	}

	/**
	 * Rank candidates by what the instance can already run.
	 *
	 * Popularity is a guess about taste; installed models are a fact about this
	 * machine. Anything already runnable therefore outranks anything that would
	 * cost a download, however celebrated.
	 *
	 * @param array  $args     Arguments accepted by {@see self::search()}.
	 * @param string $endpoint ComfyUI base URL.
	 * @param int    $probe    How many popular candidates to resolve readiness for.
	 * @return array<int, array>|WP_Error
	 */
	public static function ranked( array $args, string $endpoint, int $probe = 12 ) {
		$candidates = self::search( $args );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		// Probing costs a request per template, so spend that budget on the
		// families whose files already appear on disk rather than on whatever
		// happens to be popular this month.
		$object_info = Comfy_Manifest::object_info( $endpoint );
		if ( ! is_wp_error( $object_info ) ) {
			$installed = strtolower( implode( ' ', array_merge( [], ...array_values( self::installed_files( $object_info ) ) ) ) );
			usort( $candidates, static function ( array $a, array $b ) use ( $installed ): int {
				$hint = self::install_hint( $b, $installed ) <=> self::install_hint( $a, $installed );

				return 0 !== $hint ? $hint : self::compare_popularity( $a, $b );
			} );
		}

		foreach ( array_slice( array_keys( $candidates ), 0, max( 0, $probe ) ) as $index ) {
			$readiness = self::readiness( $candidates[ $index ]['id'], $endpoint );
			if ( ! is_wp_error( $readiness ) ) {
				$candidates[ $index ] = array_merge( $candidates[ $index ], $readiness );
			}
		}

		usort( $candidates, static function ( array $a, array $b ): int {
			$ready = ( $b['ready'] ?? false ) <=> ( $a['ready'] ?? false );
			if ( 0 !== $ready ) {
				return $ready;
			}

			$missing = ( $a['missing_bytes'] ?? PHP_INT_MAX ) <=> ( $b['missing_bytes'] ?? PHP_INT_MAX );

			return 0 !== $missing ? $missing : self::compare_popularity( $a, $b );
		} );

		return $candidates;
	}

	/**
	 * How strongly a template's advertised models resemble files already on the
	 * instance. A cheap signal used only to order the readiness probes.
	 *
	 * @param array  $entry     Registry entry.
	 * @param string $installed Lower-cased list of installed model filenames.
	 * @return int
	 */
	private static function install_hint( array $entry, string $installed ): int {
		if ( '' === $installed ) {
			return 0;
		}

		$score = 0;
		foreach ( (array) ( $entry['models'] ?? [] ) as $model ) {
			foreach ( preg_split( '/[^a-z0-9]+/', strtolower( (string) $model ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
				if ( strlen( $token ) >= 4 && false !== strpos( $installed, $token ) ) {
					++$score;
					break;
				}
			}
		}

		return $score;
	}

	/**
	 * One entry from the index.
	 *
	 * @param string $id Entry ID, with or without the registry prefix.
	 * @return array|null
	 */
	public static function find( string $id ): ?array {
		$id      = self::qualify( $id );
		$entries = self::index();
		if ( is_wp_error( $entries ) ) {
			return null;
		}

		foreach ( $entries as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Fetch a published workflow body in ComfyUI's editor format.
	 *
	 * @param string $id Entry ID.
	 * @return array|WP_Error
	 */
	public static function graph( string $id ) {
		$name = self::template_name( $id );
		if ( '' === $name ) {
			return new WP_Error( 'worldgraph_comfy_registry_bad_id', __( 'That is not a published ComfyUI template reference.', 'worldgraph' ) );
		}

		$key    = self::GRAPH_TRANSIENT . md5( $name );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$graph = self::fetch_json( self::base_url() . '/templates/' . rawurlencode( $name ) . '.json' );
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}

		set_transient( $key, $graph, self::GRAPH_TTL );

		return $graph;
	}

	/**
	 * Convert a published template into a runnable API workflow for one
	 * ComfyUI instance, with per-job prompt placeholders substituted in.
	 *
	 * @param string $id       Entry ID.
	 * @param string $endpoint ComfyUI base URL.
	 * @return array|WP_Error
	 */
	public static function workflow( string $id, string $endpoint ) {
		$graph = self::graph( $id );
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}

		$object_info = Comfy_Manifest::object_info( $endpoint );
		if ( is_wp_error( $object_info ) ) {
			return $object_info;
		}

		$api = Comfy_Graph::to_api( $graph, $object_info );
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$api      = Comfy_Graph::apply_prompt_placeholders( $api );
		$entry    = self::find( $id );
		$modality = is_array( $entry ) ? (string) ( $entry['modality'] ?? '' ) : '';
		if ( '' !== $modality ) {
			$media_slots   = Generation_Modality::media_inputs( $modality );
			$required_slots = array_values( array_intersect( Generation_Modality::required_inputs( $modality ), $media_slots ) );
			$api = Comfy_Graph::apply_media_placeholders( $api, $media_slots, $required_slots );
			if ( is_wp_error( $api ) ) {
				return $api;
			}
		}

		return Comfy_Graph::randomize_seeds( $api );
	}

	/**
	 * Whether an instance already has every model a published template loads.
	 *
	 * @param string $id       Entry ID.
	 * @param string $endpoint ComfyUI base URL.
	 * @return array|WP_Error
	 */
	public static function readiness( string $id, string $endpoint ) {
		$endpoint = untrailingslashit( esc_url_raw( $endpoint ) );
		$key      = self::READINESS_TRANSIENT . md5( $id . '|' . $endpoint );
		$cached   = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$graph = self::graph( $id );
		if ( is_wp_error( $graph ) ) {
			return $graph;
		}
		$object_info = Comfy_Manifest::object_info( $endpoint );
		if ( is_wp_error( $object_info ) ) {
			return $object_info;
		}

		$installed = self::installed_files( $object_info );
		$urls      = self::model_urls( $graph );
		$required  = [];
		$missing   = [];

		foreach ( self::model_files( $graph, $object_info ) as $file ) {
			$present = in_array( $file['filename'], $installed[ $file['folder'] ] ?? [], true );
			$file['installed'] = $present;
			$file['url']       = (string) ( $urls[ $file['filename'] ] ?? '' );
			$required[]        = $file;

			if ( ! $present ) {
				$missing[] = $file;
			}
		}

		$entry     = self::find( $id );
		$total     = (int) ( $entry['size'] ?? 0 );
		$verdict   = [
			'models_required' => $required,
			'missing'         => $missing,
			'ready'           => empty( $missing ),
			'missing_nodes'   => self::missing_nodes( $graph, $object_info ),
			// Nothing publishes per-file sizes, so an unmet requirement is
			// costed at the template's own total rather than pretending to a
			// precision the index does not have.
			'missing_bytes'   => empty( $missing ) ? 0 : $total,
		];
		$verdict['ready'] = $verdict['ready'] && empty( $verdict['missing_nodes'] );

		set_transient( $key, $verdict, self::READINESS_TTL );

		return $verdict;
	}

	/**
	 * Present a registry entry in the shape the Connection catalog stores.
	 *
	 * @param array $entry Registry entry.
	 * @return array
	 */
	public static function catalog_entry( array $entry ): array {
		return [
			'id'             => (string) $entry['id'],
			'name'           => (string) $entry['name'],
			'source'         => 'registry',
			'model_type'     => implode( ', ', (array) $entry['models'] ),
			'task_type'      => (string) $entry['task_type'],
			'modality'       => (string) $entry['modality'],
			'model_family'   => (string) $entry['model_family'],
			'required_nodes' => [],
			'models'         => [],
			'model_urls'     => [],
			'parameters'     => [],
			'workflow_hash'  => '',
			'description'    => (string) $entry['description'],
			'thumbnail'      => (string) $entry['thumbnail'],
			'tags'           => (array) $entry['tags'],
			'size'           => (int) $entry['size'],
			'usage'          => (int) $entry['usage'],
			'api_only'       => (bool) $entry['api_only'],
		];
	}

	/**
	 * Prefix a bare template name with the registry marker.
	 *
	 * @param string $id Entry ID or template name.
	 * @return string
	 */
	public static function qualify( string $id ): string {
		$id = trim( $id );

		return 0 === strpos( $id, self::ID_PREFIX ) ? $id : self::ID_PREFIX . $id;
	}

	/**
	 * The published template name behind an entry ID.
	 *
	 * @param string $id Entry ID.
	 * @return string
	 */
	public static function template_name( string $id ): string {
		$name = 0 === strpos( $id, self::ID_PREFIX ) ? substr( $id, strlen( self::ID_PREFIX ) ) : $id;

		return preg_match( '/^[A-Za-z0-9._-]+$/', (string) $name ) ? (string) $name : '';
	}

	/**
	 * Whether an ID refers to a published registry template.
	 *
	 * @param string $id Entry ID.
	 * @return bool
	 */
	public static function owns( string $id ): bool {
		return 0 === strpos( $id, self::ID_PREFIX );
	}

	/**
	 * Drop every cached index, workflow, and readiness verdict.
	 */
	public static function flush(): void {
		global $wpdb;

		delete_transient( self::INDEX_TRANSIENT );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . self::GRAPH_TRANSIENT ) . '%',
			$wpdb->esc_like( '_transient_' . self::READINESS_TRANSIENT ) . '%'
		) );
	}

	/**
	 * Normalize one published template descriptor.
	 *
	 * @param array $template Raw descriptor.
	 * @param array $group    Owning index group.
	 * @return array
	 */
	private static function normalize( array $template, array $group ): array {
		$name     = (string) $template['name'];
		$tags     = array_values( array_filter( array_map( 'strval', (array) ( $template['tags'] ?? [] ) ) ) );
		$modality = self::modality_for( $tags, (string) ( $group['type'] ?? '' ) );
		$models   = array_values( array_filter( array_map( 'strval', (array) ( $template['models'] ?? [] ) ) ) );

		return [
			'id'           => self::ID_PREFIX . $name,
			'template'     => $name,
			'name'         => (string) ( $template['title'] ?? $name ),
			'description'  => wp_strip_all_tags( (string) ( $template['description'] ?? '' ) ),
			'category'     => (string) ( $group['title'] ?? '' ),
			'tags'         => $tags,
			'models'       => $models,
			'modality'     => $modality,
			'task_type'    => '' !== $modality ? (string) Generation_Modality::get( $modality )['task_type'] : '',
			'model_family' => Model_Family::sanitize( self::family_for( $models ) ),
			'thumbnail'    => self::thumbnail_url( $name, (string) ( $template['mediaSubtype'] ?? 'webp' ) ),
			'size'         => (int) ( $template['size'] ?? 0 ),
			'usage'        => (int) ( $template['usage'] ?? 0 ),
			'rank'         => (int) ( $template['searchRank'] ?? 0 ),
			'date'         => (string) ( $template['date'] ?? '' ),
			'open_source'  => ! empty( $template['openSource'] ),
			// An "API" template calls a hosted provider through ComfyUI and
			// bills per generation, so it is never a local-first default.
			'api_only'     => in_array( 'API', $tags, true ) || 0 === strpos( $name, 'api_' ),
		];
	}

	/**
	 * Map a template's tags onto a modality.
	 *
	 * @param array  $tags Template tags.
	 * @param string $type Index group media type.
	 * @return string
	 */
	private static function modality_for( array $tags, string $type ): string {
		foreach ( self::TAG_MODALITIES as $tag => $modality ) {
			if ( in_array( $tag, $tags, true ) ) {
				return $modality;
			}
		}

		return 'image' === $type && in_array( 'Image', $tags, true ) ? Generation_Modality::TEXT_TO_IMAGE : '';
	}

	/**
	 * Guess a model family from the model names the index advertises.
	 *
	 * @param array $models Model names.
	 * @return string
	 */
	private static function family_for( array $models ): string {
		foreach ( $models as $model ) {
			$family = Model_Family::sanitize( (string) $model );
			if ( '' !== $family ) {
				return $family;
			}
		}

		return '';
	}

	/**
	 * Preview image published alongside a template.
	 *
	 * @param string $name    Template name.
	 * @param string $subtype Media subtype.
	 * @return string
	 */
	private static function thumbnail_url( string $name, string $subtype ): string {
		$subtype = preg_match( '/^[a-z0-9]+$/i', $subtype ) ? $subtype : 'webp';

		return self::base_url() . '/templates/' . rawurlencode( $name ) . '-1.' . $subtype;
	}

	/**
	 * Popularity ordering: curated rank first, then real usage.
	 *
	 * @param array $a First entry.
	 * @param array $b Second entry.
	 * @return int
	 */
	private static function compare_popularity( array $a, array $b ): int {
		$rank = ( (int) ( $b['rank'] ?? 0 ) ) <=> ( (int) ( $a['rank'] ?? 0 ) );

		return 0 !== $rank ? $rank : ( (int) ( $b['usage'] ?? 0 ) ) <=> ( (int) ( $a['usage'] ?? 0 ) );
	}

	/**
	 * The model files an editor graph's loaders name, across the root graph and
	 * every subgraph definition.
	 *
	 * @param array $graph       Editor graph.
	 * @param array $object_info Decoded `/object_info`.
	 * @return array<int, array{filename: string, folder: string, node_class: string, field: string}>
	 */
	private static function model_files( array $graph, array $object_info ): array {
		$files = [];
		foreach ( self::all_nodes( $graph ) as $node ) {
			$class = (string) ( $node['type'] ?? '' );
			if ( ! isset( $object_info[ $class ] ) ) {
				continue;
			}

			foreach ( self::named_widgets( $class, $node, $object_info ) as $field => $value ) {
				$folder = Comfy_Manifest::model_folder( $class, (string) $field );
				if ( '' === $folder || ! is_string( $value ) || '' === trim( $value ) ) {
					continue;
				}

				$files[ $field . '|' . $value ] = [
					'filename'   => trim( $value ),
					'folder'     => $folder,
					'node_class' => $class,
					'field'      => (string) $field,
				];
			}
		}

		return array_values( $files );
	}

	/**
	 * Node classes an editor graph uses that the instance does not have.
	 *
	 * @param array $graph       Editor graph.
	 * @param array $object_info Decoded `/object_info`.
	 * @return array<int, string>
	 */
	private static function missing_nodes( array $graph, array $object_info ): array {
		$subgraphs = [];
		foreach ( (array) ( $graph['definitions']['subgraphs'] ?? [] ) as $definition ) {
			if ( is_array( $definition ) && ! empty( $definition['id'] ) ) {
				$subgraphs[ (string) $definition['id'] ] = true;
			}
		}

		$missing = [];
		foreach ( self::all_nodes( $graph ) as $node ) {
			$class = (string) ( $node['type'] ?? '' );
			if ( '' === $class || isset( $object_info[ $class ] ) || isset( $subgraphs[ $class ] ) ) {
				continue;
			}
			if ( in_array( $class, [ 'Note', 'MarkdownNote', 'Reroute', 'PrimitiveNode', 'Bookmark' ], true ) ) {
				continue;
			}

			$missing[ $class ] = true;
		}

		return array_keys( $missing );
	}

	/**
	 * Every node in an editor graph, including those inside subgraphs.
	 *
	 * @param array $graph Editor graph.
	 * @return array<int, array>
	 */
	private static function all_nodes( array $graph ): array {
		$bodies = [ $graph ];
		foreach ( (array) ( $graph['definitions']['subgraphs'] ?? [] ) as $definition ) {
			if ( is_array( $definition ) ) {
				$bodies[] = $definition;
			}
		}

		$nodes = [];
		foreach ( $bodies as $body ) {
			foreach ( (array) ( $body['nodes'] ?? [] ) as $node ) {
				if ( is_array( $node ) ) {
					$nodes[] = $node;
				}
			}
		}

		return $nodes;
	}

	/**
	 * Name a node's positional widget values using the instance's catalog.
	 *
	 * @param string $class       Node class type.
	 * @param array  $node        Editor node.
	 * @param array  $object_info Decoded `/object_info`.
	 * @return array<string, mixed>
	 */
	private static function named_widgets( string $class, array $node, array $object_info ): array {
		$values = $node['widgets_values'] ?? [];
		if ( ! is_array( $values ) || empty( $values ) ) {
			return [];
		}
		if ( array_keys( $values ) !== range( 0, count( $values ) - 1 ) ) {
			return $values;
		}

		$spec  = (array) ( $object_info[ $class ]['input'] ?? [] );
		$order = (array) ( $object_info[ $class ]['input_order'] ?? [] );
		$names = [];

		foreach ( [ 'required', 'optional' ] as $group ) {
			$group_spec  = (array) ( $spec[ $group ] ?? [] );
			$group_names = isset( $order[ $group ] ) && is_array( $order[ $group ] )
				? array_map( 'strval', $order[ $group ] )
				: array_map( 'strval', array_keys( $group_spec ) );

			foreach ( $group_names as $name ) {
				$definition = $group_spec[ $name ] ?? null;
				if ( ! is_array( $definition ) ) {
					continue;
				}
				$type = $definition[0] ?? null;
				if ( ! is_array( $type ) && ( ! is_string( $type ) || ! in_array( strtoupper( $type ), Comfy_Graph::WIDGET_TYPES, true ) ) ) {
					continue;
				}

				$names[] = $name;

				$options = is_array( $definition[1] ?? null ) ? $definition[1] : [];
				if ( ! empty( $options['control_after_generate'] ) && ! in_array( 'control_after_generate', $group_names, true ) ) {
					$names[] = '';
				}
			}
		}

		$named = [];
		foreach ( array_values( $values ) as $index => $value ) {
			$name = $names[ $index ] ?? '';
			if ( '' !== $name ) {
				$named[ $name ] = $value;
			}
		}

		return $named;
	}

	/**
	 * Download URLs a template documents in its own notes, keyed by filename.
	 *
	 * Published workflows carry their model links in a markdown note rather than
	 * in structured metadata, which is the only place the download source for a
	 * required file is ever stated.
	 *
	 * @param array $graph Editor graph.
	 * @return array<string, string>
	 */
	private static function model_urls( array $graph ): array {
		$urls = [];
		foreach ( self::all_nodes( $graph ) as $node ) {
			if ( ! in_array( (string) ( $node['type'] ?? '' ), [ 'MarkdownNote', 'Note' ], true ) ) {
				continue;
			}

			$text = '';
			foreach ( (array) ( $node['widgets_values'] ?? [] ) as $value ) {
				if ( is_string( $value ) ) {
					$text .= "\n" . $value;
				}
			}

			if ( preg_match_all( '/\[([^\]\s]+\.(?:safetensors|ckpt|pth|sft|gguf|pt|bin))\]\((https:\/\/[^)\s]+)\)/i', $text, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$url = esc_url_raw( $match[2] );
					if ( '' !== $url ) {
						$urls[ $match[1] ] = $url;
					}
				}
			}
		}

		return $urls;
	}

	/**
	 * The model filenames an instance reports installed, grouped by folder.
	 *
	 * @param array $object_info Decoded `/object_info`.
	 * @return array<string, array<int, string>>
	 */
	private static function installed_files( array $object_info ): array {
		$installed = [];
		foreach ( $object_info as $class => $spec ) {
			foreach ( [ 'required', 'optional' ] as $group ) {
				foreach ( (array) ( $spec['input'][ $group ] ?? [] ) as $field => $definition ) {
					$folder = Comfy_Manifest::model_folder( (string) $class, (string) $field );
					if ( '' === $folder || ! is_array( $definition ) || ! is_array( $definition[0] ?? null ) ) {
						continue;
					}

					foreach ( $definition[0] as $option ) {
						if ( is_string( $option ) ) {
							$installed[ $folder ][ $option ] = true;
						}
					}
				}
			}
		}

		return array_map( 'array_keys', $installed );
	}

	/**
	 * Fetch and decode a JSON document from the registry.
	 *
	 * @param string $url Absolute URL.
	 * @return array|WP_Error
	 */
	private static function fetch_json( string $url ) {
		$response = wp_safe_remote_get( $url, [
			'timeout' => 30,
			'headers' => [ 'Accept' => 'application/json' ],
		] );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'worldgraph_comfy_registry_unreachable',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Unable to reach the ComfyUI template registry: %s', 'worldgraph' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'worldgraph_comfy_registry_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The ComfyUI template registry returned HTTP %d.', 'worldgraph' ),
					$code
				)
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'worldgraph_comfy_registry_invalid', __( 'The ComfyUI template registry returned an unreadable response.', 'worldgraph' ) );
		}

		return $decoded;
	}
}
