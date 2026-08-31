<?php
/**
 * Reviewed Seedance 2.5 via CyberBara Template provisioning.
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

/** Materializes the reviewed CyberBara Seedance 2.5 REST operations. */
class Seedance_25_Catalog {

	/** Version of the reviewed local Template contracts. */
	const SCHEMA_VERSION = '1.0.0';

	/**
	 * Create or update each Seedance operation allowed by the Connection.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function provision( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if (
			! is_array( $connection )
			|| 'seedance_25' !== (string) ( $connection['provider_type'] ?? '' )
			|| 'publish' !== (string) ( $connection['status_wp'] ?? '' )
			|| 'disabled' === (string) ( $connection['status'] ?? '' )
		) {
			return new WP_Error(
				'seedance_25_catalog_connection_invalid',
				__( 'Template provisioning requires a published, enabled Seedance 2.5 via CyberBara Connection.', 'worldgraph' )
			);
		}
		if ( '' === trim( (string) ( $connection['credential_reference'] ?? '' ) ) ) {
			return new WP_Error(
				'seedance_25_catalog_credential_missing',
				__( 'Add a CyberBara API key before provisioning Seedance 2.5 Templates.', 'worldgraph' )
			);
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
					'provider_type'        => 'seedance_25',
					'provider_template_id' => (string) $definition['reference'],
					'template_name'        => (string) $definition['name'],
					'description'          => (string) $definition['description'],
					'modality'             => (string) $definition['modality'],
					'input'                => (array) $definition['input'],
					'provider_schema'      => (array) $definition['schema'],
					'configuration'        => [
						'transport'         => 'api',
						'schema_provenance' => 'CyberBara Seedance 2.5 README and SDK contract reviewed 2026-08-30',
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
		];
	}

	/**
	 * Return the fixed, reviewed Seedance 2.5 operation definitions.
	 *
	 * Model and scene are server-owned by the matching API operation reference;
	 * only the bounded generation options below are exposed as run controls.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function definitions(): array {
		$common_input = [
			'duration'     => 10,
			'resolution'   => '720p',
			'aspect_ratio' => '16:9',
		];
		$common_properties = [
			'prompt'       => [
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 10000,
			],
			'duration'     => [
				'type'    => 'integer',
				'minimum' => 4,
				'maximum' => 30,
				'default' => 10,
			],
			'resolution'   => [
				'type'    => 'string',
				'enum'    => [ '480p', '720p' ],
				'default' => '720p',
			],
			'aspect_ratio' => [
				'type'    => 'string',
				'enum'    => [ '21:9', '16:9', '4:3', '1:1', '3:4', '9:16' ],
				'default' => '16:9',
			],
		];

		return [
			[
				'reference'   => 'seedance-2.5:text-to-video',
				'name'        => 'Seedance 2.5 via CyberBara — Text to Video',
				'description' => 'Generate a Seedance 2.5 video from a text prompt through the CyberBara asynchronous REST API.',
				'modality'    => Generation_Modality::TEXT_TO_VIDEO,
				'input'       => $common_input,
				'schema'      => [
					'$schema'    => 'https://json-schema.org/draft/2020-12/schema',
					'title'      => 'Seedance 2.5 Text to Video via CyberBara',
					'endpoint'   => 'POST /api/v1/videos/generations',
					'type'       => 'object',
					'properties' => $common_properties,
					'required'   => [ 'prompt' ],
				],
			],
			[
				'reference'   => 'seedance-2.5:image-to-video',
				'name'        => 'Seedance 2.5 via CyberBara — Image to Video',
				'description' => 'Animate one authorized reference image with Seedance 2.5 through the CyberBara asynchronous REST API.',
				'modality'    => Generation_Modality::TEXT_IMAGE_TO_VIDEO,
				'input'       => $common_input,
				'schema'      => [
					'$schema'    => 'https://json-schema.org/draft/2020-12/schema',
					'title'      => 'Seedance 2.5 Image to Video via CyberBara',
					'endpoint'   => 'POST /api/v1/videos/generations',
					'type'       => 'object',
					'properties' => array_merge(
						$common_properties,
						[
							'image_input' => [
								'type'       => 'array',
								'minItems'   => 1,
								'maxItems'   => 1,
								'items'      => [ 'type' => 'string', 'format' => 'uri' ],
								'managed_by' => 'worldgraph',
								'input_slot' => 'image',
							],
						]
					),
					'required'   => [ 'prompt', 'image_input' ],
				],
			],
		];
	}

	/** Apply an optional exact operation-reference allowlist. */
	private static function selected_definitions( string $raw_allowlist ) {
		$definitions   = self::definitions();
		$raw_allowlist = trim( $raw_allowlist );
		if ( '' === $raw_allowlist ) {
			return $definitions;
		}

		$allowed = json_decode( $raw_allowlist, true );
		if ( ! is_array( $allowed ) || $allowed !== array_values( $allowed ) ) {
			return new WP_Error(
				'seedance_25_catalog_allowlist_invalid',
				__( 'Seedance 2.5 Model Access must be a JSON array of exact operation references.', 'worldgraph' )
			);
		}
		if ( count( $allowed ) !== count( array_filter( $allowed, 'is_string' ) ) ) {
			return new WP_Error(
				'seedance_25_catalog_allowlist_invalid',
				__( 'Seedance 2.5 Model Access entries must be exact operation-reference strings.', 'worldgraph' )
			);
		}

		$allowed = array_values( array_unique( $allowed ) );
		$known   = array_column( $definitions, 'reference' );
		if ( ! empty( array_diff( $allowed, $known ) ) ) {
			return new WP_Error(
				'seedance_25_catalog_allowlist_invalid',
				__( 'Seedance 2.5 Model Access contains an operation that this adapter has not reviewed.', 'worldgraph' )
			);
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
			return new WP_Error(
				'seedance_25_catalog_allowlist_empty',
				__( 'Seedance 2.5 Model Access contains no reviewed operation references.', 'worldgraph' )
			);
		}

		return $selected;
	}
}
