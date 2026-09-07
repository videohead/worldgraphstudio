<?php
/**
 * World Graph Studio MCP server registration.
 *
 * Bridges the plugin's Abilities API registrations into the WordPress MCP
 * Adapter plugin so external MCP clients see World Graph Studio capabilities
 * as first-class tools/resources/prompts on a dedicated server, alongside a
 * capability manifest describing the plugin's CPTs, taxonomies and REST API.
 *
 * @package WorldGraph
 * @since 0.1.0
 */

namespace WorldGraph\AI\Abilities;

/**
 * Registers the World Graph Studio MCP server with the MCP Adapter plugin.
 */
class Mcp_Server {

	/**
	 * MCP server identifier.
	 */
	const SERVER_ID = 'worldgraph-studio-server';

	/**
	 * REST namespace the MCP transport is mounted under.
	 */
	const ROUTE_NAMESPACE = 'mcp';

	/**
	 * REST route the MCP transport is mounted at.
	 */
	const ROUTE = 'worldgraph';

	/**
	 * Ability name prefix owned by this plugin.
	 */
	const ABILITY_PREFIX = 'worldgraph/';

	/**
	 * Hook registration.
	 */
	public static function init(): void {
		add_action( 'wp_abilities_api_init', [ __CLASS__, 'register_capability_abilities' ], 20 );
		add_action( 'mcp_adapter_init', [ __CLASS__, 'register_server' ] );
	}

	/**
	 * Register the introspection abilities that describe this plugin to MCP clients.
	 */
	public static function register_capability_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		\wp_register_ability(
			self::ABILITY_PREFIX . 'capabilities',
			[
				'label'               => 'World Graph Studio Capabilities',
				'description'         => 'Describe the World Graph Studio installation: version, Story Graph content types, taxonomies, REST API surface, provider connections and registered abilities.',
				'category'            => 'worldgraph-ai-editor',
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'plugin'        => [ 'type' => 'object' ],
						'content_types' => [ 'type' => 'array' ],
						'taxonomies'    => [ 'type' => 'array' ],
						'rest_api'      => [ 'type' => 'object' ],
						'connections'   => [ 'type' => 'array' ],
						'abilities'     => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ __CLASS__, 'build_capability_manifest' ],
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => [
					'public' => true,
					'mcp'    => [
						'type'        => 'resource',
						'uri'         => 'worldgraph://capabilities',
						'annotations' => [
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						],
					],
				],
			]
		);
	}

	/**
	 * Create the dedicated World Graph Studio MCP server.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter MCP Adapter instance.
	 */
	public static function register_server( $adapter ): void {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$components = self::collect_abilities();

		if ( empty( $components['tools'] ) && empty( $components['resources'] ) && empty( $components['prompts'] ) ) {
			return;
		}

		$adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			'World Graph Studio',
			'Story Graph authoring, continuity analysis, prompt templates and asset generation for the World Graph Studio WordPress plugin.',
			defined( 'WORLDGRAPH_VERSION' ) ? 'v' . WORLDGRAPH_VERSION : 'v1.0.0',
			[ \WP\MCP\Transport\HttpTransport::class ],
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			$components['tools'],
			$components['resources'],
			$components['prompts']
		);
	}

	/**
	 * Split the plugin's public abilities into MCP component buckets.
	 *
	 * @return array{tools:string[],resources:string[],prompts:string[]}
	 */
	private static function collect_abilities(): array {
		$buckets = [
			'tools'     => [],
			'resources' => [],
			'prompts'   => [],
		];

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $buckets;
		}

		foreach ( \wp_get_abilities() as $ability ) {
			$name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';

			if ( 0 !== strpos( $name, self::ABILITY_PREFIX ) ) {
				continue;
			}

			$meta = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : [];

			if ( empty( $meta['public'] ) ) {
				continue;
			}

			switch ( $meta['mcp']['type'] ?? 'tool' ) {
				case 'resource':
					$buckets['resources'][] = $name;
					break;
				case 'prompt':
					$buckets['prompts'][] = $name;
					break;
				default:
					$buckets['tools'][] = $name;
			}
		}

		/**
		 * Filters the abilities exposed on the World Graph Studio MCP server.
		 *
		 * @param array $buckets Arrays of ability names keyed by tools/resources/prompts.
		 */
		return apply_filters( 'worldgraph_mcp_server_components', $buckets );
	}

	/**
	 * Build the capability manifest returned by the capabilities ability.
	 *
	 * @param mixed $input Ability input. Ability-backed MCP resources are read without arguments.
	 * @return array
	 */
	public static function build_capability_manifest( $input = null ): array {
		$input = is_array( $input ) ? $input : [];

		$sections = isset( $input['sections'] ) && is_array( $input['sections'] ) && $input['sections']
			? array_map( 'strval', $input['sections'] )
			: [ 'plugin', 'content_types', 'taxonomies', 'rest_api', 'connections', 'abilities' ];

		$manifest = [];

		if ( in_array( 'plugin', $sections, true ) ) {
			$manifest['plugin'] = [
				'name'          => 'World Graph Studio',
				'version'       => defined( 'WORLDGRAPH_VERSION' ) ? WORLDGRAPH_VERSION : '',
				'rest_namespace'=> defined( 'WORLDGRAPH_API_NAMESPACE' ) ? WORLDGRAPH_API_NAMESPACE : 'worldgraph/v1',
				'mcp_endpoint'  => rest_url( self::ROUTE_NAMESPACE . '/' . self::ROUTE ),
				'site_url'      => home_url( '/' ),
			];
		}

		if ( in_array( 'content_types', $sections, true ) ) {
			$manifest['content_types'] = self::content_types();
		}

		if ( in_array( 'taxonomies', $sections, true ) ) {
			$manifest['taxonomies'] = self::taxonomies();
		}

		if ( in_array( 'rest_api', $sections, true ) ) {
			$manifest['rest_api'] = self::rest_api();
		}

		if ( in_array( 'connections', $sections, true ) ) {
			$manifest['connections'] = self::connections();
		}

		if ( in_array( 'abilities', $sections, true ) ) {
			$manifest['abilities'] = self::abilities_summary();
		}

		return $manifest;
	}

	/**
	 * Story Graph custom post types registered by the plugin.
	 *
	 * @return array
	 */
	private static function content_types(): array {
		$prefix = defined( 'WORLDGRAPH_CPT_PREFIX' ) ? WORLDGRAPH_CPT_PREFIX : 'worldgraph_';
		$types  = [];

		foreach ( get_post_types( [], 'objects' ) as $post_type ) {
			if ( 0 !== strpos( $post_type->name, $prefix ) ) {
				continue;
			}

			$types[] = [
				'slug'          => $post_type->name,
				'label'         => $post_type->labels->name ?? $post_type->label,
				'description'   => $post_type->description,
				'rest_base'     => $post_type->rest_base ?: $post_type->name,
				'show_in_rest'  => (bool) $post_type->show_in_rest,
				'taxonomies'    => get_object_taxonomies( $post_type->name ),
				'supports'      => array_keys( get_all_post_type_supports( $post_type->name ) ),
			];
		}

		return $types;
	}

	/**
	 * Story Graph taxonomies registered by the plugin.
	 *
	 * @return array
	 */
	private static function taxonomies(): array {
		$prefix     = defined( 'WORLDGRAPH_CPT_PREFIX' ) ? WORLDGRAPH_CPT_PREFIX : 'worldgraph_';
		$taxonomies = [];

		foreach ( get_taxonomies( [], 'objects' ) as $taxonomy ) {
			if ( 0 !== strpos( $taxonomy->name, $prefix ) ) {
				continue;
			}

			$taxonomies[] = [
				'slug'         => $taxonomy->name,
				'label'        => $taxonomy->labels->name ?? $taxonomy->label,
				'description'  => $taxonomy->description,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'object_types' => (array) $taxonomy->object_type,
				'show_in_rest' => (bool) $taxonomy->show_in_rest,
			];
		}

		return $taxonomies;
	}

	/**
	 * REST routes registered under the plugin namespace.
	 *
	 * @return array
	 */
	private static function rest_api(): array {
		$namespace = defined( 'WORLDGRAPH_API_NAMESPACE' ) ? WORLDGRAPH_API_NAMESPACE : 'worldgraph/v1';
		$routes    = [];

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( 0 !== strpos( ltrim( $route, '/' ), $namespace ) ) {
				continue;
			}

			$methods = [];
			foreach ( $handlers as $handler ) {
				$methods = array_merge( $methods, array_keys( array_filter( (array) ( $handler['methods'] ?? [] ) ) ) );
			}

			$routes[] = [
				'route'   => $route,
				'methods' => array_values( array_unique( $methods ) ),
				'url'     => rest_url( ltrim( $route, '/' ) ),
			];
		}

		return [
			'namespace' => $namespace,
			'root'      => rest_url( $namespace ),
			'routes'    => $routes,
		];
	}

	/**
	 * Provider connection adapters known to this installation.
	 *
	 * @return array
	 */
	private static function connections(): array {
		if ( ! class_exists( '\WorldGraph\Connections\Adapter_Registry' ) ) {
			return [];
		}

		$adapters = [];

		foreach ( \WorldGraph\Connections\Adapter_Registry::all() as $type => $manifest ) {
			$adapters[] = [
				'provider_type' => $type,
				'label'         => $manifest['label'] ?? $type,
				'description'   => $manifest['description'] ?? '',
				'transport'     => ! empty( $manifest['mcp_endpoint'] ) ? 'mcp' : 'rest',
				'supports_generation' => \WorldGraph\Connections\Adapter_Registry::supports_generation( $type ),
			];
		}

		return $adapters;
	}

	/**
	 * Summary of the plugin's abilities and how they surface over MCP.
	 *
	 * @return array
	 */
	private static function abilities_summary(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}

		$summary = [];

		foreach ( \wp_get_abilities() as $ability ) {
			$name = is_object( $ability ) && method_exists( $ability, 'get_name' ) ? $ability->get_name() : '';

			if ( 0 !== strpos( $name, self::ABILITY_PREFIX ) ) {
				continue;
			}

			$meta = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : [];

			$summary[] = [
				'name'        => $name,
				'label'       => method_exists( $ability, 'get_label' ) ? $ability->get_label() : '',
				'description' => method_exists( $ability, 'get_description' ) ? $ability->get_description() : '',
				'mcp_type'    => $meta['mcp']['type'] ?? 'tool',
				'public'      => ! empty( $meta['public'] ),
			];
		}

		return $summary;
	}
}
