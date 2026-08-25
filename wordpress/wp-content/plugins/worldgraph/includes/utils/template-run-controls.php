<?php
/**
 * Safe, provider-neutral runtime controls for generation Templates.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovers, validates, and applies bounded scalar Template run controls.
 *
 * Public descriptions deliberately contain no workflow node IDs or input
 * paths. Bindings are rediscovered from the server-owned Template whenever a
 * workflow is mutated.
 */
class Template_Run_Controls {

	/** Description wire-format version. */
	const VERSION = 1;

	/** Maximum number of controls a Template may advertise. */
	const MAX_FIELDS = 32;

	/** Maximum encoded configuration size considered for discovery. */
	const MAX_CONFIGURATION_BYTES = 131072;

	/** Maximum API-format workflow nodes considered for discovery/application. */
	const MAX_WORKFLOW_NODES = 512;

	/** Maximum inputs considered on one workflow node. */
	const MAX_NODE_INPUTS = 128;

	/** Maximum generic text override length. */
	const MAX_TEXT_LENGTH = 1000;

	/** Maximum plain-language help copied into a public field description. */
	const MAX_FIELD_DESCRIPTION_LENGTH = 480;

	/** Maximum prompt-conditioning override length. */
	const MAX_PROMPT_LENGTH = 4000;

	/** Maximum enum choices copied into a public description. */
	const MAX_ENUM_VALUES = 64;

	/** Maximum traversal depth through workflow references. */
	const MAX_LINK_DEPTH = 32;

	/**
	 * Describe one stored Template's safe runtime controls.
	 *
	 * @param int $template_id Template post ID.
	 * @return array{version:int,fingerprint:string,fields:array<int,array<string,mixed>>}
	 */
	public static function describe( int $template_id ): array {
		$configuration = self::decode_object( self::template_value( $template_id, 'configuration_json' ) );
		$workflow      = self::decode_object( self::template_value( $template_id, 'workflow_json' ) );
		$defaults      = self::decode_object( self::template_value( $template_id, 'default_values' ) );

		return self::describe_configuration(
			$configuration,
			[
				'workflow'        => $workflow,
				'default_values'  => $defaults,
				'provider_type'   => (string) self::template_value( $template_id, 'provider_type' ),
				'modality'        => (string) self::template_value( $template_id, 'modality' ),
				'output_type'     => (string) self::template_value( $template_id, 'generation_structure' ),
			]
		);
	}

	/**
	 * Validate submitted values for one stored Template.
	 *
	 * @param int   $template_id Template post ID.
	 * @param array $submitted   Submitted key/value overrides.
	 * @return array<string,scalar>|WP_Error
	 */
	public static function validate( int $template_id, array $submitted ) {
		return self::validate_description( self::describe( $template_id ), $submitted );
	}

	/**
	 * Return explicit Template execution defaults keyed by control name.
	 *
	 * Values inferred from a stored workflow are display defaults only: that
	 * workflow already owns them and omission must not rewrite it. Explicit
	 * configuration/default_values and built-in workflow defaults remain runner
	 * inputs. Seed is always absent so omission retains random-seed behavior.
	 *
	 * @param int $template_id Template post ID.
	 * @return array<string,scalar>
	 */
	public static function defaults( int $template_id ): array {
		$description   = self::describe( $template_id );
		$configuration = self::decode_object( self::template_value( $template_id, 'configuration_json' ) );
		$workflow      = self::decode_object( self::template_value( $template_id, 'workflow_json' ) );
		$defaults      = self::decode_object( self::template_value( $template_id, 'default_values' ) );

		if ( empty( $workflow ) ) {
			return self::description_defaults( $description );
		}

		$configured = self::description_defaults(
			self::describe_configuration( $configuration, [ 'default_values' => $defaults ] )
		);
		$allowed = [];
		foreach ( (array) ( $description['fields'] ?? [] ) as $field ) {
			if ( is_array( $field ) && isset( $field['key'] ) ) {
				$key             = (string) $field['key'];
				$allowed[ $key ] = true;
				// Preserve the inferred negative-conditioning baseline for legacy
				// placeholder workflows and runners that accept scalar defaults,
				// without replaying sampling/output literals the graph already owns.
				if ( 'negative_prompt' === self::semantic_key( $key ) && array_key_exists( 'default', $field ) && is_scalar( $field['default'] ) && ! array_key_exists( $key, $configured ) ) {
					$configured[ $key ] = $field['default'];
				}
			}
		}

		return array_intersect_key( $configured, $allowed );
	}

	/**
	 * Apply validated values to a server-owned API-format workflow.
	 *
	 * The unchanged workflow is returned when validation fails. Call validate()
	 * first when the caller needs to return the detailed WP_Error to a client.
	 *
	 * @param int   $template_id Template post ID.
	 * @param array $workflow    API-format workflow.
	 * @param array $values      Submitted runtime values.
	 * @return array
	 */
	public static function apply_to_workflow( int $template_id, array $workflow, array $values ): array {
		$description = self::describe( $template_id );

		return self::apply_description_to_workflow( $description, $workflow, $values );
	}

	/**
	 * Project a merged runner payload through a pure description and apply it.
	 *
	 * @param array $description Trusted server-derived control description.
	 * @param array $workflow    API-format workflow.
	 * @param array $values      Merged runtime/provider values.
	 * @return array
	 */
	public static function apply_description_to_workflow( array $description, array $workflow, array $values ): array {
		$allowed = [];
		foreach ( (array) ( $description['fields'] ?? [] ) as $field ) {
			if ( is_array( $field ) && isset( $field['key'] ) ) {
				$allowed[ (string) $field['key'] ] = true;
			}
		}
		// Runners pass a merged provider payload here. Public request validation
		// rejects unknown submitted controls; this boundary projects that larger
		// runtime map down to the Template-declared control contract.
		$projected = array_intersect_key( $values, $allowed );
		$validated = self::validate_description( $description, $projected );
		if ( self::is_error( $validated ) ) {
			return $workflow;
		}

		return self::apply_values_to_workflow( $workflow, $validated );
	}

	/**
	 * Build a safe description from decoded Template configuration.
	 *
	 * The optional context accepts `workflow`, `default_values`, `modality`,
	 * `output_type`, and `provider_type`. It exists both for unit testing and so
	 * callers with an already-loaded Template do not need a second database read.
	 *
	 * @param array $configuration Decoded configuration_json.
	 * @param array $context       Server-owned Template context.
	 * @return array{version:int,fingerprint:string,fields:array<int,array<string,mixed>>}
	 */
	public static function describe_configuration( array $configuration, array $context = [] ): array {
		if ( ! self::within_configuration_limit( $configuration ) ) {
			return self::make_description( [] );
		}

		$workflow = self::context_workflow( $context );
		$defaults = self::configuration_defaults( $configuration, $context );
		$fields   = [];

		foreach ( self::provider_properties( $configuration ) as $key => $property ) {
			$default = array_key_exists( (string) $key, $defaults ) ? $defaults[ (string) $key ] : null;
			$has_default = array_key_exists( (string) $key, $defaults );
			$field = self::normalize_field(
				(string) $key,
				is_array( $property ) ? $property : [],
				$default,
				$has_default,
				true
			);
			self::store_field( $fields, $field, false );
		}

		// A scalar provider default remains useful when a provider advertises an
		// incomplete schema. Denylisted names and non-scalars are still omitted.
		foreach ( $defaults as $key => $default ) {
			if ( isset( $fields[ (string) $key ] ) || ! is_scalar( $default ) ) {
				continue;
			}
			$field = self::normalize_field( (string) $key, [], $default, true, true );
			self::store_field( $fields, $field, false );
		}

		$workflow_fields = self::workflow_fields( $workflow );
		foreach ( $workflow_fields as $field ) {
			self::store_semantic_field( $fields, $field );
		}

		if ( empty( $workflow ) && self::is_comfy_context( $context ) ) {
			foreach ( self::comfy_fields( $context ) as $field ) {
				self::store_semantic_field( $fields, $field );
			}
		}

		list( $explicit, $disabled ) = self::explicit_controls( $configuration, $defaults );
		foreach ( $disabled as $key ) {
			unset( $fields[ $key ] );
		}
		foreach ( $explicit as $field ) {
			self::store_field( $fields, $field, true );
		}

		// Split text conditioning is only meaningful when the matching encoder
		// socket exists. A schema alone must not invent a workflow binding.
		foreach ( $fields as $key => $field ) {
			$semantic = self::semantic_key( (string) $key );
			if ( self::is_dual_text_semantic( $semantic ) && ! self::workflow_has_dual_input( $workflow, $semantic ) ) {
				unset( $fields[ $key ] );
			}
		}

		// A pasted Comfy workflow is the executable contract. Configuration or
		// schema fields without a proven mutable target would render as controls
		// that silently do nothing, so keep only discovered semantics.
		if ( ! empty( $workflow ) && self::is_comfy_context( $context ) ) {
			$supported = [];
			foreach ( $workflow_fields as $field ) {
				$semantic = self::semantic_key( (string) ( $field['key'] ?? '' ) );
				if ( null !== $semantic ) {
					$supported[ $semantic ] = true;
				}
			}
			foreach ( $fields as $key => $field ) {
				$semantic = self::semantic_key( (string) $key );
				if ( null === $semantic || ! isset( $supported[ $semantic ] ) ) {
					unset( $fields[ $key ] );
				}
			}
		}

		$fields = array_values( $fields );
		usort( $fields, [ __CLASS__, 'compare_fields' ] );
		$fields = array_slice( $fields, 0, self::MAX_FIELDS );

		return self::make_description( $fields );
	}

	/**
	 * Validate values against a previously generated description.
	 *
	 * @param array $description Description returned by describe_configuration().
	 * @param array $submitted   Submitted key/value overrides.
	 * @return array<string,scalar>|WP_Error
	 */
	public static function validate_description( array $description, array $submitted ) {
		$known = [];
		foreach ( array_slice( (array) ( $description['fields'] ?? [] ), 0, self::MAX_FIELDS ) as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['key'] ) || ! is_string( $field['key'] ) ) {
				continue;
			}
			$known[ $field['key'] ] = $field;
		}

		if ( count( $submitted ) > self::MAX_FIELDS ) {
			return self::validation_error(
				'unknown',
				'',
				__( 'Too many generation controls were submitted.', 'worldgraph' )
			);
		}

		$normalized = [];
		foreach ( $submitted as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $known[ $key ] ) ) {
				return self::validation_error(
					'unknown',
					$key,
					sprintf(
						/* translators: %s: generation control name. */
						__( 'Unknown generation control: %s.', 'worldgraph' ),
						$key
					)
				);
			}

			$result = self::normalize_submitted_value( $known[ $key ], $value );
			if ( isset( $result['error'] ) ) {
				return self::validation_error(
					(string) $result['error'],
					$key,
					self::validation_message( (string) $result['error'], $key )
				);
			}

			// array_key_exists semantics are intentional: false and zero are valid
			// normalized values and must survive the request boundary.
			$normalized[ $key ] = $result['value'];
		}
		ksort( $normalized, SORT_STRING );

		return $normalized;
	}

	/**
	 * Extract defaults from a pure description.
	 *
	 * @param array $description Description returned by describe_configuration().
	 * @return array<string,scalar>
	 */
	public static function description_defaults( array $description ): array {
		$defaults = [];
		foreach ( array_slice( (array) ( $description['fields'] ?? [] ), 0, self::MAX_FIELDS ) as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['key'] ) || ! array_key_exists( 'default', $field ) || ! is_scalar( $field['default'] ) ) {
				continue;
			}
			$defaults[ (string) $field['key'] ] = $field['default'];
		}

		return $defaults;
	}

	/**
	 * Project Project-profile framing defaults into declared output controls.
	 *
	 * Each value is validated independently, so one incompatible profile value
	 * cannot suppress other valid framing defaults. Returned keys always retain
	 * the Template description's public alias.
	 *
	 * @param array $description Trusted server-derived control description.
	 * @param array $profile     Project generation profile.
	 * @return array<string,scalar>
	 */
	public static function profile_defaults( array $description, array $profile ): array {
		$sources = [ $profile ];
		foreach ( [ 'output', 'generation', 'generation_defaults' ] as $container ) {
			if ( isset( $profile[ $container ] ) && is_array( $profile[ $container ] ) ) {
				array_unshift( $sources, $profile[ $container ] );
			}
		}

		$field_semantics = [];
		foreach ( array_slice( (array) ( $description['fields'] ?? [] ), 0, self::MAX_FIELDS ) as $field ) {
			if ( is_array( $field ) && isset( $field['key'] ) ) {
				$field_semantics[] = self::semantic_key( (string) $field['key'] );
			}
		}

		$defaults = [];
		foreach ( array_slice( (array) ( $description['fields'] ?? [] ), 0, self::MAX_FIELDS ) as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['key'] ) ) {
				continue;
			}
			$key      = (string) $field['key'];
			$semantic = self::semantic_key( $key );
			if ( ! in_array( $semantic, [ 'width', 'height', 'aspect_ratio', 'fps' ], true ) ) {
				continue;
			}
			// A fixed-frame workflow such as WAN FLF defines playback duration as
			// length / fps. Overriding only fps would shorten or lengthen the shot,
			// so keep the workflow-authored rate unless it also exposes a duration
			// control that can preserve the intended timing contract.
			if ( 'fps' === $semantic && in_array( 'length', $field_semantics, true ) && ! in_array( 'duration', $field_semantics, true ) ) {
				continue;
			}

			$aliases = 'fps' === $semantic ? [ $key, 'fps', 'frame_rate' ] : [ $key, $semantic ];
			$found   = false;
			$value   = null;
			foreach ( $sources as $source ) {
				foreach ( array_unique( $aliases ) as $alias ) {
					if ( array_key_exists( $alias, $source ) && is_scalar( $source[ $alias ] ) ) {
						$value = $source[ $alias ];
						$found = true;
						break 2;
					}
				}
			}
			if ( ! $found ) {
				continue;
			}

			$validated = self::validate_description( [ 'fields' => [ $field ] ], [ $key => $value ] );
			if ( ! self::is_error( $validated ) && array_key_exists( $key, $validated ) ) {
				$defaults[ $key ] = $validated[ $key ];
			}
		}

		return $defaults;
	}

	/**
	 * Apply already-normalized scalar overrides to an API-format workflow.
	 *
	 * Only a fixed allowlist of generation semantics is mutable. Linked
	 * Primitive/Reroute nodes are followed instead of replacing their links.
	 *
	 * @param array $workflow API-format workflow.
	 * @param array $values   Normalized scalar overrides.
	 * @return array
	 */
	public static function apply_values_to_workflow( array $workflow, array $values ): array {
		if ( count( $workflow ) > self::MAX_WORKFLOW_NODES ) {
			return $workflow;
		}

		foreach ( array_slice( $values, 0, self::MAX_FIELDS, true ) as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$semantic = self::semantic_key( (string) $key );
			if ( null === $semantic ) {
				continue;
			}

			if ( 'negative_prompt' === $semantic ) {
				self::apply_negative_prompt( $workflow, (string) $value );
				self::apply_direct_inputs( $workflow, [ 'negative_prompt' ], $value, $semantic );
				continue;
			}

			if ( self::is_dual_text_semantic( $semantic ) ) {
				// Empty means inherit the already-composed Story Graph prompt.
				if ( '' !== (string) $value ) {
					self::apply_dual_text( $workflow, $semantic, (string) $value );
				}
				continue;
			}

			$targets = self::workflow_input_names( $semantic );
			if ( empty( $targets ) ) {
				continue;
			}
			self::apply_direct_inputs( $workflow, $targets, $value, $semantic );
			self::apply_titled_primitives( $workflow, $semantic, $value );
		}

		return $workflow;
	}

	/** Read a Template field without making pure helper tests load WordPress. */
	private static function template_value( int $template_id, string $field ) {
		if ( function_exists( __NAMESPACE__ . '\\worldgraph_get_field_value' ) ) {
			return worldgraph_get_field_value( $template_id, $field );
		}
		if ( function_exists( 'get_post_meta' ) ) {
			return \get_post_meta( $template_id, $field, true );
		}

		return '';
	}

	/** Decode a bounded JSON object or pass an array through. */
	private static function decode_object( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > self::MAX_CONFIGURATION_BYTES ) {
			return [];
		}

		$decoded = json_decode( $value, true, 32 );
		return is_array( $decoded ) ? $decoded : [];
	}

	/** Ensure a decoded configuration remains bounded before walking it. */
	private static function within_configuration_limit( array $configuration ): bool {
		$encoded = self::json_encode( $configuration );
		return false !== $encoded && strlen( $encoded ) <= self::MAX_CONFIGURATION_BYTES;
	}

	/** Return an API-format workflow supplied through context. */
	private static function context_workflow( array $context ): array {
		$value = $context['workflow'] ?? $context['workflow_json'] ?? [];
		$value = self::decode_object( $value );

		foreach ( [ 'prompt', 'workflow' ] as $wrapper ) {
			if ( isset( $value[ $wrapper ] ) && is_array( $value[ $wrapper ] ) ) {
				$value = $value[ $wrapper ];
				break;
			}
		}

		// Editor graphs have positional widgets and cannot be bound safely here.
		if ( isset( $value['nodes'] ) || count( $value ) > self::MAX_WORKFLOW_NODES ) {
			return [];
		}

		return $value;
	}

	/** Merge scalar provider and Template defaults without losing false/zero. */
	private static function configuration_defaults( array $configuration, array $context ): array {
		$defaults = [];
		foreach ( [ 'input', 'parameters' ] as $container ) {
			if ( isset( $configuration[ $container ] ) && is_array( $configuration[ $container ] ) ) {
				self::merge_scalar_defaults( $defaults, $configuration[ $container ] );
			}
		}

		if ( ! isset( $configuration['input'] ) && ! isset( $configuration['parameters'] ) ) {
			$reserved = [ 'provider_schema', 'runtime', 'run_controls', 'transport', 'provider_tool' ];
			foreach ( $configuration as $key => $value ) {
				if ( ! in_array( (string) $key, $reserved, true ) && is_scalar( $value ) ) {
					$defaults[ (string) $key ] = $value;
				}
			}
		}

		$additional = self::decode_object( $context['default_values'] ?? [] );
		if ( isset( $additional['parameters'] ) && is_array( $additional['parameters'] ) ) {
			$additional = $additional['parameters'];
		}
		self::merge_scalar_defaults( $defaults, $additional );

		return array_slice( $defaults, 0, self::MAX_FIELDS * 4, true );
	}

	/** Merge scalar defaults, preserving zero and false. */
	private static function merge_scalar_defaults( array &$target, array $source ): void {
		foreach ( array_slice( $source, 0, self::MAX_FIELDS * 4, true ) as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$target[ (string) $key ] = $value;
			}
		}
	}

	/** Extract JSON-Schema-like provider properties from supported catalog shapes. */
	private static function provider_properties( array $configuration ): array {
		$schema = isset( $configuration['provider_schema'] ) && is_array( $configuration['provider_schema'] )
			? $configuration['provider_schema']
			: [];
		$candidates = [
			$schema['input_schema']['properties'] ?? null,
			$schema['inputSchema']['properties'] ?? null,
			$schema['input']['properties'] ?? null,
			$schema['parameters']['properties'] ?? null,
			$schema['properties'] ?? null,
		];

		foreach ( $candidates as $properties ) {
			if ( is_array( $properties ) ) {
				return array_slice( $properties, 0, self::MAX_FIELDS * 4, true );
			}
		}

		// Some provider catalogs store a shallow map of field => constraints.
		$properties = [];
		foreach ( array_slice( $schema, 0, self::MAX_FIELDS * 4, true ) as $key => $property ) {
			if ( is_array( $property ) && self::valid_identifier( (string) $key ) ) {
				$properties[ (string) $key ] = $property;
			}
		}

		return $properties;
	}

	/** Normalize explicit runtime/run_controls declarations. */
	private static function explicit_controls( array $configuration, array $defaults ): array {
		$source = $configuration['run_controls'] ?? null;
		if ( null === $source && isset( $configuration['runtime'] ) ) {
			$runtime = $configuration['runtime'];
			if ( is_array( $runtime ) && array_key_exists( 'run_controls', $runtime ) ) {
				$source = $runtime['run_controls'];
			} else {
				$source = $runtime;
			}
		}
		if ( ! is_array( $source ) ) {
			return [ [], [] ];
		}

		if ( isset( $source['fields'] ) && is_array( $source['fields'] ) ) {
			$source = $source['fields'];
		} elseif ( isset( $source['properties'] ) && is_array( $source['properties'] ) ) {
			$source = $source['properties'];
		}

		$fields   = [];
		$disabled = [];
		$seen     = 0;
		foreach ( $source as $source_key => $descriptor ) {
			if ( $seen >= self::MAX_FIELDS * 2 ) {
				break;
			}
			++$seen;

			$key = is_int( $source_key ) ? '' : (string) $source_key;
			if ( is_string( $descriptor ) && '' === $key ) {
				$key        = $descriptor;
				$descriptor = [];
			} elseif ( is_array( $descriptor ) && '' === $key ) {
				$key = (string) ( $descriptor['key'] ?? $descriptor['name'] ?? $descriptor['id'] ?? '' );
			}

			if ( false === $descriptor || ( is_array( $descriptor ) && array_key_exists( 'enabled', $descriptor ) && false === $descriptor['enabled'] ) ) {
				if ( self::valid_identifier( $key ) ) {
					$disabled[] = 'seed' === self::semantic_key( $key ) ? 'seed' : $key;
				}
				continue;
			}
			if ( true === $descriptor ) {
				$descriptor = [];
			} elseif ( ! is_array( $descriptor ) ) {
				$descriptor = [ 'default' => $descriptor ];
			}

			$has_default = array_key_exists( 'default', $descriptor ) || array_key_exists( $key, $defaults );
			$default     = array_key_exists( 'default', $descriptor ) ? $descriptor['default'] : ( $defaults[ $key ] ?? null );
			$field       = self::normalize_field( $key, $descriptor, $default, $has_default, true );
			if ( null !== $field ) {
				$fields[] = $field;
			}
		}

		return [ $fields, array_values( array_unique( $disabled ) ) ];
	}

	/** Normalize one field to the public scalar DTO. */
	private static function normalize_field( string $key, array $schema, $default, bool $has_default, bool $allow_generic ) {
		if ( ! self::valid_identifier( $key ) || self::excluded_key( $key ) ) {
			return null;
		}
		$write_only = $schema['writeOnly'] ?? $schema['write_only'] ?? false;
		$format     = self::snake_key( (string) ( $schema['format'] ?? '' ) );
		if ( true === $write_only || 1 === $write_only || '1' === $write_only || ( is_string( $write_only ) && 'true' === strtolower( trim( $write_only ) ) ) || in_array( $format, [ 'password', 'secret', 'credential', 'token', 'api_key', 'private_key' ], true ) ) {
			return null;
		}
		if ( ! $has_default && array_key_exists( 'default', $schema ) && is_scalar( $schema['default'] ) ) {
			$default     = $schema['default'];
			$has_default = true;
		}

		$semantic = self::semantic_key( $key );
		if ( 'seed' === $semantic ) {
			$key = 'seed';
		}
		$spec     = null !== $semantic ? self::semantic_spec( $semantic ) : null;
		if ( null === $spec && ! $allow_generic ) {
			return null;
		}

		$type = $spec['type'] ?? self::schema_type( $schema, $default, $has_default );
		if ( ! in_array( $type, [ 'string', 'integer', 'number', 'boolean' ], true ) ) {
			return null;
		}
		$public_type = 'string' === $type && in_array( $semantic, [ 'negative_prompt', 'text_g', 'text_l', 'clip_l', 't5xxl' ], true )
			? 'textarea'
			: $type;

		$field = [
			'key'   => $key,
			'label' => self::field_label( $key, $schema, $semantic ),
			'type'  => $public_type,
			'group' => self::field_group( $semantic ),
		];

		$description  = null !== $semantic ? self::semantic_help( $semantic ) : '';
		$provider_note = self::bounded_plain_text( (string) ( $schema['description'] ?? $schema['help'] ?? '' ), 240 );
		if ( '' !== $provider_note && $provider_note !== $description ) {
			$description .= ( '' !== $description ? ' ' : '' ) . sprintf(
				/* translators: %s: provider-authored help for one generation setting. */
				__( 'Provider note: %s', 'worldgraph' ),
				$provider_note
			);
		}
		$description = self::bounded_plain_text( $description, self::MAX_FIELD_DESCRIPTION_LENGTH );
		if ( '' !== $description ) {
			$field['description'] = $description;
		}

		if ( in_array( $type, [ 'integer', 'number' ], true ) ) {
			$hard_min = (float) ( $spec['minimum'] ?? -1000000000 );
			$hard_max = (float) ( $spec['maximum'] ?? 1000000000 );
			$minimum  = self::numeric_constraint( $schema, [ 'minimum', 'min' ], $hard_min );
			$maximum  = self::numeric_constraint( $schema, [ 'maximum', 'max' ], $hard_max );
			$minimum  = max( $hard_min, $minimum );
			$maximum  = min( $hard_max, $maximum );
			if ( $minimum > $maximum ) {
				return null;
			}
			$field['min'] = 'integer' === $type ? (int) ceil( $minimum ) : $minimum;
			$field['max'] = 'integer' === $type ? (int) floor( $maximum ) : $maximum;
			$step = self::numeric_constraint( $schema, [ 'multipleOf', 'step' ], (float) ( $spec['step'] ?? ( 'integer' === $type ? 1 : 0.01 ) ) );
			if ( $step > 0 ) {
				$field['step'] = 'integer' === $type ? max( 1, (int) $step ) : $step;
			}
		}

		$options = self::schema_options( $schema, $field );
		if ( ! empty( $options ) ) {
			$field['type']    = 'select';
			$field['options'] = $options;
		}

		// Seed defaults are never exposed: no submitted value means random.
		if ( 'seed' === $semantic ) {
			$has_default = false;
		}
		// Split-conditioning defaults inherit the composed prompt.
		if ( self::is_dual_text_semantic( $semantic ) ) {
			$default     = '';
			$has_default = true;
		}

		if ( $has_default && is_scalar( $default ) ) {
			$normalized = self::normalize_submitted_value( $field, $default );
			if ( ! isset( $normalized['error'] ) ) {
				$field['default'] = $normalized['value'];
			}
		}

		return $field;
	}

	/** Infer a supported scalar JSON Schema type. */
	private static function schema_type( array $schema, $default, bool $has_default ) {
		$type = $schema['type'] ?? '';
		if ( is_array( $type ) ) {
			$type = reset( $type );
		}
		$type = strtolower( (string) $type );
		if ( 'textarea' === $type ) {
			$type = 'string';
		} elseif ( 'select' === $type ) {
			$type = '';
		}
		if ( in_array( $type, [ 'string', 'integer', 'number', 'boolean' ], true ) ) {
			return $type;
		}
		if ( isset( $schema['minimum'] ) || isset( $schema['maximum'] ) || isset( $schema['min'] ) || isset( $schema['max'] ) ) {
			return 'number';
		}
		$choices = $schema['enum'] ?? $schema['options'] ?? $schema['choices'] ?? $schema['oneOf'] ?? [];
		if ( is_array( $choices ) && ! empty( $choices ) ) {
			$first       = reset( $choices );
			$default     = is_array( $first ) && array_key_exists( 'value', $first )
				? $first['value']
				: ( is_array( $first ) && array_key_exists( 'const', $first ) ? $first['const'] : $first );
			$has_default = true;
		}
		if ( ! $has_default ) {
			return null;
		}
		if ( is_bool( $default ) ) {
			return 'boolean';
		}
		if ( is_int( $default ) ) {
			return 'integer';
		}
		if ( is_float( $default ) ) {
			return 'number';
		}
		return is_string( $default ) ? 'string' : null;
	}

	/** Normalize select options from enum/options/choices/oneOf schema dialects. */
	private static function schema_options( array $schema, array $field ): array {
		$values = $schema['enum'] ?? $schema['options'] ?? $schema['choices'] ?? [];
		$labels = [];
		if ( isset( $schema['oneOf'] ) && is_array( $schema['oneOf'] ) ) {
			$values = [];
			foreach ( $schema['oneOf'] as $choice ) {
				if ( is_array( $choice ) && array_key_exists( 'const', $choice ) ) {
					$values[] = $choice['const'];
					$labels[] = (string) ( $choice['title'] ?? $choice['label'] ?? $choice['const'] );
				}
			}
		}
		if ( ! is_array( $values ) ) {
			return [];
		}

		$options = [];
		foreach ( array_slice( $values, 0, self::MAX_ENUM_VALUES ) as $index => $definition ) {
			$value = is_array( $definition ) && array_key_exists( 'value', $definition ) ? $definition['value'] : $definition;
			$label = is_array( $definition ) ? (string) ( $definition['label'] ?? $definition['title'] ?? $value ) : (string) ( $labels[ $index ] ?? $value );
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$normalized = self::normalize_submitted_value( array_diff_key( $field, [ 'options' => true ] ), $value );
			$existing   = array_column( $options, 'value' );
			if ( ! isset( $normalized['error'] ) && ! in_array( $normalized['value'], $existing, true ) ) {
				if ( is_string( $normalized['value'] ) && self::looks_like_url( $normalized['value'] ) ) {
					continue;
				}
				$options[] = [
					'value' => $normalized['value'],
					'label' => self::bounded_plain_text( $label, 80 ),
				];
			}
		}

		return $options;
	}

	/** Extract a numeric schema constraint by accepted aliases. */
	private static function numeric_constraint( array $schema, array $keys, float $fallback ): float {
		foreach ( $keys as $key ) {
			if ( isset( $schema[ $key ] ) && is_numeric( $schema[ $key ] ) ) {
				$value = (float) $schema[ $key ];
				return is_finite( $value ) ? $value : $fallback;
			}
		}
		return $fallback;
	}

	/** Normalize a submitted scalar to its described type and constraints. */
	private static function normalize_submitted_value( array $field, $value ): array {
		$type = (string) ( $field['type'] ?? '' );
		if ( 'select' === $type ) {
			foreach ( array_slice( (array) ( $field['options'] ?? [] ), 0, self::MAX_ENUM_VALUES ) as $option ) {
				if ( ! is_array( $option ) || ! array_key_exists( 'value', $option ) || ! is_scalar( $option['value'] ) || ! is_scalar( $value ) ) {
					continue;
				}
				if ( $value === $option['value'] || (string) $value === (string) $option['value'] ) {
					return [ 'value' => $option['value'] ];
				}
			}
			return [ 'error' => 'enum' ];
		}
		if ( 'textarea' === $type ) {
			$type = 'string';
		}
		if ( 'boolean' === $type ) {
			if ( is_bool( $value ) ) {
				$normalized = $value;
			} elseif ( 0 === $value || 1 === $value || '0' === $value || '1' === $value ) {
				$normalized = (bool) (int) $value;
			} elseif ( is_string( $value ) && in_array( strtolower( trim( $value ) ), [ 'true', 'false' ], true ) ) {
				$normalized = 'true' === strtolower( trim( $value ) );
			} else {
				return [ 'error' => 'type' ];
			}
		} elseif ( 'integer' === $type ) {
			if ( is_int( $value ) ) {
				$normalized = $value;
			} elseif ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) {
				$normalized = (int) $value;
			} elseif ( is_string( $value ) && preg_match( '/^-?\d+$/D', trim( $value ) ) ) {
				$normalized = (int) trim( $value );
			} else {
				return [ 'error' => 'type' ];
			}
		} elseif ( 'number' === $type ) {
			if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( trim( $value ) ) ) ) {
				return [ 'error' => 'type' ];
			}
			$normalized = (float) $value;
			if ( ! is_finite( $normalized ) ) {
				return [ 'error' => 'type' ];
			}
		} elseif ( 'string' === $type ) {
			if ( ! is_string( $value ) ) {
				return [ 'error' => 'type' ];
			}
			$normalized = self::normalize_text_value( $value );
			$semantic = self::semantic_key( (string) ( $field['key'] ?? '' ) );
			$spec     = null === $semantic ? [] : self::semantic_spec( $semantic );
			$maximum  = (int) ( $spec['maxLength'] ?? self::MAX_TEXT_LENGTH );
			if ( self::text_length( $normalized ) > $maximum ) {
				return [ 'error' => 'range' ];
			}
		} else {
			return [ 'error' => 'type' ];
		}

		if ( in_array( $type, [ 'integer', 'number' ], true ) ) {
			if ( array_key_exists( 'min', $field ) && $normalized < $field['min'] ) {
				return [ 'error' => 'range' ];
			}
			if ( array_key_exists( 'max', $field ) && $normalized > $field['max'] ) {
				return [ 'error' => 'range' ];
			}
			if ( isset( $field['step'] ) && is_numeric( $field['step'] ) && (float) $field['step'] > 0 ) {
				$step      = (float) $field['step'];
				$origin    = isset( $field['min'] ) && is_numeric( $field['min'] ) ? (float) $field['min'] : 0.0;
				$quotient  = ( (float) $normalized - $origin ) / $step;
				$tolerance = max( 0.000000001, abs( $quotient ) * 0.000000000001 );
				if ( abs( $quotient - round( $quotient ) ) > $tolerance ) {
					return [ 'error' => 'range' ];
				}
			}
		}

		if ( 'aspect_ratio' === self::semantic_key( (string) ( $field['key'] ?? '' ) ) ) {
			if ( ! preg_match( '/^(?:\d{1,4}(?:\.\d+)?:\d{1,4}(?:\.\d+)?|auto|square|portrait|landscape)$/iD', $normalized ) ) {
				return [ 'error' => 'enum' ];
			}
		}

		return [ 'value' => $normalized ];
	}

	/** Build a validation WP_Error with stable reason-specific codes. */
	private static function validation_error( string $reason, string $field, string $message ) {
		return new WP_Error(
			'worldgraph_run_control_' . $reason,
			$message,
			[
				'field'  => $field,
				'reason' => $reason,
				'status' => 400,
			]
		);
	}

	/** Human-readable message for a validation reason. */
	private static function validation_message( string $reason, string $field ): string {
		$messages = [
			'type'  =>
				/* translators: %s: generation control field name. */
				__( 'Generation control %s has the wrong value type.', 'worldgraph' ),
			'range' =>
				/* translators: %s: generation control field name. */
				__( 'Generation control %s is outside its allowed range.', 'worldgraph' ),
			'enum'  =>
				/* translators: %s: generation control field name. */
				__( 'Generation control %s is not one of its allowed values.', 'worldgraph' ),
		];

		return sprintf( $messages[ $reason ] ?? $messages['type'], $field );
	}

	/** Normalize provider and workflow aliases to one mutation semantic. */
	private static function semantic_key( string $key ) {
		$key = self::snake_key( $key );
		$aliases = [
			'negative_prompt'     => 'negative_prompt',
			'negative_text'       => 'negative_prompt',
			'prompt_enhance'      => 'prompt_enhance',
			'enable_prompt_enhance' => 'prompt_enhance',
			'enhance_prompt'      => 'prompt_enhance',
			'seed'                => 'seed',
			'noise_seed'          => 'seed',
			'steps'               => 'steps',
			'inference_steps'     => 'steps',
			'num_inference_steps' => 'steps',
			'cfg'                 => 'cfg',
			'cfg_scale'           => 'cfg',
			'guidance'            => 'guidance',
			'guidance_scale'      => 'guidance',
			'sampler'             => 'sampler',
			'sampler_name'        => 'sampler',
			'scheduler'           => 'scheduler',
			'scheduler_name'      => 'scheduler',
			'denoise'             => 'denoise',
			'denoising_strength'  => 'denoise',
			'width'               => 'width',
			'height'              => 'height',
			'aspect_ratio'        => 'aspect_ratio',
			'length'              => 'length',
			'num_frames'          => 'length',
			'frame_count'         => 'length',
			'duration'            => 'duration',
			'duration_seconds'    => 'duration',
			'fps'                 => 'fps',
			'frame_rate'          => 'fps',
			'text_g'              => 'text_g',
			'text_l'              => 'text_l',
			'clip_l'              => 'clip_l',
			't5xxl'               => 't5xxl',
		];

		return $aliases[ $key ] ?? null;
	}

	/** Hard safety/type contract for known generation controls. */
	private static function semantic_spec( string $semantic ): array {
		$specs = [
			'negative_prompt' => [ 'type' => 'string', 'maxLength' => self::MAX_PROMPT_LENGTH ],
			'prompt_enhance'  => [ 'type' => 'boolean' ],
			'seed'            => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 9007199254740991, 'step' => 1 ],
			'steps'           => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'step' => 1 ],
			'cfg'             => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 100, 'step' => 0.1 ],
			'guidance'        => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 100, 'step' => 0.1 ],
			'sampler'         => [ 'type' => 'string', 'maxLength' => 80 ],
			'scheduler'       => [ 'type' => 'string', 'maxLength' => 80 ],
			'denoise'         => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1, 'step' => 0.01 ],
			'width'           => [ 'type' => 'integer', 'minimum' => 64, 'maximum' => 8192, 'step' => 8 ],
			'height'          => [ 'type' => 'integer', 'minimum' => 64, 'maximum' => 8192, 'step' => 8 ],
			'aspect_ratio'    => [ 'type' => 'string', 'maxLength' => 32 ],
			'length'          => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'step' => 1 ],
			'duration'        => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 3600, 'step' => 0.1 ],
			'fps'             => [ 'type' => 'number', 'minimum' => 1, 'maximum' => 240, 'step' => 0.001 ],
			'text_g'          => [ 'type' => 'string', 'maxLength' => self::MAX_PROMPT_LENGTH ],
			'text_l'          => [ 'type' => 'string', 'maxLength' => self::MAX_PROMPT_LENGTH ],
			'clip_l'          => [ 'type' => 'string', 'maxLength' => self::MAX_PROMPT_LENGTH ],
			't5xxl'           => [ 'type' => 'string', 'maxLength' => self::MAX_PROMPT_LENGTH ],
		];

		return $specs[ $semantic ] ?? [];
	}

	/** Workflow input aliases for each mutable semantic. */
	private static function workflow_input_names( string $semantic ): array {
		$inputs = [
			'prompt_enhance' => [ 'prompt_enhance', 'enable_prompt_enhance', 'enhance_prompt' ],
			'seed'         => [ 'seed', 'noise_seed' ],
			'steps'        => [ 'steps', 'num_inference_steps', 'inference_steps' ],
			'cfg'          => [ 'cfg', 'cfg_scale' ],
			'guidance'     => [ 'guidance', 'guidance_scale' ],
			'sampler'      => [ 'sampler_name', 'sampler' ],
			'scheduler'    => [ 'scheduler', 'scheduler_name' ],
			'denoise'      => [ 'denoise', 'denoising_strength' ],
			'width'        => [ 'width' ],
			'height'       => [ 'height' ],
			'aspect_ratio' => [ 'aspect_ratio' ],
			'length'       => [ 'length', 'num_frames', 'frame_count' ],
			'duration'     => [ 'duration', 'duration_seconds' ],
			'fps'          => [ 'fps', 'frame_rate' ],
		];

		return $inputs[ $semantic ] ?? [];
	}

	/** Discover allowlisted controls from an API-format workflow. */
	private static function workflow_fields( array $workflow ): array {
		$fields          = [];
		$semantic_values = [];
		$conflicts       = [];
		if ( empty( $workflow ) || count( $workflow ) > self::MAX_WORKFLOW_NODES ) {
			return [];
		}

		$negative_nodes = self::negative_text_node_ids( $workflow );
		foreach ( $workflow as $node_id => $node ) {
			if ( ! is_array( $node ) || ! isset( $node['inputs'] ) || ! is_array( $node['inputs'] ) || self::protected_node( $node ) ) {
				continue;
			}

			foreach ( array_slice( $node['inputs'], 0, self::MAX_NODE_INPUTS, true ) as $input => $unused ) {
				$semantic = self::semantic_key( (string) $input );
				if ( null === $semantic ) {
					continue;
				}
				if ( self::is_dual_text_semantic( $semantic ) && ( ! self::is_text_encoder( $node ) || isset( $negative_nodes[ (string) $node_id ] ) ) ) {
					continue;
				}
				$targets = self::control_input_targets( $workflow, (string) $node_id, (string) $input, $semantic );
				if ( empty( $targets ) ) {
					continue;
				}

				$values = self::workflow_target_values( $workflow, $targets );
				self::record_semantic_values( $semantic_values, $conflicts, $semantic, $values );
				$current = reset( $values );
				$field   = self::semantic_field( $semantic, $current, ! empty( $values ) );
				if ( 'aspect_ratio' === $semantic ) {
					$probe = self::normalize_submitted_value( $field, $current );
					if ( isset( $probe['error'] ) ) {
						$conflicts[ $semantic ] = true;
						continue;
					}
				}
				if ( in_array( $semantic, [ 'sampler', 'scheduler' ], true ) ) {
					$field = self::constrain_workflow_choice( $field, $semantic, $current );
				}
				self::store_field( $fields, $field, false );
			}

			if ( self::is_primitive_node( $node ) ) {
				$semantic = self::semantic_from_title( self::node_title( $node ) );
				if ( null !== $semantic && ! self::is_dual_text_semantic( $semantic ) ) {
					$input = self::primitive_input_name( $node, $semantic );
					if ( null !== $input ) {
						$targets = self::control_input_targets( $workflow, (string) $node_id, $input, $semantic );
						if ( empty( $targets ) ) {
							continue;
						}
						$values = self::workflow_target_values( $workflow, $targets );
						self::record_semantic_values( $semantic_values, $conflicts, $semantic, $values );
						$current = reset( $values );
						self::store_field( $fields, self::semantic_field( $semantic, $current, ! empty( $values ) ), false );
					}
				}
			}
		}

		$negative_targets = self::negative_prompt_targets( $workflow );
		if ( ! empty( $negative_targets ) ) {
			$negative_values = self::workflow_target_values( $workflow, $negative_targets );
			self::record_semantic_values( $semantic_values, $conflicts, 'negative_prompt', $negative_values );
			$current         = reset( $negative_values );
			// Positive prompt copy is composed from the Story Graph, but negative
			// conditioning is a deliberate Template-level quality default.
			$has_default = ! empty( $negative_values ) && '{{negative_prompt}}' !== trim( (string) $current );
			self::store_field( $fields, self::semantic_field( 'negative_prompt', $current, $has_default ), false );
		}

		foreach ( array_keys( $conflicts ) as $semantic ) {
			foreach ( $fields as $key => $field ) {
				if ( $semantic === self::semantic_key( (string) $key ) ) {
					unset( $fields[ $key ] );
				}
			}
		}

		return array_values( $fields );
	}

	/** Read scalar values from resolved workflow targets. */
	private static function workflow_target_values( array $workflow, array $targets ): array {
		$values = [];
		foreach ( $targets as $target ) {
			$value = $workflow[ $target[0] ]['inputs'][ $target[1] ] ?? null;
			if ( is_scalar( $value ) ) {
				$values[] = $value;
			}
		}
		return $values;
	}

	/** Track whether one public semantic would collapse distinct graph values. */
	private static function record_semantic_values( array &$values, array &$conflicts, string $semantic, array $candidates ): void {
		foreach ( $candidates as $candidate ) {
			$signature = gettype( $candidate ) . ':' . (string) $candidate;
			$values[ $semantic ][ $signature ] = true;
		}
		if ( count( $values[ $semantic ] ?? [] ) > 1 ) {
			$conflicts[ $semantic ] = true;
		}
	}

	/** Build a normalized known-semantic field. */
	private static function semantic_field( string $semantic, $default = null, bool $has_default = false ) {
		return self::normalize_field( $semantic, [], $default, $has_default, false );
	}

	/** Constrain Comfy sampler/scheduler strings to known values plus the stored value. */
	private static function constrain_workflow_choice( array $field, string $semantic, $current ): array {
		$values = 'sampler' === $semantic
			? [ 'euler', 'euler_ancestral', 'heun', 'dpm_2', 'dpm_2_ancestral', 'dpmpp_2m', 'dpmpp_3m_sde', 'dpmpp_sde', 'ddim', 'uni_pc' ]
			: [ 'normal', 'karras', 'exponential', 'sgm_uniform', 'simple', 'ddim_uniform', 'beta' ];
		if ( is_string( $current ) && '' !== trim( $current ) && self::text_length( trim( $current ) ) <= 80 && ! self::looks_like_url( $current ) && ! in_array( trim( $current ), $values, true ) ) {
			$values[] = trim( $current );
		}
		$field['type']    = 'select';
		$field['options'] = array_map(
			static function ( string $value ): array {
				return [
					'value' => $value,
					'label' => ucwords( str_replace( '_', ' ', $value ) ),
				];
			},
			$values
		);

		return $field;
	}

	/** Add local ComfyUI built-in settings when no catalog schema is required. */
	private static function comfy_fields( array $context ): array {
		$output = strtolower( (string) ( $context['output_type'] ?? '' ) );
		$slug   = strtolower( (string) ( $context['modality'] ?? '' ) );
		$video  = 'video' === $output || false !== strpos( $slug, 'video' );
		$audio  = 'audio' === $output || false !== strpos( $slug, 'speech' ) || false !== strpos( $slug, 'music' ) || false !== strpos( $slug, 'sound' );
		if ( $audio ) {
			return [];
		}

		$defaults = $video
			? [ 'width' => 768, 'height' => 512, 'length' => 97, 'fps' => 25, 'steps' => 30, 'cfg' => 3.0, 'denoise' => 1.0, 'sampler' => 'euler', 'scheduler' => 'normal' ]
			: [ 'width' => 1024, 'height' => 1024, 'steps' => 30, 'cfg' => 7.0, 'denoise' => 1.0, 'sampler' => 'dpmpp_2m', 'scheduler' => 'karras' ];

		$fields = [ self::semantic_field( 'negative_prompt', '', true ), self::semantic_field( 'seed' ) ];
		foreach ( $defaults as $key => $value ) {
			$field = self::semantic_field( $key, $value, true );
			if ( in_array( $key, [ 'sampler', 'scheduler' ], true ) ) {
				$field = self::constrain_workflow_choice( $field, $key, $value );
			}
			$fields[] = $field;
		}

		return $fields;
	}

	/** Whether context identifies a ComfyUI Template. */
	private static function is_comfy_context( array $context ): bool {
		if ( ! empty( $context['local_comfyui'] ) ) {
			return true;
		}
		$provider = self::snake_key( (string) ( $context['provider_type'] ?? '' ) );
		return in_array( $provider, [ 'comfyui', 'local_comfyui' ], true );
	}

	/** Store a field by exact public key. */
	private static function store_field( array &$fields, $field, bool $replace ): void {
		if ( ! is_array( $field ) || ! isset( $field['key'] ) || count( $fields ) >= self::MAX_FIELDS * 4 ) {
			return;
		}
		$key = (string) $field['key'];
		if ( $replace || ! isset( $fields[ $key ] ) ) {
			$fields[ $key ] = $field;
		}
	}

	/** Store an auto-discovered field without duplicating an existing alias. */
	private static function store_semantic_field( array &$fields, $field ): void {
		if ( ! is_array( $field ) || ! isset( $field['key'] ) ) {
			return;
		}
		$semantic = self::semantic_key( (string) $field['key'] );
		foreach ( $fields as $key => $existing ) {
			if ( null !== $semantic && $semantic === self::semantic_key( (string) ( $existing['key'] ?? '' ) ) ) {
				if ( ! array_key_exists( 'default', $existing ) && array_key_exists( 'default', $field ) && is_scalar( $field['default'] ) ) {
					$normalized = self::normalize_submitted_value( $existing, $field['default'] );
					if ( ! isset( $normalized['error'] ) ) {
						$fields[ $key ]['default'] = $normalized['value'];
					}
				}
				return;
			}
		}
		self::store_field( $fields, $field, false );
	}

	/** Deterministic presentation order independent of provider property order. */
	private static function compare_fields( array $left, array $right ): int {
		$order = [ 'negative_prompt', 'prompt_enhance', 'seed', 'steps', 'cfg', 'guidance', 'sampler', 'scheduler', 'denoise', 'width', 'height', 'aspect_ratio', 'length', 'duration', 'fps', 'text_g', 'text_l', 'clip_l', 't5xxl' ];
		$left_semantic  = self::semantic_key( (string) ( $left['key'] ?? '' ) );
		$right_semantic = self::semantic_key( (string) ( $right['key'] ?? '' ) );
		$left_rank      = false === array_search( $left_semantic, $order, true ) ? count( $order ) : (int) array_search( $left_semantic, $order, true );
		$right_rank     = false === array_search( $right_semantic, $order, true ) ? count( $order ) : (int) array_search( $right_semantic, $order, true );

		return $left_rank === $right_rank
			? strcmp( (string) ( $left['key'] ?? '' ), (string) ( $right['key'] ?? '' ) )
			: $left_rank <=> $right_rank;
	}

	/** Create the public description and fingerprint its normalized fields. */
	private static function make_description( array $fields ): array {
		$contract = [ 'version' => self::VERSION, 'fields' => array_values( $fields ) ];
		$encoded  = self::json_encode( $contract );

		return [
			'version'     => self::VERSION,
			'fingerprint' => hash( 'sha256', false === $encoded ? '' : $encoded ),
			'fields'      => array_values( $fields ),
		];
	}

	/** Apply matching direct inputs, following Primitive/Reroute references. */
	private static function apply_direct_inputs( array &$workflow, array $targets, $value, string $semantic ): void {
		foreach ( array_keys( $workflow ) as $node_id ) {
			$node = $workflow[ $node_id ] ?? null;
			if ( ! is_array( $node ) || self::protected_node( $node ) || ! isset( $node['inputs'] ) || ! is_array( $node['inputs'] ) ) {
				continue;
			}
			foreach ( $targets as $input ) {
				if ( array_key_exists( $input, $node['inputs'] ) ) {
					self::assign_input( $workflow, (string) $node_id, $input, $value, $semantic, [], 0 );
				}
			}
		}
	}

	/** Follow server-derived mutable targets instead of destroying workflow links. */
	private static function assign_input( array &$workflow, string $node_id, string $input, $value, string $semantic, array $visited, int $depth ): void {
		unset( $visited, $depth );
		foreach ( self::control_input_targets( $workflow, $node_id, $input, $semantic ) as $target ) {
			if ( isset( $workflow[ $target[0] ]['inputs'] ) && array_key_exists( $target[1], $workflow[ $target[0] ]['inputs'] ) && is_scalar( $workflow[ $target[0] ]['inputs'][ $target[1] ] ) ) {
				$workflow[ $target[0] ]['inputs'][ $target[1] ] = $value;
			}
		}
	}

	/** Mutate primitive nodes whose server-owned title declares the semantic. */
	private static function apply_titled_primitives( array &$workflow, string $semantic, $value ): void {
		foreach ( array_keys( $workflow ) as $node_id ) {
			$node = $workflow[ $node_id ] ?? null;
			if ( ! is_array( $node ) || ! self::is_primitive_node( $node ) || $semantic !== self::semantic_from_title( self::node_title( $node ) ) ) {
				continue;
			}
			$input = self::primitive_input_name( $node, $semantic );
			if ( null !== $input ) {
				self::assign_input( $workflow, (string) $node_id, $input, $value, $semantic, [], 0 );
			}
		}
	}

	/** Apply a negative prompt through the sampler's negative conditioning link. */
	private static function apply_negative_prompt( array &$workflow, string $value ): void {
		foreach ( self::negative_prompt_targets( $workflow ) as $target ) {
			self::assign_input( $workflow, $target[0], $target[1], $value, 'negative_prompt', [], 0 );
		}
	}

	/** Apply a split prompt only to an encoder exposing the exact socket. */
	private static function apply_dual_text( array &$workflow, string $semantic, string $value ): void {
		$negative_nodes = self::negative_text_node_ids( $workflow );
		foreach ( array_keys( $workflow ) as $node_id ) {
			$node = $workflow[ $node_id ] ?? null;
			if ( ! is_array( $node ) || isset( $negative_nodes[ (string) $node_id ] ) || ! self::is_text_encoder( $node ) || ! array_key_exists( $semantic, (array) ( $node['inputs'] ?? [] ) ) ) {
				continue;
			}
			self::assign_input( $workflow, (string) $node_id, $semantic, $value, $semantic, [], 0 );
		}
	}

	/** Find text inputs reachable only from negative conditioning sockets. */
	private static function negative_prompt_targets( array $workflow ): array {
		$targets  = self::conditioning_prompt_targets( $workflow, 'negative' );
		$positive = self::conditioning_prompt_targets( $workflow, 'positive' );
		foreach ( array_keys( $positive ) as $key ) {
			unset( $targets[ $key ] );
		}

		return array_values( $targets );
	}

	/** Text targets reachable from one sampler conditioning socket. */
	private static function conditioning_prompt_targets( array $workflow, string $socket ): array {
		$targets = [];
		foreach ( $workflow as $node ) {
			$conditioning = is_array( $node ) ? ( $node['inputs'][ $socket ] ?? null ) : null;
			if ( ! self::is_reference( $conditioning ) ) {
				continue;
			}
			foreach ( self::find_conditioning_text_targets( $workflow, (string) $conditioning[0], [], 0 ) as $target ) {
				$targets[ $target[0] . ':' . $target[1] ] = $target;
			}
		}

		return $targets;
	}

	/** Return encoder/primitive node IDs owned by negative conditioning branches. */
	private static function negative_text_node_ids( array $workflow ): array {
		$ids = [];
		foreach ( self::negative_prompt_targets( $workflow ) as $target ) {
			$ids[ (string) $target[0] ] = true;
		}
		return $ids;
	}

	/** Recursively locate encoder/primitive text fields below one conditioning link. */
	private static function find_conditioning_text_targets( array $workflow, string $node_id, array $visited, int $depth ): array {
		if ( $depth > self::MAX_LINK_DEPTH || isset( $visited[ $node_id ] ) || ! isset( $workflow[ $node_id ] ) || ! is_array( $workflow[ $node_id ] ) ) {
			return [];
		}
		$visited[ $node_id ] = true;
		$node                = $workflow[ $node_id ];
		$inputs              = isset( $node['inputs'] ) && is_array( $node['inputs'] ) ? $node['inputs'] : [];

		if ( self::protected_node( $node ) ) {
			return [];
		}
		// A CONDITIONING link cannot legitimately terminate at an arbitrary
		// numeric/boolean Primitive. Text primitives are reached through the
		// encoder's own text socket below, where topology proves their meaning.
		if ( self::is_primitive_node( $node ) ) {
			return [];
		}
		if ( self::is_text_encoder( $node ) ) {
			$targets = [];
			foreach ( [ 'text', 'prompt', 'string', 'text_g', 'text_l', 'clip_l', 't5xxl' ] as $input ) {
				if ( array_key_exists( $input, $inputs ) && ( is_scalar( $inputs[ $input ] ) || self::is_reference( $inputs[ $input ] ) ) ) {
					$targets[] = [ $node_id, $input ];
				}
			}
			if ( ! empty( $targets ) ) {
				return $targets;
			}
		}

		$targets = [];
		foreach ( array_slice( $inputs, 0, self::MAX_NODE_INPUTS, true ) as $name => $input ) {
			if ( self::is_reference( $input ) && self::is_conditioning_input( (string) $name ) ) {
				$targets = array_merge( $targets, self::find_conditioning_text_targets( $workflow, (string) $input[0], $visited, $depth + 1 ) );
			}
		}

		return $targets;
	}

	/** Whether an intermediary socket carries conditioning rather than framing/model data. */
	private static function is_conditioning_input( string $input ): bool {
		$input = self::snake_key( $input );
		return 'negative' === $input
			|| 'conditioning' === $input
			|| 0 === strpos( $input, 'conditioning_' )
			|| false !== strpos( $input, '_conditioning' );
	}

	/**
	 * Resolve concrete scalar targets for one allowlisted workflow input.
	 *
	 * A direct literal is writable. A direct Primitive/Reroute is writable by
	 * topology. Switch branches are only writable when their Primitive/constant
	 * title identifies the same semantic, preventing selector mutation.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function control_input_targets( array $workflow, string $node_id, string $input, string $semantic ): array {
		if ( ! isset( $workflow[ $node_id ]['inputs'] ) || ! array_key_exists( $input, $workflow[ $node_id ]['inputs'] ) ) {
			return [];
		}
		$value = $workflow[ $node_id ]['inputs'][ $input ];
		if ( is_scalar( $value ) ) {
			return [ [ $node_id, $input ] ];
		}
		if ( ! self::is_reference( $value ) ) {
			return [];
		}

		return self::linked_control_targets( $workflow, (string) $value[0], $semantic, [], 0, true );
	}

	/** Recursively resolve mutable constants through Primitive/Reroute/Switch nodes. */
	private static function linked_control_targets( array $workflow, string $node_id, string $semantic, array $visited, int $depth, bool $direct ): array {
		if ( $depth > self::MAX_LINK_DEPTH || isset( $visited[ $node_id ] ) || ! isset( $workflow[ $node_id ] ) || ! is_array( $workflow[ $node_id ] ) ) {
			return [];
		}
		$visited[ $node_id ] = true;
		$node                = $workflow[ $node_id ];
		$inputs              = isset( $node['inputs'] ) && is_array( $node['inputs'] ) ? $node['inputs'] : [];
		if ( self::protected_node( $node ) ) {
			return [];
		}

		if ( self::is_primitive_node( $node ) ) {
			$title_semantic = self::semantic_from_title( self::node_title( $node ) );
			if ( ! $direct && $semantic !== $title_semantic && ! self::node_has_semantic_input( $node, $semantic ) ) {
				return [];
			}
			$input = self::primitive_input_name( $node, $semantic );
			if ( null === $input ) {
				return [];
			}
			$value = $inputs[ $input ];
			if ( is_scalar( $value ) ) {
				return [ [ $node_id, $input ] ];
			}
			if ( self::is_reference( $value ) ) {
				return self::linked_control_targets( $workflow, (string) $value[0], $semantic, $visited, $depth + 1, $direct );
			}
			return [];
		}

		if ( ! self::is_control_switch( $node ) ) {
			return [];
		}

		$targets = [];
		foreach ( array_slice( $inputs, 0, self::MAX_NODE_INPUTS, true ) as $input => $value ) {
			if ( self::is_reference( $value ) ) {
				$targets = array_merge( $targets, self::linked_control_targets( $workflow, (string) $value[0], $semantic, $visited, $depth + 1, false ) );
			} elseif ( is_scalar( $value ) && $semantic === self::semantic_from_title( self::node_title( $node ) ) && ! self::is_switch_selector_input( (string) $input ) ) {
				$targets[] = [ $node_id, (string) $input ];
			}
		}

		$unique = [];
		foreach ( $targets as $target ) {
			$unique[ $target[0] . ':' . $target[1] ] = $target;
		}
		return array_values( $unique );
	}

	/** Identify a Primitive/Reroute node. */
	private static function is_primitive_node( array $node ): bool {
		$class = strtolower( (string) ( $node['class_type'] ?? '' ) );
		return false !== strpos( $class, 'primitive' ) || false !== strpos( $class, 'reroute' );
	}

	/** Identify bounded value-routing nodes whose branches may be traced safely. */
	private static function is_control_switch( array $node ): bool {
		$class = strtolower( (string) ( $node['class_type'] ?? '' ) );
		return false !== strpos( $class, 'switch' ) || false !== strpos( $class, 'selector' );
	}

	/** Whether a node exposes an input alias for the requested semantic. */
	private static function node_has_semantic_input( array $node, string $semantic ): bool {
		foreach ( array_keys( (array) ( $node['inputs'] ?? [] ) ) as $input ) {
			if ( $semantic === self::semantic_key( (string) $input ) ) {
				return true;
			}
		}
		return false;
	}

	/** Switch selectors choose branches and must never be replaced by a value. */
	private static function is_switch_selector_input( string $input ): bool {
		$input = self::snake_key( $input );
		return in_array( $input, [ 'select', 'selector', 'switch', 'condition', 'boolean', 'enabled', 'index' ], true );
	}

	/** Choose a scalar/reference input carried by a Primitive node. */
	private static function primitive_input_name( array $node, $semantic ) {
		$inputs = isset( $node['inputs'] ) && is_array( $node['inputs'] ) ? $node['inputs'] : [];
		$candidates = array_merge( null === $semantic ? [] : self::workflow_input_names( (string) $semantic ), [ 'value', 'string', 'text' ] );
		foreach ( array_unique( $candidates ) as $candidate ) {
			if ( array_key_exists( $candidate, $inputs ) && ( is_scalar( $inputs[ $candidate ] ) || self::is_reference( $inputs[ $candidate ] ) ) ) {
				return $candidate;
			}
		}
		foreach ( array_slice( $inputs, 0, self::MAX_NODE_INPUTS, true ) as $key => $value ) {
			if ( is_scalar( $value ) || self::is_reference( $value ) ) {
				return (string) $key;
			}
		}
		return null;
	}

	/** Whether a workflow value is a ComfyUI `[node_id, output_index]` link. */
	private static function is_reference( $value ): bool {
		return is_array( $value ) && 2 === count( $value ) && ( is_string( $value[0] ) || is_int( $value[0] ) ) && is_numeric( $value[1] );
	}

	/** Prevent mutations of model/codec/loader nodes. */
	private static function protected_node( array $node ): bool {
		$class = strtolower( (string) ( $node['class_type'] ?? '' ) );
		return (bool) preg_match( '/(?:checkpoint|loader|lora|vae|modelpatch|unet)/', $class );
	}

	/** Recognize text encoders while excluding CLIP/model loaders. */
	private static function is_text_encoder( array $node ): bool {
		if ( self::protected_node( $node ) ) {
			return false;
		}
		$class = strtolower( (string) ( $node['class_type'] ?? '' ) );
		return false !== strpos( $class, 'textencode' )
			|| false !== strpos( $class, 'text_encode' )
			|| false !== strpos( $class, 'cliptext' )
			|| false !== strpos( $class, 'promptencode' );
	}

	/** Require the exact dual-CLIP/T5 input to exist on an encoder. */
	private static function workflow_has_dual_input( array $workflow, string $semantic ): bool {
		$negative_nodes = self::negative_text_node_ids( $workflow );
		foreach ( $workflow as $node_id => $node ) {
			if ( is_array( $node ) && ! isset( $negative_nodes[ (string) $node_id ] ) && self::is_text_encoder( $node ) && array_key_exists( $semantic, (array) ( $node['inputs'] ?? [] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether a semantic is a split text-conditioning control. */
	private static function is_dual_text_semantic( $semantic ): bool {
		return in_array( $semantic, [ 'text_g', 'text_l', 'clip_l', 't5xxl' ], true );
	}

	/** Derive a known control from a bounded Primitive title. */
	private static function semantic_from_title( string $title ) {
		$title = self::snake_key( $title );
		$aliases = [
			'prompt_enhance'        => 'prompt_enhance',
			'enable_prompt_enhance' => 'prompt_enhance',
			'enhance_prompt'        => 'prompt_enhance',
			'fixed_seed'      => 'seed',
			'random_seed'     => 'seed',
			'seed'            => 'seed',
			'steps'           => 'steps',
			'sampling_steps'  => 'steps',
			'cfg'             => 'cfg',
			'cfg_scale'       => 'cfg',
			'guidance'        => 'guidance',
			'guidance_scale'  => 'guidance',
			'sampler'         => 'sampler',
			'scheduler'       => 'scheduler',
			'denoise'         => 'denoise',
			'width'           => 'width',
			'height'          => 'height',
			'aspect_ratio'    => 'aspect_ratio',
			'length'          => 'length',
			'frames'          => 'length',
			'duration'        => 'duration',
			'fps'             => 'fps',
			'frame_rate'      => 'fps',
			'negative_prompt' => 'negative_prompt',
		];

		if ( isset( $aliases[ $title ] ) ) {
			return $aliases[ $title ];
		}

		// Comfy commonly titles scalar nodes `Int (Steps)`, `Float (CFG)`,
		// `Primitive Width`, and similar. Strip type wrappers only; arbitrary
		// descriptive titles are not treated as workflow bindings.
		$tokens     = explode( '_', $title );
		$qualifiers = [ 'int', 'integer', 'float', 'number', 'string', 'bool', 'boolean', 'primitive', 'constant', 'value', 'control' ];
		while ( ! empty( $tokens ) && in_array( reset( $tokens ), $qualifiers, true ) ) {
			array_shift( $tokens );
		}
		while ( ! empty( $tokens ) && in_array( end( $tokens ), $qualifiers, true ) ) {
			array_pop( $tokens );
		}
		$title = implode( '_', $tokens );

		return $aliases[ $title ] ?? null;
	}

	/** Return a node's bounded server-owned title. */
	private static function node_title( array $node ): string {
		return self::bounded_plain_text( (string) ( $node['_meta']['title'] ?? $node['title'] ?? '' ), 80 );
	}

	/** Validate a public/provider key without accepting binding paths. */
	private static function valid_identifier( string $key ): bool {
		return '' !== $key && strlen( $key ) <= 64 && 1 === preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/D', $key );
	}

	/** Deny execution-owned, sensitive, media, model, URL, and output-count fields. */
	private static function excluded_key( string $key ): bool {
		$normalized = self::snake_key( $key );
		if ( in_array( $normalized, [ 'text_g', 'text_l', 'clip_l', 't5xxl', 'negative_prompt' ], true ) ) {
			return false;
		}

		$exact = [
			'prompt', 'positive_prompt', 'text', 'string', 'input', 'inputs',
			'image', 'images', 'start_frame', 'end_frame', 'video', 'audio', 'mask',
			'model', 'checkpoint', 'ckpt_name', 'vae', 'clip', 'lora',
			'access_key', 'private_key', 'client_secret', 'access_token',
			'refresh_token', 'bearer', 'cookie', 'session_cookie',
			'output', 'outputs', 'num_outputs', 'output_count', 'num_images',
			'number_of_images', 'batch_size', 'batch_count', 'count', 'n',
			'endpoint', 'url', 'uri', 'callback', 'webhook', 'transport',
			'provider_tool', 'provider_schema', 'runtime', 'run_controls',
			'workflow', 'template', 'template_name', 'parameters', 'configuration',
		];
		if ( in_array( $normalized, $exact, true ) || '_' === substr( $normalized, 0, 1 ) ) {
			return true;
		}

		return (bool) preg_match(
			'/(?:^|_)(?:api_?key|access_?key|private_?key|auth(?:orization)?|bearer|cookie|credential|password|secret|token|callback|webhook|url|uri|endpoint|model|checkpoint|ckpt|vae|lora|loader)(?:_|$)|(?:^|_)prompt$|_id$|(?:^|_)(?:output|image)s?_count$/',
			$normalized
		);
	}

	/** Convert camelCase, spaces, and hyphens to stable snake_case for matching. */
	private static function snake_key( string $value ): string {
		$value = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', trim( $value ) );
		$value = strtolower( (string) preg_replace( '/[^A-Za-z0-9]+/', '_', (string) $value ) );
		return trim( $value, '_' );
	}


	/**
	 * Return concise, provider-neutral help for a known generation control.
	 *
	 * Provider prose is supplemental because API schemas frequently omit a
	 * description or assume model-specific knowledge.
	 */
	private static function semantic_help( string $semantic ): string {
		$help = [
			'negative_prompt' => __( 'Describes visual elements or qualities the model should avoid. Keep it concise; long or conflicting exclusions can weaken the main prompt.', 'worldgraph' ),
			'prompt_enhance'  => __( 'Lets the provider rewrite or expand the prompt before generation. Turn it off when exact wording and repeatability matter.', 'worldgraph' ),
			'seed'            => __( 'Sets the starting random noise. Reusing the same seed with the same model and settings usually produces a related result; leave it blank for a random seed.', 'worldgraph' ),
			'steps'           => __( 'Sets how many refinement passes the model performs. More steps take longer and may add detail, but gains usually diminish.', 'worldgraph' ),
			'cfg'             => __( 'CFG (Classifier-Free Guidance) sets how strongly the model follows the text prompt instead of its unguided prediction. Higher values can increase prompt adherence; values that are too high can look rigid, oversaturated, or artifacted. The useful range is model-specific.', 'worldgraph' ),
			'guidance'        => __( 'Sets the model\'s prompt-guidance strength. Higher values generally follow the prompt more literally; values that are too high can reduce natural variation or create artifacts. This is model-specific and is not always classic CFG.', 'worldgraph' ),
			'sampler'         => __( 'Selects the algorithm that turns noise into an image or video. It can change speed, detail, and stability; keep the Template preset unless you are deliberately testing.', 'worldgraph' ),
			'scheduler'       => __( 'Controls how noise is removed across the sampling steps. It affects texture and detail and is normally chosen together with the model and sampler.', 'worldgraph' ),
			'denoise'         => __( 'Controls how far generation may move from the source image or latent. Lower values preserve more of the source; higher values reimagine more of it.', 'worldgraph' ),
			'width'           => __( 'Sets output width in pixels. Larger values require more memory and time and must stay within the model\'s supported resolutions.', 'worldgraph' ),
			'height'          => __( 'Sets output height in pixels. Larger values require more memory and time and must stay within the model\'s supported resolutions.', 'worldgraph' ),
			'aspect_ratio'    => __( 'Sets the frame\'s width-to-height proportion. The provider may use it to choose or adjust the exact pixel dimensions.', 'worldgraph' ),
			'length'          => __( 'Sets the number of generated video frames. At a fixed FPS, more frames make a longer clip and require more processing.', 'worldgraph' ),
			'duration'        => __( 'Sets the requested clip length in seconds. The provider may round it to a supported frame count or duration.', 'worldgraph' ),
			'fps'             => __( 'FPS (Frames Per Second) sets playback rate. With a fixed frame count, higher FPS makes the clip shorter; with a fixed duration, it requires more frames for smoother motion.', 'worldgraph' ),
			'text_g'          => __( 'Advanced prompt sent to the model\'s G text encoder. Leave it blank to reuse the composed prompt unless the Template specifically calls for separate encoder text.', 'worldgraph' ),
			'text_l'          => __( 'Advanced prompt sent to the model\'s L text encoder. Leave it blank to reuse the composed prompt unless the Template specifically calls for separate encoder text.', 'worldgraph' ),
			'clip_l'          => __( 'Advanced prompt sent to the model\'s CLIP-L text encoder. Leave it blank to reuse the composed prompt unless the Template specifically calls for separate encoder text.', 'worldgraph' ),
			't5xxl'           => __( 'Advanced prompt sent to the model\'s T5-XXL text encoder. Leave it blank to reuse the composed prompt unless the Template specifically calls for separate encoder text.', 'worldgraph' ),
		];

		return $help[ $semantic ] ?? '';
	}

	/** Generate or accept a bounded field label. */
	private static function field_label( string $key, array $schema, $semantic = null ): string {
		if ( 'cfg' === $semantic ) {
			return __( 'CFG (Classifier-Free Guidance)', 'worldgraph' );
		}

		$label = (string) ( $schema['label'] ?? $schema['title'] ?? '' );
		if ( '' === trim( $label ) ) {
			$semantic_labels = [
				'negative_prompt' => __( 'Negative Prompt', 'worldgraph' ),
				'prompt_enhance'  => __( 'Prompt Enhancement', 'worldgraph' ),
				'seed'            => __( 'Seed', 'worldgraph' ),
				'steps'           => __( 'Steps', 'worldgraph' ),
				'guidance'        => __( 'Guidance Scale', 'worldgraph' ),
				'sampler'         => __( 'Sampler', 'worldgraph' ),
				'scheduler'       => __( 'Scheduler', 'worldgraph' ),
				'denoise'         => __( 'Denoise Strength', 'worldgraph' ),
				'width'           => __( 'Width', 'worldgraph' ),
				'height'          => __( 'Height', 'worldgraph' ),
				'aspect_ratio'    => __( 'Aspect Ratio', 'worldgraph' ),
				'length'          => __( 'Frame Count', 'worldgraph' ),
				'duration'        => __( 'Duration', 'worldgraph' ),
				'fps'             => __( 'FPS (Frames Per Second)', 'worldgraph' ),
				'text_g'          => __( 'G Encoder Prompt', 'worldgraph' ),
				'text_l'          => __( 'L Encoder Prompt', 'worldgraph' ),
				'clip_l'          => __( 'CLIP-L Prompt', 'worldgraph' ),
				't5xxl'           => __( 'T5-XXL Prompt', 'worldgraph' ),
			];
			$label = null !== $semantic && isset( $semantic_labels[ $semantic ] )
				? $semantic_labels[ $semantic ]
				: ucwords( str_replace( [ '_', '-' ], ' ', self::snake_key( $key ) ) );
		}
		return self::bounded_plain_text( $label, 80 );
	}

	/** Assign a UI-native control group without trusting provider markup. */
	private static function field_group( $semantic ): string {
		if ( in_array( $semantic, [ 'negative_prompt', 'text_g', 'text_l', 'clip_l', 't5xxl' ], true ) ) {
			return 'conditioning';
		}
		if ( in_array( $semantic, [ 'seed', 'steps', 'cfg', 'guidance', 'sampler', 'scheduler', 'denoise' ], true ) ) {
			return 'sampling';
		}
		if ( in_array( $semantic, [ 'width', 'height', 'aspect_ratio', 'length', 'duration', 'fps' ], true ) ) {
			return 'output';
		}
		return 'advanced';
	}

	/** Strip markup/control characters and bound public metadata strings. */
	private static function bounded_plain_text( string $value, int $maximum ): string {
		$value = wp_strip_all_tags( $value, true );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value );
		$value = preg_replace( '/\s+/u', ' ', (string) $value );
		$value = trim( (string) $value );
		return self::text_substr( $value, $maximum );
	}

	/** Normalize runtime text without interpreting it as HTML or a path. */
	private static function normalize_text_value( string $value ): string {
		$value = function_exists( 'wp_check_invalid_utf8' ) ? wp_check_invalid_utf8( $value, true ) : $value;
		$value = preg_replace( '/[\x00\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value );
		return trim( (string) $value );
	}

	/** UTF-8-aware string length with a portable fallback. */
	private static function text_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/** UTF-8-aware substring with a portable fallback. */
	private static function text_substr( string $value, int $maximum ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $maximum, 'UTF-8' ) : substr( $value, 0, $maximum );
	}

	/** Identify URL-like enum values so the DTO never advertises URLs. */
	private static function looks_like_url( string $value ): bool {
		return 1 === preg_match( '#^(?:https?|ftp)://#i', trim( $value ) );
	}

	/** WordPress-aware deterministic JSON encoding. */
	private static function json_encode( array $value ) {
		$options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		return function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, $options ) : json_encode( $value, $options );
	}

	/** Test a value for WP_Error without requiring is_wp_error in pure tests. */
	private static function is_error( $value ): bool {
		return function_exists( 'is_wp_error' ) ? is_wp_error( $value ) : $value instanceof WP_Error;
	}
}
