<?php
/**
 * Reviewed Higgsfield REST operation Template provisioning.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

use WP_Error;
use WorldGraph\Templates\Template_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/templates/class-template-repository.php';

/** Materializes a conservative, reviewed Higgsfield API catalog. */
class Higgsfield_Catalog {

	/** Provider OpenAPI version reviewed for these local schemas. */
	const SCHEMA_VERSION = '2.0.0';

	/**
	 * Create or update each explicitly allowed Higgsfield REST Template.
	 *
	 * MCP discovery is validated separately. Higgsfield does not publish stable
	 * MCP tool contracts, so runtime tool data is never converted into an
	 * executable Template by this catalog.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function provision( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if (
			! is_array( $connection )
			|| 'higgsfield' !== ( $connection['provider_type'] ?? '' )
			|| 'publish' !== ( $connection['status_wp'] ?? '' )
			|| 'disabled' === ( $connection['status'] ?? '' )
		) {
			return new WP_Error( 'higgsfield_catalog_connection_invalid', __( 'Template provisioning requires a published, enabled Higgsfield Connection.', 'worldgraph' ) );
		}
		if ( '' === trim( (string) ( $connection['credential_reference'] ?? '' ) ) ) {
			return new WP_Error( 'higgsfield_catalog_credential_missing', __( 'Add the Higgsfield REST API key ID and secret before provisioning Templates.', 'worldgraph' ) );
		}

		$definitions = self::selected_definitions( (string) ( $connection['model_access'] ?? '' ) );
		if ( is_wp_error( $definitions ) ) {
			return $definitions;
		}

		$template_ids = [];
		foreach ( $definitions as $definition ) {
			$template_id = Template_Repository::upsert_provider_template(
				$connection_id,
				[
					'provider_type'        => 'higgsfield',
					'provider_template_id' => (string) $definition['reference'],
					'template_name'        => (string) $definition['name'],
					'description'          => (string) $definition['description'],
					'modality'             => (string) $definition['modality'],
					'input'                => (array) $definition['input'],
					'provider_schema'      => (array) $definition['schema'],
					'configuration'        => [
						'transport'         => 'api',
						'schema_provenance' => 'Higgsfield OpenAPI ' . self::SCHEMA_VERSION . ' plus reviewed narrative guides',
					],
					'status'               => 'active',
					'version'              => self::SCHEMA_VERSION,
				]
			);
			if ( is_wp_error( $template_id ) ) {
				return $template_id;
			}
			$template_ids[] = $template_id;
		}

		return [
			'connection_id' => $connection_id,
			'template_ids'  => $template_ids,
			'transports'    => [ 'api' ],
			'mcp_catalog'   => 'runtime_discovery_only',
		];
	}

	/**
	 * Reviewed fixed operation definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function definitions(): array {
		return [
			[
				'reference'   => 'api:higgsfield-ai/soul/standard',
				'name'        => 'Higgsfield Soul — Text to Image',
				'description' => 'Generate one or more Soul still images from a text prompt through Higgsfield REST.',
				'modality'    => Generation_Modality::TEXT_TO_IMAGE,
				'input'       => [
					'num_images'   => 1,
					'resolution'   => '2K',
					'aspect_ratio' => '4:3',
				],
				'schema'      => [
					'$schema'    => 'https://json-schema.org/draft/2020-12/schema',
					'title'      => 'Higgsfield Soul Standard',
					'endpoint'   => 'POST /higgsfield-ai/soul/standard',
					'type'       => 'object',
					'properties' => [
						'prompt'       => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 10000 ],
						'num_images'   => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'default' => 1 ],
						'resolution'   => [ 'type' => 'string', 'enum' => [ '2K', '4K' ], 'default' => '2K' ],
						'aspect_ratio' => [ 'type' => 'string', 'enum' => [ '1:1', '4:3', '3:4', '3:2', '2:3', '5:4', '4:5', '16:9', '9:16', '21:9' ], 'default' => '4:3' ],
					],
					'required'   => [ 'prompt' ],
				],
			],
			[
				'reference'   => 'api:higgsfield-ai/dop/standard',
				'name'        => 'Higgsfield DoP — Image to Video',
				'description' => 'Animate a source image with a text prompt through the Higgsfield DoP standard REST operation.',
				'modality'    => Generation_Modality::TEXT_IMAGE_TO_VIDEO,
				'input'       => [
					'enhance_prompt' => true,
				],
				'schema'      => [
					'$schema'    => 'https://json-schema.org/draft/2020-12/schema',
					'title'      => 'Higgsfield DoP Standard',
					'endpoint'   => 'POST /higgsfield-ai/dop/standard',
					'type'       => 'object',
					'properties' => [
						'prompt'         => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 10000 ],
						'image_url'      => [ 'type' => 'string', 'format' => 'uri', 'managed_by' => 'worldgraph', 'input_slot' => 'image' ],
						'end_image_url'  => [ 'type' => 'string', 'format' => 'uri', 'managed_by' => 'worldgraph', 'input_slot' => 'end_frame' ],
						'seed'           => [ 'type' => [ 'integer', 'null' ], 'minimum' => 1, 'maximum' => 1000000, 'default' => null ],
						'enhance_prompt' => [ 'type' => 'boolean', 'default' => true ],
					],
					'required'   => [ 'prompt', 'image_url' ],
				],
			],
			[
				'reference'   => 'api:kling-video/v2.1/pro/image-to-video',
				'name'        => 'Higgsfield Kling 2.1 Pro — Image to Video',
				'description' => 'Animate a source image with Kling Video 2.1 Pro through Higgsfield REST.',
				'modality'    => Generation_Modality::TEXT_IMAGE_TO_VIDEO,
				'input'       => [
					'duration'        => 5,
					'cfg_scale'       => 0.5,
					'negative_prompt' => '',
				],
				'schema'      => [
					'$schema'    => 'https://json-schema.org/draft/2020-12/schema',
					'title'      => 'Higgsfield Kling Video 2.1 Pro Image to Video',
					'endpoint'   => 'POST /kling-video/v2.1/pro/image-to-video',
					'type'       => 'object',
					'properties' => [
						'prompt'          => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 10000 ],
						'image_url'       => [ 'type' => 'string', 'format' => 'uri', 'managed_by' => 'worldgraph', 'input_slot' => 'image' ],
						'duration'        => [ 'type' => 'integer', 'enum' => [ 5, 10 ], 'default' => 5 ],
						'cfg_scale'       => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1, 'multipleOf' => 0.01, 'default' => 0.5 ],
						'negative_prompt' => [ 'type' => 'string', 'default' => '', 'maxLength' => 5000 ],
					],
					'required'   => [ 'prompt', 'image_url' ],
				],
			],
		];
	}

	/** Apply an optional exact operation-reference allowlist. */
	private static function selected_definitions( string $raw_allowlist ) {
		$definitions = self::definitions();
		$raw_allowlist = trim( $raw_allowlist );
		if ( '' === $raw_allowlist ) {
			return $definitions;
		}

		$allowed = json_decode( $raw_allowlist, true );
		if ( ! is_array( $allowed ) || $allowed !== array_values( $allowed ) ) {
			return new WP_Error( 'higgsfield_catalog_allowlist_invalid', __( 'Higgsfield Model Access must be a JSON array of exact API operation references.', 'worldgraph' ) );
		}
		if ( count( $allowed ) !== count( array_filter( $allowed, 'is_string' ) ) ) {
			return new WP_Error( 'higgsfield_catalog_allowlist_invalid', __( 'Higgsfield Model Access entries must be exact API operation-reference strings.', 'worldgraph' ) );
		}
		$allowed = array_values( array_unique( $allowed ) );
		$known   = array_column( $definitions, 'reference' );
		if ( ! empty( array_diff( $allowed, $known ) ) ) {
			return new WP_Error( 'higgsfield_catalog_allowlist_invalid', __( 'Higgsfield Model Access contains an operation that this adapter has not reviewed.', 'worldgraph' ) );
		}
		$selected = array_values(
			array_filter(
				$definitions,
				static function ( array $definition ) use ( $allowed ): bool {
					return in_array( (string) $definition['reference'], $allowed, true );
				}
			)
		);
		if ( empty( $selected ) ) {
			return new WP_Error( 'higgsfield_catalog_allowlist_empty', __( 'The Higgsfield Model Access allowlist contains no reviewed operation references.', 'worldgraph' ) );
		}

		return $selected;
	}
}
