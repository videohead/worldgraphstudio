<?php
/**
 * Reviewed MidJourney REST and MCP Template provisioning.
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

/** Materializes transport-specific MidJourney text-to-image Templates. */
class Midjourney_Catalog {

	/** Version of the reviewed, local Template contracts. */
	const SCHEMA_VERSION = '1.0.0';

	/** AceData Cloud provider source revision reviewed for the MCP contract. */
	const MCP_SOURCE_REVISION = '112c9113e946298c70d8117b0a3bd20857055940';

	/** Maximum encoded size accepted for one discovered MCP input schema. */
	private const MAX_LIVE_SCHEMA_BYTES = 32768;

	/** Maximum number of properties accepted in one discovered input schema. */
	private const MAX_LIVE_PROPERTIES = 32;

	/** Maximum length retained from a discovered label or description. */
	private const MAX_LIVE_TEXT_LENGTH = 500;

	/** The only discovered MCP generation tool this catalog may materialize. */
	private const MCP_IMAGINE_TOOL = 'midjourney_imagine';

	/** Parameters the reviewed MCP adapter permits for the imagine tool. */
	private const MCP_PARAMETER_ALLOWLIST = [
		'prompt',
		'mode',
		'translation',
		'split_images',
		'timeout',
	];

	/**
	 * Create or update Templates for each independently configured transport.
	 *
	 * A failed MCP discovery pass does not make an otherwise executable REST
	 * Template disappear. In that case REST provisioning completes and the
	 * result carries an operator-visible warning; an MCP-only Connection fails.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function provision( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if (
			! is_array( $connection )
			|| 'midjourney' !== (string) ( $connection['provider_type'] ?? '' )
			|| 'publish' !== (string) ( $connection['status_wp'] ?? '' )
			|| 'disabled' === (string) ( $connection['status'] ?? '' )
		) {
			return new WP_Error(
				'midjourney_catalog_connection_invalid',
				__( 'Template provisioning requires a published, enabled MidJourney Connection.', 'worldgraph' )
			);
		}

		$api_credential = trim( (string) ( $connection['credential_reference'] ?? '' ) );
		$mcp_credential = trim( (string) ( $connection['mcp_credential_reference'] ?? '' ) );
		if ( '' === $api_credential && '' === $mcp_credential ) {
			return new WP_Error(
				'midjourney_catalog_credentials_missing',
				__( 'Add a midjourney-api.com credential, an AceData Cloud MCP credential, or both before provisioning Templates.', 'worldgraph' )
			);
		}

		$selected = self::selected_definitions( $connection['model_access'] ?? '' );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}
		$api_definitions = array_values(
			array_filter(
				$selected,
				static function ( array $definition ): bool {
					return 'api' === ( $definition['transport'] ?? '' );
				}
			)
		);
		$mcp_definitions = array_values(
			array_filter(
				$selected,
				static function ( array $definition ): bool {
					return 'mcp' === ( $definition['transport'] ?? '' );
				}
			)
		);

		$definitions = [];
		$transports  = [];
		$mcp_error   = null;

		if ( '' !== $api_credential && ! empty( $api_definitions ) ) {
			$definitions = $api_definitions;
			$transports[] = 'api';
		}

		if ( '' !== $mcp_credential && ! empty( $mcp_definitions ) ) {
			$live_schemas = Midjourney_MCP::tool_schemas( $connection_id );
			if ( is_wp_error( $live_schemas ) ) {
				$mcp_error = $live_schemas;
			} elseif ( ! is_array( $live_schemas ) ) {
				$mcp_error = new WP_Error(
					'midjourney_mcp_catalog_schema_invalid',
					__( 'MidJourney MCP returned an invalid tool catalog.', 'worldgraph' )
				);
			} else {
				$missing_tools = array_values(
					array_filter(
						Midjourney_MCP::REQUIRED_TOOLS,
						static function ( string $tool ) use ( $live_schemas ): bool {
							return ! isset( $live_schemas[ $tool ] ) || ! is_array( $live_schemas[ $tool ] );
						}
					)
				);
				if ( ! empty( $missing_tools ) ) {
					$mcp_error = new WP_Error(
						'midjourney_mcp_catalog_incomplete',
						sprintf(
							/* translators: %s: comma-separated MCP tool names. */
							__( 'MidJourney MCP did not advertise expected tools: %s.', 'worldgraph' ),
							implode( ', ', array_map( 'sanitize_key', $missing_tools ) )
						)
					);
				} else {
					$mcp_definition = self::merge_live_imagine_schema(
						$mcp_definitions[0],
						$live_schemas[ self::MCP_IMAGINE_TOOL ]
					);
					if ( is_wp_error( $mcp_definition ) ) {
						$mcp_error = $mcp_definition;
					} else {
						$definitions[] = $mcp_definition;
						$transports[]  = 'mcp';
					}
				}
			}
		}

		if ( is_wp_error( $mcp_error ) && empty( $definitions ) ) {
			return $mcp_error;
		}
		if ( empty( $definitions ) ) {
			return new WP_Error(
				'midjourney_catalog_credentials_missing',
				__( 'Add a credential for at least one MidJourney transport allowed by Model Access.', 'worldgraph' )
			);
		}

		$template_ids = [];
		foreach ( $definitions as $definition ) {
			$template_id = self::materialize( $connection_id, $definition );
			if ( is_wp_error( $template_id ) ) {
				return $template_id;
			}
			$template_ids[] = $template_id;
		}

		$result = [
			'connection_id' => $connection_id,
			'template_ids'  => $template_ids,
			'transports'    => $transports,
		];
		if ( is_wp_error( $mcp_error ) ) {
			$result['warning'] = sprintf(
				/* translators: %s: sanitized MCP provisioning error. */
				__( 'The REST Template is ready, but the MidJourney MCP Template was not provisioned: %s', 'worldgraph' ),
				sanitize_text_field( $mcp_error->get_error_message() )
			);
		}

		return $result;
	}

	/**
	 * Return reviewed definitions for one or both transports.
	 *
	 * @param string $transport api, mcp, or an empty string for both.
	 * @return array<int, array<string, mixed>>
	 */
	public static function definitions( string $transport = '' ): array {
		$transport = sanitize_key( $transport );
		if ( 'api' === $transport ) {
			return self::api_definitions();
		}
		if ( 'mcp' === $transport ) {
			return self::mcp_definitions();
		}

		return array_merge( self::api_definitions(), self::mcp_definitions() );
	}

	/** Documented midjourney-api.com REST operation. */
	private static function api_definitions(): array {
		return [
			[
				'reference'   => Midjourney_API::TEMPLATE,
				'name'        => 'MidJourney API — Imagine',
				'description' => 'Generate an image from a text prompt through the midjourney-api.com REST service.',
				'transport'   => 'api',
				'modality'    => Generation_Modality::TEXT_TO_IMAGE,
				'input'       => [
					'mode'    => 'fast',
					'timeout' => 600,
				],
				'schema'      => [
					'$schema'              => 'https://json-schema.org/draft/2020-12/schema',
					'title'                => 'MidJourney API Imagine',
					'endpoint'             => 'POST /midjourney/v1/submit-jobs',
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'prompt'  => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => Midjourney_API::MAX_PROMPT_BYTES ],
						'mode'    => [ 'type' => 'string', 'enum' => [ 'fast', 'relaxed' ], 'default' => 'fast' ],
						'timeout' => [ 'type' => 'integer', 'minimum' => 300, 'maximum' => 1200, 'default' => 600 ],
					],
					'required'             => [ 'prompt' ],
				],
				'version'     => self::SCHEMA_VERSION,
				'provenance'  => 'midjourney-api.com API reference reviewed 2026-08-25',
			],
		];
	}

	/** Reviewed AceData Cloud MCP imagine tool. */
	private static function mcp_definitions(): array {
		return [
			[
				'reference'   => Midjourney_MCP::TEMPLATE,
				'tool'        => self::MCP_IMAGINE_TOOL,
				'name'        => 'MidJourney MCP — Imagine',
				'description' => 'Generate an image from a text prompt through the hosted AceData Cloud MidJourney MCP server.',
				'transport'   => 'mcp',
				'modality'    => Generation_Modality::TEXT_TO_IMAGE,
				'input'       => [
					'mode'         => 'fast',
					'translation'  => false,
					'split_images' => true,
					'timeout'      => Midjourney_MCP::DEFAULT_GENERATION_TIMEOUT,
				],
				'schema'      => [
					'$schema'              => 'https://json-schema.org/draft/2020-12/schema',
					'title'                => self::MCP_IMAGINE_TOOL,
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'prompt'       => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => Midjourney_MCP::MAX_PROMPT_BYTES ],
						'mode'         => [ 'type' => 'string', 'enum' => [ 'fast', 'relax', 'turbo' ], 'default' => 'fast' ],
						'translation'  => [ 'type' => 'boolean', 'default' => false ],
						'split_images' => [ 'type' => 'boolean', 'default' => true ],
						'timeout'      => [
							'type'    => 'integer',
							'minimum' => Midjourney_MCP::MIN_GENERATION_TIMEOUT,
							'maximum' => Midjourney_MCP::MAX_GENERATION_TIMEOUT,
							'default' => Midjourney_MCP::DEFAULT_GENERATION_TIMEOUT,
						],
					],
					'required'             => [ 'prompt' ],
				],
				'version'     => self::SCHEMA_VERSION,
				'provenance'  => sprintf(
					'AceData Cloud MidJourney MCP source revision %s reviewed 2026-08-25, plus bounded live tools/list inputSchema',
					self::MCP_SOURCE_REVISION
				),
			],
		];
	}

	/** Apply an optional exact transport-operation allowlist. */
	private static function selected_definitions( $raw_allowlist ) {
		$definitions = self::definitions();
		if ( is_array( $raw_allowlist ) ) {
			$allowed = $raw_allowlist;
		} else {
			$raw_allowlist = trim( (string) $raw_allowlist );
			if ( '' === $raw_allowlist ) {
				return $definitions;
			}
			if ( strlen( $raw_allowlist ) > Midjourney_API::MAX_RESPONSE_BYTES ) {
				return new WP_Error( 'midjourney_catalog_allowlist_invalid', __( 'MidJourney Model Access is too large.', 'worldgraph' ) );
			}
			$allowed = json_decode( $raw_allowlist, true );
		}

		if ( ! is_array( $allowed ) || $allowed !== array_values( $allowed ) || count( $allowed ) > 100 ) {
			return new WP_Error( 'midjourney_catalog_allowlist_invalid', __( 'MidJourney Model Access must be a JSON array of exact transport-operation references.', 'worldgraph' ) );
		}
		if ( count( $allowed ) !== count( array_filter( $allowed, 'is_string' ) ) ) {
			return new WP_Error( 'midjourney_catalog_allowlist_invalid', __( 'MidJourney Model Access entries must be exact transport-operation strings.', 'worldgraph' ) );
		}

		$allowed = array_values( array_unique( $allowed ) );
		$known   = array_column( $definitions, 'reference' );
		if ( ! empty( array_diff( $allowed, $known ) ) ) {
			return new WP_Error( 'midjourney_catalog_allowlist_invalid', __( 'MidJourney Model Access contains an operation that this adapter has not reviewed.', 'worldgraph' ) );
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
			return new WP_Error( 'midjourney_catalog_allowlist_empty', __( 'MidJourney Model Access contains no reviewed transport-operation references.', 'worldgraph' ) );
		}

		return $selected;
	}

	/**
	 * Add bounded, non-executable metadata from the allowlisted live tool schema.
	 *
	 * Static reviewed types, enums, defaults, and request bounds remain
	 * authoritative. Discovery may refine labels and descriptions, but it cannot
	 * add a tool or parameter to the execution allowlist.
	 *
	 * @param array<string, mixed> $definition Reviewed fallback definition.
	 * @param array<string, mixed> $live_tool  Discovered imagine tool.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function merge_live_imagine_schema( array $definition, array $live_tool ) {
		$live_name = sanitize_key( (string) ( $live_tool['name'] ?? self::MCP_IMAGINE_TOOL ) );
		if ( self::MCP_IMAGINE_TOOL !== $live_name ) {
			return new WP_Error(
				'midjourney_mcp_catalog_schema_invalid',
				__( 'MidJourney MCP returned a schema under the wrong tool name.', 'worldgraph' )
			);
		}

		$live_schema = $live_tool['inputSchema'] ?? null;
		if ( ! is_array( $live_schema ) ) {
			return new WP_Error(
				'midjourney_mcp_catalog_schema_invalid',
				__( 'MidJourney MCP did not provide a usable imagine input schema.', 'worldgraph' )
			);
		}

		$encoded_schema = wp_json_encode( $live_schema );
		if ( ! is_string( $encoded_schema ) || strlen( $encoded_schema ) > self::MAX_LIVE_SCHEMA_BYTES ) {
			return new WP_Error(
				'midjourney_mcp_catalog_schema_invalid',
				__( 'MidJourney MCP returned an imagine input schema that exceeds the supported size.', 'worldgraph' )
			);
		}

		if ( isset( $live_schema['type'] ) && 'object' !== $live_schema['type'] ) {
			return new WP_Error(
				'midjourney_mcp_catalog_schema_invalid',
				__( 'MidJourney MCP returned an imagine input schema with an unsupported root type.', 'worldgraph' )
			);
		}

		$live_properties = $live_schema['properties'] ?? null;
		if (
			! is_array( $live_properties )
			|| count( $live_properties ) > self::MAX_LIVE_PROPERTIES
			|| ! is_array( $live_properties['prompt'] ?? null )
		) {
			return new WP_Error(
				'midjourney_mcp_catalog_schema_invalid',
				__( 'MidJourney MCP returned an incomplete or oversized imagine parameter schema.', 'worldgraph' )
			);
		}

		$live_required = $live_schema['required'] ?? [];
		if ( ! is_array( $live_required ) || count( $live_required ) > self::MAX_LIVE_PROPERTIES ) {
			return new WP_Error(
				'midjourney_mcp_catalog_schema_invalid',
				__( 'MidJourney MCP returned an invalid imagine required-parameter list.', 'worldgraph' )
			);
		}
		$live_required = array_values( array_filter( $live_required, 'is_string' ) );
		if ( ! empty( array_diff( $live_required, self::MCP_PARAMETER_ALLOWLIST ) ) ) {
			return new WP_Error(
				'midjourney_mcp_catalog_schema_invalid',
				__( 'MidJourney MCP requires an imagine parameter that this adapter has not reviewed.', 'worldgraph' )
			);
		}

		$schema = (array) ( $definition['schema'] ?? [] );
		if ( ! empty( $live_tool['description'] ) ) {
			$schema['description'] = self::bounded_live_text( $live_tool['description'] );
		}

		foreach ( self::MCP_PARAMETER_ALLOWLIST as $parameter ) {
			if ( ! array_key_exists( $parameter, $live_properties ) ) {
				continue;
			}
			$live_property = $live_properties[ $parameter ];
			if ( ! is_array( $live_property ) ) {
				return new WP_Error(
					'midjourney_mcp_catalog_schema_invalid',
					__( 'MidJourney MCP returned an invalid imagine parameter definition.', 'worldgraph' )
				);
			}

			$static_property = (array) ( $schema['properties'][ $parameter ] ?? [] );
			if ( isset( $live_property['type'] ) && ! self::type_matches( $live_property['type'], (string) ( $static_property['type'] ?? '' ) ) ) {
				return new WP_Error(
					'midjourney_mcp_catalog_schema_invalid',
					__( 'MidJourney MCP returned an imagine parameter type that does not match the reviewed contract.', 'worldgraph' )
				);
			}

			foreach ( [ 'title', 'description' ] as $metadata_key ) {
				if ( ! empty( $live_property[ $metadata_key ] ) ) {
					$schema['properties'][ $parameter ][ $metadata_key ] = self::bounded_live_text( $live_property[ $metadata_key ] );
				}
			}
		}

		$definition['schema'] = $schema;
		return $definition;
	}

	/** Determine whether a live JSON Schema type includes the reviewed type. */
	private static function type_matches( $live_type, string $reviewed_type ): bool {
		if ( is_string( $live_type ) ) {
			return $reviewed_type === $live_type;
		}
		if ( is_array( $live_type ) ) {
			return in_array( $reviewed_type, $live_type, true );
		}

		return false;
	}

	/** Sanitize and bound one remote schema label or description. */
	private static function bounded_live_text( $value ): string {
		$text = sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, self::MAX_LIVE_TEXT_LENGTH );
		}

		return substr( $text, 0, self::MAX_LIVE_TEXT_LENGTH );
	}

	/** Create or update one reviewed, transport-specific Template. */
	private static function materialize( int $connection_id, array $definition ) {
		$configuration = [
			'transport'         => (string) ( $definition['transport'] ?? '' ),
			'schema_provenance' => (string) ( $definition['provenance'] ?? '' ),
		];
		if ( 'mcp' === $configuration['transport'] ) {
			$configuration['provider_tool']       = self::MCP_IMAGINE_TOOL;
			$configuration['mcp_protocol_version'] = Midjourney_MCP::PROTOCOL_VERSION;
		}

		return Template_Repository::upsert_provider_template(
			$connection_id,
			[
				'provider_type'        => 'midjourney',
				'provider_template_id' => sanitize_text_field( (string) ( $definition['reference'] ?? '' ) ),
				'template_name'        => (string) ( $definition['name'] ?? '' ),
				'description'          => (string) ( $definition['description'] ?? '' ),
				'modality'             => (string) ( $definition['modality'] ?? '' ),
				'input'                => (array) ( $definition['input'] ?? [] ),
				'provider_schema'      => (array) ( $definition['schema'] ?? [] ),
				'configuration'        => $configuration,
				'status'               => 'active',
				'version'              => (string) ( $definition['version'] ?? self::SCHEMA_VERSION ),
			]
		);
	}
}
