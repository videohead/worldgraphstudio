<?php
/**
 * Hierarchical, Template-scoped generation run-control defaults.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Edits the Template baseline and stores sparse Project/item overrides.
 *
 * The visual editor may resemble a repeater, but row order has no meaning.
 * Every entry is selected by the exact Connection and Template pair and is
 * revalidated against the Template's current run-control fingerprint.
 */
class Generation_Run_Defaults {

	/** Stored document and public DTO version. */
	public const VERSION = 1;

	/** One bounded contextual document is stored on each Project or source item. */
	public const META_KEY = '_worldgraph_generation_run_defaults';

	/** Defensive storage bounds. */
	private const MAX_ENTRIES = 64;
	private const MAX_BYTES   = 65536;

	/** Supported saved layers. */
	private const SCOPES = [ 'template', 'project', 'item' ];

	/** Build the stable row key for an exact Connection and Template pair. */
	public static function pair_key( int $connection_id, int $template_id ): string {
		return 'c:' . max( 0, $connection_id ) . ':t:' . max( 0, $template_id );
	}

	/**
	 * Describe inherited values and their source for an editor context.
	 *
	 * @param int    $source_id   Story Graph source post.
	 * @param int    $template_id Selected Template.
	 * @param string $scope       `item` includes every layer; `project` or `template` stops at that layer.
	 * @param array  $description Optional run-control description.
	 * @return array<string,mixed>
	 */
	public static function describe( int $source_id, int $template_id, string $scope = 'item', array $description = [] ): array {
		$scope          = in_array( $scope, self::SCOPES, true ) ? $scope : 'item';
		$description    = $description ?: Template_Run_Controls::describe( $template_id );
		$connection_id  = self::connection_id( $template_id );
		$fingerprint    = sanitize_text_field( (string) ( $description['fingerprint'] ?? '' ) );
		$project_id     = Generation_Workflows::project_id_for_source( $source_id );
		$template       = Template_Run_Controls::description_defaults( $description );
		$template_layer = self::template_layer( $template_id, $description, $template );
		$profile        = 'template' !== $scope
			? Template_Run_Controls::profile_defaults( $description, Asset_Generator::project_media_profile( $source_id ) )
			: [];
		$project        = 'template' !== $scope && $project_id
			? self::read_layer( $project_id, $connection_id, $template_id, $description )
			: self::empty_layer( 0 );
		$item           = 'item' === $scope && $source_id !== $project_id
			? self::read_layer( $source_id, $connection_id, $template_id, $description )
			: self::empty_layer( $source_id );

		$effective = [];
		$sources   = [];
		self::overlay( $effective, $sources, $template, 'template' );
		if ( 'template' !== $scope ) {
			self::overlay( $effective, $sources, $profile, 'project_profile' );
			self::overlay( $effective, $sources, (array) $project['values'], 'project' );
		}
		if ( 'item' === $scope && $source_id !== $project_id ) {
			self::overlay( $effective, $sources, (array) $item['values'], 'item' );
		}

		$targets = [
			[
				'scope'         => 'template',
				'post_id'       => $template_id,
				'label'         => __( 'Template', 'worldgraph' ),
				'has_overrides' => ! empty( $template_layer['has_entry'] ),
			],
		];
		if ( 'template' !== $scope && $project_id ) {
			$targets[] = [
				'scope'         => 'project',
				'post_id'       => $project_id,
				'label'         => __( 'Project', 'worldgraph' ),
				'has_overrides' => ! empty( $project['has_entry'] ),
			];
		}
		if ( 'item' === $scope && $source_id !== $project_id ) {
			$labels    = worldgraph_get_all_cpts();
			$post_type = (string) get_post_type( $source_id );
			$targets[] = [
				'scope'         => 'item',
				'post_id'       => $source_id,
				'label'         => (string) ( $labels[ $post_type ] ?? __( 'Item', 'worldgraph' ) ),
				'has_overrides' => ! empty( $item['has_entry'] ),
			];
		}

		return [
			'version'       => self::VERSION,
			'source_id'     => $source_id,
			'project_id'    => $project_id,
			'template_id'   => $template_id,
			'connection_id' => $connection_id,
			'fingerprint'   => $fingerprint,
			'scope'         => $scope,
			'effective'     => $effective,
			'sources'       => $sources,
			'layers'        => [
				'template'        => $template_layer,
				'project_profile' => [ 'post_id' => $project_id, 'values' => $profile, 'status' => 'current' ],
				'project'         => $project,
				'item'            => $item,
			],
			'targets'       => $targets,
			'warnings'      => array_values(
				array_unique(
					array_merge( (array) $template_layer['warnings'], (array) $project['warnings'], (array) $item['warnings'] )
				)
			),
		];
	}

	/**
	 * Return only persisted Project/item values used at execution time.
	 *
	 * Template defaults and Project framing are merged by their existing runner
	 * paths; this method supplies the two new sparse editorial layers.
	 *
	 * @return array<string,scalar>
	 */
	public static function runtime_overrides( int $source_id, int $template_id, array $description = [] ): array {
		$resolved = self::describe( $source_id, $template_id, 'item', $description );
		return array_merge(
			(array) ( $resolved['layers']['project']['values'] ?? [] ),
			(array) ( $resolved['layers']['item']['values'] ?? [] )
		);
	}

	/**
	 * Merge persisted defaults with one-off values and validate the result.
	 *
	 * @return array<string,scalar>|WP_Error
	 */
	public static function runtime_values( int $source_id, int $template_id, array $one_off = [], array $description = [] ) {
		$description = $description ?: Template_Run_Controls::describe( $template_id );
		$one_off     = Template_Run_Controls::validate_description( $description, $one_off );
		if ( is_wp_error( $one_off ) ) {
			return $one_off;
		}

		return Template_Run_Controls::validate_description(
			$description,
			array_merge( self::runtime_overrides( $source_id, $template_id, $description ), $one_off )
		);
	}

	/**
	 * Replace one Template, sparse Project, or sparse item layer from a complete editor form.
	 *
	 * @param int    $source_id   Story Graph source used to resolve its Project.
	 * @param int    $template_id Selected Template.
	 * @param string $scope       `template`, `project`, or `item`.
	 * @param array  $values      Complete current form values.
	 * @param string $fingerprint Client-observed Template contract fingerprint.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save( int $source_id, int $template_id, string $scope, array $values, string $fingerprint ) {
		$context = self::write_context( $source_id, $template_id, $scope, $fingerprint );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$description = (array) $context['description'];
		$validated   = Template_Run_Controls::validate_description( $description, $values );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( 'template' === $scope ) {
			$saved = self::persist_template_defaults( $template_id, $validated, (string) $context['fingerprint'] );
			return is_wp_error( $saved ) ? $saved : self::describe( $source_id, $template_id, 'template' );
		}

		$baseline = self::baseline_for_scope( $source_id, $template_id, $scope, $description );
		$sparse   = [];
		foreach ( $validated as $key => $value ) {
			if ( ! array_key_exists( $key, $baseline ) || ! self::same_value( $value, $baseline[ $key ] ) ) {
				$sparse[ $key ] = $value;
			}
		}

		$snapshot = [];
		$document = self::document_for_write( (int) $context['target_id'], $snapshot );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		$key = self::pair_key( (int) $context['connection_id'], $template_id );
		if ( $sparse ) {
			$document['entries'][ $key ] = [
				'connection_id' => (int) $context['connection_id'],
				'template_id'   => $template_id,
				'fingerprint'   => (string) $context['fingerprint'],
				'values'        => $sparse,
			];
		} else {
			unset( $document['entries'][ $key ] );
		}

		$saved = self::persist_document( (int) $context['target_id'], $document, $snapshot );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return self::describe( $source_id, $template_id, 'project' === $scope ? 'project' : 'item', $description );
	}

	/** Remove the requested Template baseline or exact Connection+Template contextual layer. */
	public static function reset( int $source_id, int $template_id, string $scope, string $fingerprint ) {
		$context = self::write_context( $source_id, $template_id, $scope, $fingerprint );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( 'template' === $scope ) {
			$saved = self::persist_template_defaults( $template_id, [], (string) $context['fingerprint'] );
			return is_wp_error( $saved ) ? $saved : self::describe( $source_id, $template_id, 'template' );
		}
		$snapshot = [];
		$document = self::document_for_write( (int) $context['target_id'], $snapshot, true );
		if ( is_wp_error( $document ) ) {
			return $document;
		}
		unset( $document['entries'][ self::pair_key( (int) $context['connection_id'], $template_id ) ] );

		$saved = self::persist_document( (int) $context['target_id'], $document, $snapshot );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return self::describe( $source_id, $template_id, 'project' === $scope ? 'project' : 'item', (array) $context['description'] );
	}

	/** Resolve and validate the target of a mutating operation. */
	private static function write_context( int $source_id, int $template_id, string $scope, string $fingerprint ) {
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			return self::error( 'worldgraph_generation_default_scope_invalid', __( 'Choose Template, Project, or item defaults.', 'worldgraph' ), 400 );
		}
		$source = get_post( $source_id );
		if ( ! $source instanceof \WP_Post || ! Asset_Generator::supports( $source_id ) ) {
			return self::error( 'worldgraph_generation_default_source_invalid', __( 'That Story Graph item cannot store generation defaults.', 'worldgraph' ), 404 );
		}
		$template = get_post( $template_id );
		if ( ! $template instanceof \WP_Post || 'worldgraph_template' !== $template->post_type ) {
			return self::error( 'worldgraph_generation_default_template_invalid', __( 'That generation Template does not exist.', 'worldgraph' ), 404 );
		}
		$connection_id = self::connection_id( $template_id );
		if ( ! $connection_id ) {
			return self::error( 'worldgraph_generation_default_connection_invalid', __( 'That Template has no Connection.', 'worldgraph' ), 409 );
		}
		$description = Template_Run_Controls::describe( $template_id );
		$current     = (string) ( $description['fingerprint'] ?? '' );
		if ( '' === $fingerprint || '' === $current || ! hash_equals( $current, $fingerprint ) ) {
			return self::error( 'worldgraph_generation_default_fingerprint_stale', __( 'The Template controls changed. Refresh them before saving defaults.', 'worldgraph' ), 409 );
		}

		$project_id = Generation_Workflows::project_id_for_source( $source_id );
		if ( 'template' === $scope ) {
			$target_id = $template_id;
		} elseif ( 'project' === $scope ) {
			if ( ! $project_id ) {
				return self::error( 'worldgraph_generation_default_project_missing', __( 'This item does not belong to a Project.', 'worldgraph' ), 409 );
			}
			$target_id = $project_id;
		} else {
			if ( $source_id === $project_id ) {
				return self::error( 'worldgraph_generation_default_item_is_project', __( 'Use the Project default layer for a Project record.', 'worldgraph' ), 400 );
			}
			$target_id = $source_id;
		}

		return [
			'target_id'     => $target_id,
			'connection_id' => $connection_id,
			'fingerprint'   => $current,
			'description'   => $description,
		];
	}

	/** Build the inherited values beneath the layer being saved. */
	private static function baseline_for_scope( int $source_id, int $template_id, string $scope, array $description ): array {
		$baseline = array_merge(
			Template_Run_Controls::description_defaults( $description ),
			Template_Run_Controls::profile_defaults( $description, Asset_Generator::project_media_profile( $source_id ) )
		);
		if ( 'item' === $scope ) {
			$project_id = Generation_Workflows::project_id_for_source( $source_id );
			if ( $project_id ) {
				$project = self::read_layer( $project_id, self::connection_id( $template_id ), $template_id, $description );
				$baseline = array_merge( $baseline, (array) $project['values'] );
			}
		}

		return $baseline;
	}

	/** Read and atomically revalidate one exact stored layer. */
	private static function read_layer( int $post_id, int $connection_id, int $template_id, array $description ): array {
		$layer  = self::empty_layer( $post_id );
		$stored = get_post_meta( $post_id, self::META_KEY, false );
		if ( [] === $stored ) {
			return $layer;
		}
		if ( ! is_array( $stored ) || 1 !== count( $stored ) ) {
			$layer['has_entry']   = true;
			$layer['status']      = 'invalid_document';
			$layer['warnings'][]  = 'invalid_document';
			return $layer;
		}
		$raw = reset( $stored );
		if (
			! is_array( $raw )
			|| self::VERSION !== (int) ( $raw['version'] ?? 0 )
			|| ! is_array( $raw['entries'] ?? null )
			|| count( $raw['entries'] ) > self::MAX_ENTRIES
		) {
			$layer['has_entry']   = true;
			$layer['status']      = 'invalid_document';
			$layer['warnings'][]  = 'invalid_document';
			return $layer;
		}
		$raw_encoded = wp_json_encode( $raw );
		if ( false === $raw_encoded || strlen( $raw_encoded ) > self::MAX_BYTES ) {
			$layer['has_entry']   = true;
			$layer['status']      = 'invalid_document';
			$layer['warnings'][]  = 'invalid_document';
			return $layer;
		}
		foreach ( $raw['entries'] as $stored_key => $stored_entry ) {
			$stored_connection  = is_array( $stored_entry ) ? absint( $stored_entry['connection_id'] ?? 0 ) : 0;
			$stored_template    = is_array( $stored_entry ) ? absint( $stored_entry['template_id'] ?? 0 ) : 0;
			$stored_fingerprint = is_array( $stored_entry ) ? sanitize_text_field( (string) ( $stored_entry['fingerprint'] ?? '' ) ) : '';
			if (
				! is_string( $stored_key )
				|| ! is_array( $stored_entry )
				|| ! $stored_connection
				|| ! $stored_template
				|| self::pair_key( $stored_connection, $stored_template ) !== $stored_key
				|| ! preg_match( '/^[a-f0-9]{64}$/', $stored_fingerprint )
				|| ! self::scalar_values( $stored_entry['values'] ?? null )
			) {
				$layer['has_entry']   = true;
				$layer['status']      = 'invalid_document';
				$layer['warnings'][]  = 'invalid_document';
				return $layer;
			}
		}

		$key   = self::pair_key( $connection_id, $template_id );
		$entry = $raw['entries'][ $key ] ?? null;
		if ( ! is_array( $entry ) ) {
			return $layer;
		}
		$layer['has_entry'] = true;
		if ( $connection_id !== absint( $entry['connection_id'] ?? 0 ) || $template_id !== absint( $entry['template_id'] ?? 0 ) || ! is_array( $entry['values'] ?? null ) ) {
			$layer['status']     = 'invalid_entry';
			$layer['warnings'][] = 'invalid_entry';
			return $layer;
		}

		$validated = Template_Run_Controls::validate_description( $description, $entry['values'] );
		if ( is_wp_error( $validated ) ) {
			$layer['status']     = 'incompatible';
			$layer['warnings'][] = 'incompatible';
			return $layer;
		}
		$stored_fingerprint = sanitize_text_field( (string) ( $entry['fingerprint'] ?? '' ) );
		$current_fingerprint = sanitize_text_field( (string) ( $description['fingerprint'] ?? '' ) );
		$layer['values']      = $validated;
		$layer['fingerprint'] = $stored_fingerprint;
		$layer['status']      = $stored_fingerprint === $current_fingerprint ? 'current' : 'revalidated';
		if ( 'revalidated' === $layer['status'] ) {
			$layer['warnings'][] = 'revalidated';
		}

		return $layer;
	}

	/** Empty layer DTO with stable falsey values. */
	private static function empty_layer( int $post_id ): array {
		return [
			'post_id'     => $post_id,
			'values'      => [],
			'fingerprint' => '',
			'has_entry'   => false,
			'status'      => 'inherited',
			'warnings'    => [],
		];
	}

	/** Apply one layer while retaining the exact source for every field. */
	private static function overlay( array &$effective, array &$sources, array $values, string $source ): void {
		foreach ( $values as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$effective[ (string) $key ] = $value;
				$sources[ (string) $key ]   = $source;
			}
		}
	}

	/** Describe the explicit Template field separately from its resolved defaults. */
	private static function template_layer( int $template_id, array $description, array $values ): array {
		$layer = [
			'post_id'     => $template_id,
			'values'      => $values,
			'fingerprint' => sanitize_text_field( (string) ( $description['fingerprint'] ?? '' ) ),
			'has_entry'   => false,
			'status'      => 'current',
			'warnings'    => [],
		];
		$raw = worldgraph_get_field_value( $template_id, 'default_values' );
		if ( is_array( $raw ) ) {
			if ( empty( $raw ) ) {
				return $layer;
			}
			$layer['has_entry'] = true;
			if ( array_is_list( $raw ) ) {
				$layer['status']     = 'invalid_document';
				$layer['warnings'][] = 'invalid_document';
				return $layer;
			}
			$decoded = $raw;
		} elseif ( ! is_string( $raw ) || '' === trim( $raw ) || '{}' === trim( $raw ) ) {
			return $layer;
		} elseif ( strlen( $raw ) > Template_Run_Controls::MAX_CONFIGURATION_BYTES ) {
			$layer['has_entry']   = true;
			$layer['status']      = 'invalid_document';
			$layer['warnings'][]  = 'invalid_document';
			return $layer;
		} else {
			$object  = json_decode( $raw );
			$decoded = json_decode( $raw, true, 32 );
			$layer['has_entry'] = true;
			if ( ! $object instanceof \stdClass || ! is_array( $decoded ) ) {
				$layer['status']     = 'invalid_document';
				$layer['warnings'][] = 'invalid_document';
				return $layer;
			}
		}

		$submitted = isset( $decoded['parameters'] ) && is_array( $decoded['parameters'] )
			? $decoded['parameters']
			: $decoded;
		$validated = Template_Run_Controls::validate_description( $description, $submitted );
		if ( is_wp_error( $validated ) ) {
			$layer['status']     = 'incompatible';
			$layer['warnings'][] = 'incompatible';
		}

		return $layer;
	}

	/** Encode a flat, deterministically ordered Template defaults object. */
	private static function canonical_template_defaults( array $values ) {
		if ( ! self::scalar_values( $values ) ) {
			return false;
		}
		ksort( $values, SORT_STRING );
		if ( empty( $values ) ) {
			return '{}';
		}

		$encoded = wp_json_encode( $values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false !== $encoded && strlen( $encoded ) <= Template_Run_Controls::MAX_CONFIGURATION_BYTES
			? $encoded
			: false;
	}

	/** Persist the Template baseline and recheck its fingerprint before writing. */
	private static function persist_template_defaults( int $template_id, array $values, string $fingerprint ) {
		$current = (string) ( Template_Run_Controls::describe( $template_id )['fingerprint'] ?? '' );
		if ( '' === $fingerprint || '' === $current || ! hash_equals( $current, $fingerprint ) ) {
			return self::error( 'worldgraph_generation_default_fingerprint_stale', __( 'The Template controls changed. Refresh them before saving defaults.', 'worldgraph' ), 409 );
		}

		$encoded = self::canonical_template_defaults( $values );
		if ( false === $encoded ) {
			return self::error( 'worldgraph_generation_default_template_invalid', __( 'Template defaults must be a bounded object of validated scalar values.', 'worldgraph' ), 400 );
		}
		$before = (string) worldgraph_get_field_value( $template_id, 'default_values' );
		if ( $before === $encoded ) {
			return true;
		}

		$updated = worldgraph_update_field_value( $template_id, 'default_values', $encoded );
		$stored  = (string) worldgraph_get_field_value( $template_id, 'default_values' );
		if ( ! $updated || $stored !== $encoded ) {
			return self::error( 'worldgraph_generation_default_template_write_failed', __( 'Template defaults could not be saved.', 'worldgraph' ), 500 );
		}

		return true;
	}

	/**
	 * Load and strictly validate a complete document before mutating it.
	 *
	 * The snapshot is the exact metadata value used by the later atomic compare-
	 * and-swap. Keeping it separate from the canonicalized document prevents a
	 * concurrent request from being silently overwritten between read and write.
	 *
	 * An explicit reset may clear the entire metadata key when its document is
	 * unreadable. Selective pair removal is unsafe in that state, and retaining
	 * an invisible corrupt value would make Reset appear successful while it
	 * continued to affect later writes.
	 *
	 * @param int                      $post_id            Metadata owner.
	 * @param array<string,mixed>|null $snapshot           Exact pre-mutation state.
	 * @param bool                     $allow_invalid_reset Return an empty document and destructive reset snapshot when unreadable.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function document_for_write( int $post_id, ?array &$snapshot = null, bool $allow_invalid_reset = false ) {
		$stored = get_post_meta( $post_id, self::META_KEY, false );
		if ( ! is_array( $stored ) ) {
			return self::error( 'worldgraph_generation_default_document_invalid', __( 'Saved generation defaults have an unsupported format.', 'worldgraph' ), 409 );
		}

		$exists   = 1 === count( $stored );
		$raw      = $exists ? reset( $stored ) : '';
		$snapshot = [
			'exists'     => ! empty( $stored ),
			'value'      => $raw,
			'values'     => array_values( $stored ),
			'unreadable' => false,
		];
		if ( count( $stored ) > 1 ) {
			return self::invalid_document_for_reset( $snapshot, $allow_invalid_reset, 'worldgraph_generation_default_document_invalid', __( 'Saved generation defaults have an unsupported format.', 'worldgraph' ) );
		}
		if ( ! $exists ) {
			return [ 'version' => self::VERSION, 'entries' => [] ];
		}
		if ( ! is_array( $raw ) || self::VERSION !== (int) ( $raw['version'] ?? 0 ) || ! is_array( $raw['entries'] ?? null ) ) {
			return self::invalid_document_for_reset( $snapshot, $allow_invalid_reset, 'worldgraph_generation_default_document_invalid', __( 'Saved generation defaults have an unsupported format.', 'worldgraph' ) );
		}
		$raw_encoded = wp_json_encode( $raw );
		if ( false === $raw_encoded || strlen( $raw_encoded ) > self::MAX_BYTES ) {
			return self::invalid_document_for_reset( $snapshot, $allow_invalid_reset, 'worldgraph_generation_default_document_large', __( 'Saved generation defaults are too large to read safely.', 'worldgraph' ) );
		}
		if ( count( $raw['entries'] ) > self::MAX_ENTRIES ) {
			return self::invalid_document_for_reset( $snapshot, $allow_invalid_reset, 'worldgraph_generation_default_document_large', __( 'Too many generation default entries are stored on this item.', 'worldgraph' ) );
		}

		$entries = [];
		foreach ( $raw['entries'] as $key => $entry ) {
			if ( ! is_string( $key ) || ! is_array( $entry ) ) {
				return self::invalid_document_for_reset( $snapshot, $allow_invalid_reset, 'worldgraph_generation_default_document_invalid', __( 'Saved generation defaults contain an invalid entry.', 'worldgraph' ) );
			}
			$connection_id = absint( $entry['connection_id'] ?? 0 );
			$template_id   = absint( $entry['template_id'] ?? 0 );
			$fingerprint   = sanitize_text_field( (string) ( $entry['fingerprint'] ?? '' ) );
			$values        = $entry['values'] ?? null;
			if ( ! $connection_id || ! $template_id || self::pair_key( $connection_id, $template_id ) !== $key || ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) || ! self::scalar_values( $values ) ) {
				return self::invalid_document_for_reset( $snapshot, $allow_invalid_reset, 'worldgraph_generation_default_document_invalid', __( 'Saved generation defaults contain an invalid entry.', 'worldgraph' ) );
			}
			$entries[ $key ] = [
				'connection_id' => $connection_id,
				'template_id'   => $template_id,
				'fingerprint'   => $fingerprint,
				'values'        => $values,
			];
		}
		ksort( $entries, SORT_STRING );

		return [ 'version' => self::VERSION, 'entries' => $entries ];
	}

	/** Convert an unreadable document to an explicitly destructive reset plan. */
	private static function invalid_document_for_reset( array &$snapshot, bool $allow_invalid_reset, string $code, string $message ) {
		if ( ! $allow_invalid_reset ) {
			return self::error( $code, $message, 409 );
		}

		$snapshot['unreadable'] = true;
		return [ 'version' => self::VERSION, 'entries' => [] ];
	}

	/**
	 * Persist a bounded canonical document with an optimistic compare-and-swap.
	 *
	 * @param int                 $post_id  Metadata owner.
	 * @param array<string,mixed> $document Canonical post-mutation document.
	 * @param array<string,mixed> $snapshot Exact value read before mutation.
	 * @return true|WP_Error
	 */
	private static function persist_document( int $post_id, array $document, array $snapshot ) {
		if ( count( $document['entries'] ?? [] ) > self::MAX_ENTRIES ) {
			return self::error( 'worldgraph_generation_default_document_large', __( 'This item has too many generation default entries.', 'worldgraph' ), 400 );
		}
		ksort( $document['entries'], SORT_STRING );
		$encoded = wp_json_encode( $document );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_BYTES ) {
			return self::error( 'worldgraph_generation_default_document_large', __( 'These generation defaults are too large to store safely.', 'worldgraph' ), 400 );
		}

		if ( ! array_key_exists( 'exists', $snapshot ) || ! is_bool( $snapshot['exists'] ) || ! array_key_exists( 'value', $snapshot ) ) {
			return self::error( 'worldgraph_generation_default_conflict', __( 'These generation defaults changed in another editor. Refresh before saving again.', 'worldgraph' ), 409 );
		}
		if ( ! empty( $snapshot['unreadable'] ) ) {
			return self::clear_unreadable_document( $post_id, $document, $snapshot );
		}

		$existed = true === ( $snapshot['exists'] ?? false );
		$before  = $snapshot['value'] ?? '';
		if ( $existed && $before === $document ) {
			return true;
		}
		if ( empty( $document['entries'] ) ) {
			if ( ! $existed || delete_post_meta( $post_id, self::META_KEY, $before ) ) {
				return true;
			}
			if ( [] === get_post_meta( $post_id, self::META_KEY, false ) ) {
				return true;
			}
		} elseif ( ! $existed ) {
			if ( add_post_meta( $post_id, self::META_KEY, $document, true ) ) {
				return true;
			}
			if ( $document === get_post_meta( $post_id, self::META_KEY, true ) ) {
				return true;
			}
		} elseif ( update_post_meta( $post_id, self::META_KEY, $document, $before ) ) {
			return true;
		} elseif ( $document === get_post_meta( $post_id, self::META_KEY, true ) ) {
			return true;
		}

		return self::error( 'worldgraph_generation_default_conflict', __( 'These generation defaults changed in another editor. Refresh before saving again.', 'worldgraph' ), 409 );
	}

	/** Clear only the exact unreadable values observed by an explicit reset. */
	private static function clear_unreadable_document( int $post_id, array $document, array $snapshot ) {
		if ( ! empty( $document['entries'] ) || ! is_array( $snapshot['values'] ?? null ) ) {
			return self::error( 'worldgraph_generation_default_conflict', __( 'These generation defaults changed in another editor. Refresh before saving again.', 'worldgraph' ), 409 );
		}
		$before = array_values( $snapshot['values'] );
		if ( $before !== array_values( get_post_meta( $post_id, self::META_KEY, false ) ) ) {
			return self::error( 'worldgraph_generation_default_conflict', __( 'These generation defaults changed in another editor. Refresh before saving again.', 'worldgraph' ), 409 );
		}

		foreach ( $before as $value ) {
			if ( '' === $value || null === $value || false === $value ) {
				delete_post_meta( $post_id, self::META_KEY );
				break;
			}
			delete_post_meta( $post_id, self::META_KEY, $value );
		}
		if ( [] === get_post_meta( $post_id, self::META_KEY, false ) ) {
			return true;
		}

		return self::error( 'worldgraph_generation_default_conflict', __( 'These generation defaults changed in another editor. Refresh before saving again.', 'worldgraph' ), 409 );
	}

	/** Values in a stored row must remain scalar and bounded by the control cap. */
	private static function scalar_values( $values ): bool {
		if ( ! is_array( $values ) || count( $values ) > Template_Run_Controls::MAX_FIELDS ) {
			return false;
		}
		foreach ( $values as $key => $value ) {
			if ( ! is_string( $key ) || ! is_scalar( $value ) ) {
				return false;
			}
		}
		return true;
	}

	/** Derive Connection identity from the server-owned Template. */
	private static function connection_id( int $template_id ): int {
		return absint( worldgraph_get_field_value( $template_id, 'connection_id' ) );
	}

	/** Compare normalized scalars while preserving false and zero semantics. */
	private static function same_value( $left, $right ): bool {
		return $left === $right || ( is_numeric( $left ) && is_numeric( $right ) && (float) $left === (float) $right );
	}

	/** Build a REST-friendly stable error. */
	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}
}
