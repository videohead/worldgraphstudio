<?php
/**
 * Persistence for provider-managed generation Templates.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Templates;

use WP_Error;
use WorldGraph\Utils\Connection_Repository;
use WorldGraph\Utils\Generation_Modality;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralizes the idempotent provider-Template persistence contract.
 */
class Template_Repository {

	/** Supported values for the Template's application-level status. */
	const STATUSES = [ 'draft', 'active', 'archived' ];

	/**
	 * Create or update one provider-managed Template.
	 *
	 * The stable identity is the owning Connection plus the provider's operation,
	 * model, or tool identifier. Existing configuration extras and every Template
	 * field omitted from the definition are preserved.
	 *
	 * Required definition keys are `provider_type`, `provider_template_id`,
	 * `template_name`, and `modality`. Optional keys are `description`, `input`,
	 * `provider_schema`, `configuration`, `status`, and `version`. A new record
	 * defaults to application status `active` when status is omitted; an update
	 * preserves the existing status when omitted.
	 *
	 * @param int   $connection_id Owning worldgraph_conn post ID.
	 * @param array $definition    Normalized provider Template definition.
	 * @return int|WP_Error Template post ID on success.
	 */
	public static function upsert_provider_template( int $connection_id, array $definition ) {
		$connection = Connection_Repository::get( $connection_id );
		if (
			! is_array( $connection )
			|| 'publish' !== (string) ( $connection['status_wp'] ?? '' )
			|| 'disabled' === (string) ( $connection['status'] ?? '' )
		) {
			return new WP_Error(
				'worldgraph_template_connection_invalid',
				__( 'Provider Template provisioning requires a published, enabled Connection.', 'worldgraph' )
			);
		}

		$connection_provider = (string) ( $connection['provider_type'] ?? '' );
		$definition_provider = (string) ( $definition['provider_type'] ?? '' );
		$provider_type       = sanitize_key( $definition_provider );
		if ( '' === $provider_type || $definition_provider !== $connection_provider || $provider_type !== $definition_provider ) {
			return new WP_Error(
				'worldgraph_template_provider_mismatch',
				__( 'The provider Template definition must use the owning Connection provider.', 'worldgraph' )
			);
		}

		$provider_template_id = sanitize_text_field( (string) ( $definition['provider_template_id'] ?? '' ) );
		if ( '' === $provider_template_id ) {
			return new WP_Error(
				'worldgraph_template_reference_missing',
				__( 'The provider Template definition requires a stable provider template ID.', 'worldgraph' )
			);
		}

		$template_name = sanitize_text_field( (string) ( $definition['template_name'] ?? '' ) );
		if ( '' === $template_name ) {
			return new WP_Error(
				'worldgraph_template_name_missing',
				__( 'The provider Template definition requires a name.', 'worldgraph' )
			);
		}

		$modality = sanitize_key( (string) ( $definition['modality'] ?? '' ) );
		if ( '' === $modality || ! Generation_Modality::has( $modality ) ) {
			return new WP_Error(
				'worldgraph_template_modality_invalid',
				__( 'The provider Template definition must use a registered generation modality.', 'worldgraph' )
			);
		}

		foreach ( [ 'input', 'provider_schema', 'configuration' ] as $array_key ) {
			if ( array_key_exists( $array_key, $definition ) && ! is_array( $definition[ $array_key ] ) ) {
				return new WP_Error(
					'worldgraph_template_configuration_invalid',
					__( 'Provider Template input, schema, and extra configuration values must be JSON objects.', 'worldgraph' )
				);
			}
		}

		$status = null;
		if ( array_key_exists( 'status', $definition ) ) {
			$status = sanitize_key( (string) $definition['status'] );
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				return new WP_Error(
					'worldgraph_template_status_invalid',
					__( 'The provider Template status must be draft, active, or archived.', 'worldgraph' )
				);
			}
		}

		$existing = get_posts(
			[
				'post_type'      => 'worldgraph_template',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[ 'key' => 'connection_id', 'value' => (string) $connection_id ],
					[ 'key' => 'provider_template_id', 'value' => $provider_template_id ],
				],
			]
		);
		$template_id = $existing ? (int) $existing[0] : 0;
		$current_configuration = $template_id
			? json_decode(
				(string) \WorldGraph\Utils\worldgraph_get_field_value( $template_id, 'configuration_json' ),
				true
			)
			: [];
		$current_configuration = is_array( $current_configuration ) ? $current_configuration : [];
		$configuration_extras  = [];
		foreach ( $current_configuration as $key => $value ) {
			if ( ! in_array( (string) $key, [ 'input', 'provider_schema' ], true ) ) {
				$configuration_extras[ (string) $key ] = $value;
			}
		}
		foreach ( (array) ( $definition['configuration'] ?? [] ) as $key => $value ) {
			if ( ! in_array( (string) $key, [ 'input', 'provider_schema' ], true ) ) {
				$configuration_extras[ (string) $key ] = $value;
			}
		}
		$configuration = [
			'input'           => array_key_exists( 'input', $definition )
				? $definition['input']
				: ( is_array( $current_configuration['input'] ?? null ) ? $current_configuration['input'] : [] ),
			'provider_schema' => array_key_exists( 'provider_schema', $definition )
				? $definition['provider_schema']
				: ( is_array( $current_configuration['provider_schema'] ?? null ) ? $current_configuration['provider_schema'] : [] ),
		];
		foreach ( $configuration_extras as $key => $value ) {
			$configuration[ $key ] = $value;
		}
		$configuration_json = wp_json_encode( $configuration );
		if ( ! is_string( $configuration_json ) ) {
			return new WP_Error(
				'worldgraph_template_configuration_invalid',
				__( 'The provider Template configuration could not be encoded as JSON.', 'worldgraph' )
			);
		}

		$post = [
			'post_type'   => 'worldgraph_template',
			'post_title'  => $template_name,
			'post_status' => 'publish',
		];
		if ( array_key_exists( 'description', $definition ) ) {
			$post['post_content'] = wp_kses_post( (string) $definition['description'] );
		}

		if ( $template_id ) {
			$post['ID']  = $template_id;
			$template_id = wp_update_post( $post, true );
		} else {
			$template_id = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $template_id ) || ! $template_id ) {
			return new WP_Error(
				'worldgraph_template_write_failed',
				__( 'World Graph Studio could not save the provider Template.', 'worldgraph' )
			);
		}

		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'template_name', $template_name );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'provider_type', $provider_type );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'connection_id', (string) $connection_id );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'provider_template_id', $provider_template_id );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'modality', $modality );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'generation_structure', Generation_Modality::output_type( $modality ) );
		\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'configuration_json', $configuration_json );

		if ( array_key_exists( 'description', $definition ) ) {
			\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'description', wp_kses_post( (string) $definition['description'] ) );
		}
		if ( null !== $status ) {
			\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'status', $status );
		} elseif ( ! $existing ) {
			\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'status', 'active' );
		}
		if ( array_key_exists( 'version', $definition ) ) {
			\WorldGraph\Utils\worldgraph_update_field_value( (int) $template_id, 'version', sanitize_text_field( (string) $definition['version'] ) );
		}

		return (int) $template_id;
	}
}
