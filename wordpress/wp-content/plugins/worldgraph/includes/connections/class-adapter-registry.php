<?php
/**
 * Conditional loader, manifest, and capability registry for Connections.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Connections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-builtin-adapter-runtime.php';
require_once __DIR__ . '/class-builtin-connection-tests.php';

/**
 * Keeps provider metadata in core while loading implementations on demand.
 */
class Adapter_Registry {

	/** @var array<string, bool> Providers loaded during this request. */
	private static $loaded = [];

	/**
	 * Provider adapter manifest.
	 *
	 * Third-party integrations may add an adapter with the
	 * `worldgraph_conn_adapters` filter. Files are loaded only when that
	 * provider has an enabled Connection or is explicitly requested.
	 *
	 * Optional contracts are declared under `callbacks`, `templates`, and
	 * `generation`. A declaration advertises capability; it does not bypass
	 * Connection permissions, provider loading, or execution-time validation.
	 */
	public static function all(): array {
		$adapters = [
			'comfyui' => [
				'label'         => 'ComfyUI',
				'description'   => 'Generate media through Comfy Cloud MCP or a local ComfyUI installation.',
				'icon'          => 'dashicons-format-image',
				'endpoint'      => 'https://cloud.comfy.org/mcp',
				'setup_options' => [
					'cloud'     => [
						'label'       => 'Comfy Cloud MCP',
						'environment' => 'production',
					],
					'local_mcp' => [
						'label'       => 'Local ComfyUI HTTP API + MCP',
						'environment' => 'local',
					],
				],
				'files'         => [
					'includes/utils/comfy-cloud-mcp.php',
					'includes/utils/local-comfyui.php',
					'includes/utils/comfy-manifest.php',
					'includes/utils/comfy-graph.php',
					'includes/utils/comfy-template-registry.php',
					'includes/utils/comfy-catalog.php',
					'includes/utils/comfy-bootstrap.php',
				],
				'callbacks'     => [
					'test' => [ Builtin_Connection_Tests::class, 'test_comfyui' ],
				],
				'generation'    => [
					'client_resolver'    => [ Builtin_Adapter_Runtime::class, 'comfy_client' ],
					'poll'               => true,
					'poll_with_template' => false,
					'poll_error_limit'   => 10,
					'adapter'            => 'comfyui',
					'media_inputs'       => true,
				],
			],
			'fal' => [
				'label'         => 'fal',
				'description'   => 'Generate media through fal MCP with automatically provisioned Templates.',
				'icon'          => 'dashicons-cloud',
				'endpoint'      => 'https://mcp.fal.ai/mcp',
				'setup_options' => [
					'fal' => [
						'label'        => 'fal MCP',
						'environment'  => 'production',
						'mcp_endpoint' => true,
					],
				],
				'files'         => [
					'includes/utils/fal-mcp.php',
					'includes/utils/fal-catalog.php',
				],
				'init'          => [ 'WorldGraph\\Utils\\Fal_Catalog', 'init' ],
				'callbacks'     => [
					'test' => [ Builtin_Connection_Tests::class, 'test_fal' ],
				],
				'templates'     => [
					'provision'          => [ 'WorldGraph\\Utils\\Fal_Catalog', 'provision' ],
					'delay'              => 5,
					'status_meta_prefix' => 'fal_catalog',
				],
				'generation'    => [
					'client'             => 'WorldGraph\\Utils\\Fal_MCP',
					'poll'               => true,
					'poll_with_template' => true,
					'poll_error_limit'   => 10,
					'adapter'            => 'fal_mcp',
					'flatten_inputs'     => true,
					'media_inputs'       => false,
				],
			],
			'elevenlabs' => [
				'label'         => 'ElevenLabs',
				'description'   => 'Generate speech, dialogue, sound effects, music, and voice previews through endpoint-specific Templates.',
				'icon'          => 'dashicons-microphone',
				'endpoint'      => 'https://api.elevenlabs.io/v1',
				'setup_options' => [
					'elevenlabs' => [
						'label'       => 'ElevenLabs Generative Audio',
						'environment' => 'production',
					],
				],
				'files'         => [
					'includes/utils/elevenlabs-api.php',
					'includes/utils/elevenlabs-catalog.php',
				],
				'init'          => [ 'WorldGraph\\Utils\\ElevenLabs_Catalog', 'init' ],
				'callbacks'     => [
					'test' => [ Builtin_Connection_Tests::class, 'test_elevenlabs' ],
				],
				'templates'     => [
					'provision'          => [ 'WorldGraph\\Utils\\ElevenLabs_Catalog', 'provision' ],
					'delay'              => 5,
					'status_meta_prefix' => 'elevenlabs_catalog',
				],
				'generation'    => [
					'client'             => 'WorldGraph\\Utils\\ElevenLabs_API',
					'poll'               => false,
					'poll_with_template' => false,
					'adapter'            => 'elevenlabs',
					'media_inputs'       => false,
				],
			],
			'suno' => [
				'label'         => 'Suno',
				'description'   => 'Generate songs and lyrics through SunoAPI.org REST or the AceData Cloud Suno MCP server.',
				'icon'          => 'dashicons-album',
				'endpoint'      => 'https://api.sunoapi.org',
				'mcp_endpoint'  => 'https://suno.mcp.acedata.cloud/mcp',
				'setup_options' => [
					'suno' => [
						'label'                   => 'Suno API + MCP',
						'environment'             => 'production',
						'mcp_endpoint'            => true,
						'separate_mcp_credential' => true,
					],
				],
				'files'         => [
					'includes/utils/suno-api.php',
					'includes/utils/suno-mcp.php',
					'includes/utils/suno-catalog.php',
				],
				'init'          => [ 'WorldGraph\\Utils\\Suno_Catalog', 'init' ],
				'callbacks'     => [
					'test' => [ Builtin_Connection_Tests::class, 'test_suno' ],
				],
				'templates'     => [
					'provision'          => [ 'WorldGraph\\Utils\\Suno_Catalog', 'provision' ],
					'delay'              => 5,
					'status_meta_prefix' => 'suno_catalog',
				],
				'generation'    => [
					'client_resolver'    => [ Builtin_Adapter_Runtime::class, 'suno_client' ],
					'poll'               => true,
					'poll_with_template' => true,
					'poll_error_limit'   => 10,
					'adapter'            => 'suno',
					'media_inputs'       => false,
				],
			],
			'videodraft' => [
				'label'         => 'VideoDraft',
				'description'   => 'Generate image, video, and audio assets and synchronize projects through VideoDraft MCP.',
				'icon'          => 'dashicons-video-alt3',
				'endpoint'      => 'https://app.videodraft.ai/api/mcp',
				'mcp_endpoint'  => 'https://app.videodraft.ai/api/mcp',
				'setup_options' => [
					'videodraft' => [
						'label'        => 'VideoDraft Cloud',
						'environment'  => 'production',
						'mcp_endpoint' => true,
					],
				],
				'files'         => [
					'includes/utils/videodraft-api.php',
					'includes/utils/videodraft-catalog.php',
				],
				'init'          => [ 'WorldGraph\\Utils\\VideoDraft_Catalog', 'init' ],
				'callbacks'     => [
					'test' => [ Builtin_Connection_Tests::class, 'test_videodraft' ],
				],
				'templates'     => [
					'provision'          => [ 'WorldGraph\\Utils\\VideoDraft_Catalog', 'provision' ],
					'delay'              => 5,
					'status_meta_prefix' => 'videodraft_catalog',
				],
				'generation'    => [
					'client'                => 'WorldGraph\\Utils\\VideoDraft_API',
					'poll'                  => true,
					'poll_with_template'    => true,
					'poll_error_limit'      => 10,
					'permanent_error_codes' => [
						'videodraft_credential_missing',
						'videodraft_connection_invalid',
						'videodraft_tool_not_allowed',
					],
					'adapter'               => 'videodraft',
					'media_inputs'          => true,
				],
			],
			'descript' => [
				'label'         => 'Descript',
				'description'   => 'Import project transcripts from Descript and export bound media into new Descript projects.',
				'icon'          => 'dashicons-media-text',
				'endpoint'      => 'https://descriptapi.com/v1',
				'setup_options' => [
					'descript' => [
						'label'       => 'Descript API',
						'environment' => 'production',
					],
				],
				'files'         => [
					'includes/utils/descript-api.php',
				],
				'callbacks'     => [
					'test' => [ Builtin_Connection_Tests::class, 'test_descript' ],
				],
			],
			'openrouter' => [
				'label'         => 'OpenRouter',
				'description'   => 'Generate video from text (and optional reference images) through the OpenRouter asynchronous Video Generation API.',
				'icon'          => 'dashicons-video-alt2',
				'endpoint'      => 'https://openrouter.ai/api/v1',
				'setup_options' => [
					'openrouter' => [
						'label'       => 'OpenRouter Video Generation',
						'environment' => 'production',
					],
				],
				'files'         => [
					'includes/utils/openrouter-api.php',
				],
				'callbacks'     => [
					'test' => [ Builtin_Connection_Tests::class, 'test_openrouter' ],
				],
				'generation'    => [
					'client'             => 'WorldGraph\\Utils\\OpenRouter_API',
					'poll'               => true,
					'poll_with_template' => false,
					'poll_error_limit'   => 10,
					'adapter'            => 'openrouter',
					'media_inputs'       => false,
				],
			],
			'openai_compatible' => [
				'label'     => 'OpenAI-compatible',
				'endpoint'  => '',
				'files'     => [],
				'callbacks' => [ 'test' => [ Builtin_Connection_Tests::class, 'test_llm' ] ],
			],
			'openai' => [
				'label'     => 'OpenAI',
				'endpoint'  => 'https://api.openai.com/v1',
				'files'     => [],
				'callbacks' => [ 'test' => [ Builtin_Connection_Tests::class, 'test_llm' ] ],
			],
			'anthropic' => [
				'label'     => 'Anthropic',
				'endpoint'  => 'https://api.anthropic.com',
				'files'     => [],
				'callbacks' => [ 'test' => [ Builtin_Connection_Tests::class, 'test_llm' ] ],
			],
			'dual' => [
				'label'     => 'Dual LLM',
				'endpoint'  => '',
				'files'     => [],
				'callbacks' => [ 'test' => [ Builtin_Connection_Tests::class, 'test_llm' ] ],
			],
			'google_gemini' => [ 'label' => 'Google Gemini', 'endpoint' => '', 'files' => [] ],
			'veo'           => [ 'label' => 'Veo', 'endpoint' => '', 'files' => [] ],
			'nova_reel'     => [ 'label' => 'Nova Reel', 'endpoint' => '', 'files' => [] ],
		];

		return (array) apply_filters( 'worldgraph_conn_adapters', $adapters );
	}

	/** Return one normalized adapter declaration. */
	public static function get( string $provider_type ): ?array {
		$provider_type = sanitize_key( $provider_type );
		$adapter       = self::all()[ $provider_type ] ?? null;
		if ( ! is_array( $adapter ) ) {
			return null;
		}

		$adapter['callbacks'] = isset( $adapter['callbacks'] ) && is_array( $adapter['callbacks'] ) ? $adapter['callbacks'] : [];
		$adapter['templates'] = isset( $adapter['templates'] ) && is_array( $adapter['templates'] ) ? $adapter['templates'] : [];
		if ( ! empty( $adapter['templates'] ) ) {
			$adapter['templates']['delay'] = max( 1, absint( $adapter['templates']['delay'] ?? 5 ) );
		}
		$adapter['generation'] = isset( $adapter['generation'] ) && is_array( $adapter['generation'] ) ? $adapter['generation'] : [];
		if ( ! empty( $adapter['generation'] ) ) {
			$adapter['generation'] = array_merge(
				[
					'poll'               => false,
					'poll_with_template' => false,
					'poll_error_limit'   => 10,
					'flatten_inputs'     => false,
					'media_inputs'       => false,
				],
				$adapter['generation']
			);
			$adapter['generation']['poll_error_limit'] = max( 1, absint( $adapter['generation']['poll_error_limit'] ) );
		}

		return $adapter;
	}

	/** Known provider slugs, including metadata-only future adapters. */
	public static function provider_types(): array {
		return array_keys( self::all() );
	}

	/** Preferred generation choices exposed by the first-run Setup Wizard. */
	public static function setup_options(): array {
		$options = [];
		foreach ( self::setup_choices() as $value => $choice ) {
			$options[ $value ] = sanitize_text_field( (string) ( $choice['label'] ?? $value ) );
		}

		$options['none'] = 'No generation connection yet';
		return (array) apply_filters( 'worldgraph_setup_connection_options', $options );
	}

	/** Full Setup Wizard choice definitions keyed by submitted value. */
	public static function setup_choices(): array {
		$choices = [];
		foreach ( self::all() as $provider_type => $adapter ) {
			foreach ( (array) ( $adapter['setup_options'] ?? [] ) as $value => $choice ) {
				if ( ! is_array( $choice ) ) {
					$choice = [ 'label' => (string) $choice ];
				}
				$choice['provider_type'] = $provider_type;
				$choices[ sanitize_key( (string) $value ) ] = $choice;
			}
		}

		return (array) apply_filters( 'worldgraph_setup_connection_choices', $choices );
	}

	/** Setup definition for one submitted wizard choice. */
	public static function setup_choice( string $value ): ?array {
		$choice = self::setup_choices()[ sanitize_key( $value ) ] ?? null;
		return is_array( $choice ) ? $choice : null;
	}

	/** Default endpoint without loading provider API code. */
	public static function endpoint( string $provider_type ): string {
		$adapter = self::get( $provider_type ) ?? [];
		return esc_url_raw( (string) ( $adapter['endpoint'] ?? '' ) );
	}

	/** Default MCP endpoint without loading provider API code. */
	public static function mcp_endpoint( string $provider_type ): string {
		$adapter = self::get( $provider_type ) ?? [];
		return esc_url_raw( (string) ( $adapter['mcp_endpoint'] ?? '' ) );
	}

	/**
	 * Load one provider implementation for this request.
	 *
	 * @return bool Whether the provider is known and its declared files loaded.
	 */
	public static function load( string $provider_type ): bool {
		$provider_type = sanitize_key( $provider_type );
		if ( isset( self::$loaded[ $provider_type ] ) ) {
			return self::$loaded[ $provider_type ];
		}

		$adapter = self::get( $provider_type );
		if ( ! is_array( $adapter ) ) {
			self::$loaded[ $provider_type ] = false;
			return false;
		}
		if ( ! empty( $adapter['loader'] ) && is_callable( $adapter['loader'] ) ) {
			call_user_func( $adapter['loader'], $provider_type, $adapter );
		}

		foreach ( (array) ( $adapter['files'] ?? [] ) as $relative_file ) {
			$relative_file = ltrim( (string) $relative_file, '/' );
			$file          = realpath( WORLDGRAPH_PLUGIN_DIR . $relative_file );
			$base          = trailingslashit( wp_normalize_path( (string) realpath( WORLDGRAPH_PLUGIN_DIR ) ) );
			if ( false === $file || ! str_starts_with( wp_normalize_path( $file ), $base ) || ! is_readable( $file ) ) {
				self::$loaded[ $provider_type ] = false;
				return false;
			}
			require_once $file;
		}

		if ( ! empty( $adapter['init'] ) && is_callable( $adapter['init'] ) ) {
			call_user_func( $adapter['init'] );
		}

		self::$loaded[ $provider_type ] = true;
		return true;
	}

	/** Load adapters for saved, non-disabled Connections only. */
	public static function load_configured(): void {
		foreach ( \WorldGraph\Utils\Connection_Repository::get_all() as $connection ) {
			if ( 'disabled' === ( $connection['status'] ?? '' ) ) {
				continue;
			}
			self::load( (string) ( $connection['provider_type'] ?? '' ) );
		}
	}

	/** Whether a provider implementation was loaded this request. */
	public static function is_loaded( string $provider_type ): bool {
		return ! empty( self::$loaded[ sanitize_key( $provider_type ) ] );
	}

	/** Whether an adapter declares one callback or dotted manifest capability. */
	public static function supports( string $provider_type, string $capability ): bool {
		$adapter = self::get( $provider_type );
		if ( ! is_array( $adapter ) || '' === trim( $capability ) ) {
			return false;
		}

		$segments = array_map(
			'sanitize_key',
			explode( '.', strtolower( trim( $capability ) ) )
		);
		if ( in_array( '', $segments, true ) ) {
			return false;
		}
		if ( 1 === count( $segments ) && isset( $adapter['callbacks'][ $segments[0] ] ) ) {
			return ! empty( $adapter['callbacks'][ $segments[0] ] );
		}

		$value = $adapter;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return false;
			}
			$value = $value[ $segment ];
		}

		return ! empty( $value );
	}

	/** Resolve one declared callback after loading its provider. */
	public static function callback( string $provider_type, string $callback_name ): ?callable {
		$provider_type = sanitize_key( $provider_type );
		$callback_name = sanitize_key( $callback_name );
		$adapter       = self::get( $provider_type );
		$callback      = is_array( $adapter ) ? ( $adapter['callbacks'][ $callback_name ] ?? null ) : null;
		if ( empty( $callback ) || ! self::load( $provider_type ) ) {
			return null;
		}

		return is_callable( $callback ) ? $callback : null;
	}

	/** Return arbitrary normalized generation metadata for one provider. */
	public static function generation_config( string $provider_type ): array {
		$adapter = self::get( $provider_type );
		return is_array( $adapter ) ? (array) ( $adapter['generation'] ?? [] ) : [];
	}

	/** Whether an adapter declares exactly one generation client strategy. */
	public static function supports_generation( string $provider_type ): bool {
		$config       = self::generation_config( $provider_type );
		$has_client   = ! empty( $config['client'] );
		$has_resolver = ! empty( $config['client_resolver'] );

		return $has_client !== $has_resolver;
	}

	/** Resolve the class used to submit or poll a provider generation job. */
	public static function generation_client( string $provider_type, array $connection = [], string $template = '', string $adapter = '' ): string {
		$provider_type = sanitize_key( $provider_type );
		if ( ! self::supports_generation( $provider_type ) || ! self::load( $provider_type ) ) {
			return '';
		}

		$config = self::generation_config( $provider_type );
		if ( ! empty( $config['client_resolver'] ) && is_callable( $config['client_resolver'] ) ) {
			$client = call_user_func( $config['client_resolver'], $connection, $template, $adapter );
			return is_string( $client ) ? ltrim( $client, '\\' ) : '';
		}

		return is_string( $config['client'] ?? null ) ? ltrim( (string) $config['client'], '\\' ) : '';
	}

	/** Whether generation from this adapter has an asynchronous poll phase. */
	public static function supports_polling( string $provider_type ): bool {
		return self::supports_generation( $provider_type ) && ! empty( self::generation_config( $provider_type )['poll'] );
	}

	/** Whether the poll client accepts the provider Template as a third argument. */
	public static function poll_with_template( string $provider_type ): bool {
		return self::supports_polling( $provider_type ) && ! empty( self::generation_config( $provider_type )['poll_with_template'] );
	}

	/** Whether provider submission accepts resolved media input bindings. */
	public static function supports_media_inputs( string $provider_type ): bool {
		return self::supports_generation( $provider_type ) && ! empty( self::generation_config( $provider_type )['media_inputs'] );
	}

	/** Resolve the stable adapter marker persisted with a generation job. */
	public static function generation_adapter( string $provider_type, array $connection = [], string $template = '', string $adapter = '' ): string {
		$provider_type = sanitize_key( $provider_type );
		$adapter       = sanitize_key( $adapter );
		if ( '' !== $adapter ) {
			return $adapter;
		}

		$config   = self::generation_config( $provider_type );
		$declared = $config['adapter'] ?? '';
		if ( is_callable( $declared ) && self::load( $provider_type ) ) {
			$resolved = call_user_func( $declared, $connection, $template, $adapter );
			$resolved = is_string( $resolved ) ? sanitize_key( $resolved ) : '';
			if ( '' !== $resolved ) {
				return $resolved;
			}
		} elseif ( is_string( $declared ) && '' !== sanitize_key( $declared ) ) {
			return sanitize_key( $declared );
		}

		return $provider_type;
	}
}
