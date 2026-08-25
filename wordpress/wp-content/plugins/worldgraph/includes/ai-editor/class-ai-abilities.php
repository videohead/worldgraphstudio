<?php
/**
 * World Graph Studio AI Abilities Registration
 *
 * Registers World Graph Studio AI abilities with WordPress core Abilities API
 * for exposure via MCP Adapter and other AI tooling.
 *
 * @package WorldGraph
 * @since 0.1.0
 */

namespace WorldGraph\AI\Abilities;

use WP_Error;

/**
 * Abstract base class for World Graph Studio ability groups.
 *
 * Provides a registration helper method and manages
 * the group's ability definitions.
 */
abstract class AbstractAbilityGroup {
    /**
     * Group slug.
     *
     * @var string
     */
    protected $slug = '';

    /**
     * Group label.
     *
     * @var string
     */
    protected $label = '';

    /**
     * Group description.
     *
     * @var string
     */
    protected $description = '';

    /**
     * Group category slug for WP_Ability_Category.
     *
     * @var string
     */
    protected $category_slug = 'worldgraph-ai-editor';

    /**
     * Abilities defined by this group.
     *
     * @var array
     */
    protected $abilities = [];

    /**
     * Register the ability group.
     *
     * Called by the main Abilities class during init.
     */
    abstract public function register(): void;

    /**
     * Register a single ability.
     *
     * @param string $name      Ability name (e.g., 'worldgraph/chat').
     * @param array  $args      Ability arguments.
     * @return WP_Error|int Result of wp_register_ability.
     */
    protected function register_ability( string $name, array $args ) {
        // Merge default meta with provided args.
        $args = wp_parse_args( $args, [
            'label'          => '',
            'description'    => '',
            'input_schema'   => [],
            'output_schema'  => [],
            'execute_callback' => null,
            'permission_callback' => null,
			'meta'           => [],
		] );
        // Ensure meta array exists.
        $args['meta'] = wp_parse_args( $args['meta'], [
            'public' => true,
            'mcp'    => [ 'type' => 'tool' ],
		] );

        // Set default annotations if not provided.
        if ( ! isset( $args['meta']['annotations'] ) ) {
            $args['meta']['annotations'] = [
                'readonly'   => true,
                'destructive' => false,
                'idempotent'  => true,
            ];
        }

        // Default permission: logged in.
        if ( ! $args['permission_callback'] ) {
            $args['permission_callback'] = function() {
                return is_user_logged_in();
            };
        }

        return \wp_register_ability( $name, $args );
    }

    /**
     * Get group slug.
     *
     * @return string
     */
    public function get_slug(): string {
        return $this->slug;
    }

    /**
     * Get group label.
     *
     * @return string
     */
    public function get_label(): string {
        return $this->label;
    }
}

/**
 * Chat & Generation abilities.
 *
 * Provides chat, content analysis, generation, and continuity checking.
 */
class Chat_Abilities extends AbstractAbilityGroup {
    protected $slug       = 'worldgraph-chat';
    protected $label      = 'Chat & Generation';
    protected $description = 'AI chat, content analysis, generation, and continuity checking for story editors.';

    public function register(): void {
        // worldgraph/chat - Main AI chat endpoint.
        $this->register_ability( 'worldgraph/chat', [
            'label'       => 'AI Chat',
            'description' => 'Send a prompt to the World Graph Studio AI agent and receive a response.',
            'input_schema' => [
                'type'  => 'object',
                'properties' => [
                    'prompt' => [
                        'type'        => 'string',
                        'description' => 'The user prompt or question.',
                    ],
                    'agent'  => [
                        'type'        => 'string',
                        'description' => 'Agent slug to route to (optional). Auto-detected if omitted.',
                    ],
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Current post ID for context (optional).',
                    ],
                ],
                'required' => ['prompt'],
            ],
            'output_schema' => [
                'type'  => 'object',
                'properties' => [
                    'response' => ['type' => 'string'],
                    'agent'    => ['type' => 'string'],
                    'success'  => ['type' => 'boolean'],
                ],
            ],
            'execute_callback' => function( $input ) {
                $request = new \WP_REST_Request( 'POST' );
                $request->set_param( 'prompt', $input['prompt'] );
                if ( isset( $input['agent'] ) ) {
                    $request->set_param( 'agent', $input['agent'] );
                }
                if ( isset( $input['post_id'] ) ) {
                    $request->set_param( 'post_id', (int) $input['post_id'] );
                }
                $response = ( new \WorldGraph\AI\AI_Editor_REST() )->chat( $request );
                return $response->get_data();
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'public' => true,
                'mcp'    => [ 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => false,
                ],
            ],
        ] );

        // worldgraph/analyze - Content analysis.
        $this->register_ability( 'worldgraph/analyze', [
            'label'       => 'Analyze Content',
            'description' => 'Analyze post content for story quality, tone, and structure.',
            'input_schema' => [
                'type'  => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID to analyze.',
                    ],
                    'focus' => [
                        'type'        => 'string',
                        'description' => 'Analysis focus: story, dialogue, pacing, character (optional).',
                    ],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'  => 'object',
                'properties' => [
                    'score'     => ['type' => 'number'],
                    'feedback'  => ['type' => 'array'],
                    'suggestions' => ['type' => 'array'],
                ],
            ],
            'execute_callback' => function( $input ) {
                $request = new \WP_REST_Request( 'POST' );
                $request->set_param( 'post_id', (int) $input['post_id'] );
                $request->set_param( 'prompt', isset( $input['focus'] ) ? $input['focus'] : 'story' );
                $response = ( new \WorldGraph\AI\AI_Editor_REST() )->analyze( $request );
                return $response->get_data();
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'public' => true,
                'mcp'    => [ 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );

        // worldgraph/generate - Content generation.
        $this->register_ability( 'worldgraph/generate', [
            'label'       => 'Generate Content',
            'description' => 'Generate story content such as dialogue, scenes, or descriptions.',
            'input_schema' => [
                'type'  => 'object',
                'properties' => [
                    'prompt'    => [
                        'type'        => 'string',
                        'description' => 'Generation prompt.',
                    ],
                    'type'      => [
                        'type'        => 'string',
                        'description' => 'Content type: dialogue, scene, description (optional).',
                    ],
                    'post_id'   => [
                        'type'        => 'integer',
                        'description' => 'Current post for context (optional).',
                    ],
                ],
                'required' => ['prompt'],
            ],
            'output_schema' => [
                'type'  => 'object',
                'properties' => [
                    'content' => ['type' => 'string'],
                    'type'    => ['type' => 'string'],
                ],
            ],
            'execute_callback' => function( $input ) {
                $request = new \WP_REST_Request( 'POST' );
                $request->set_param( 'prompt', $input['prompt'] );
                if ( isset( $input['type'] ) ) {
                    $request->set_param( 'agent', $input['type'] );
                }
                if ( isset( $input['post_id'] ) ) {
                    $request->set_param( 'post_id', (int) $input['post_id'] );
                }
                $response = ( new \WorldGraph\AI\AI_Editor_REST() )->generate( $request );
                return $response->get_data();
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'public' => true,
                'mcp'    => [ 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ],
            ],
        ] );

        // worldgraph/continuity-check - Continuity checking.
        $this->register_ability( 'worldgraph/continuity-check', [
            'label'       => 'Continuity Check',
            'description' => 'Check content for continuity errors against Story Graph data.',
            'input_schema' => [
                'type'  => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID to check.',
                    ],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'  => 'object',
                'properties' => [
                    'issues'    => ['type' => 'array'],
                    'severity'  => ['type' => 'string'],
                ],
            ],
            'execute_callback' => function( $input ) {
                $request = new \WP_REST_Request( 'POST' );
                $request->set_param( 'post_id', (int) $input['post_id'] );
                $response = ( new \WorldGraph\AI\AI_Editor_REST() )->continuity_check( $request );
                return $response->get_data();
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'public' => true,
                'mcp'    => [ 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );
    }
}

/**
 * Context Resource abilities.
 *
 * Provides read-only access to Story Graph context data.
 */
class Context_Resources extends AbstractAbilityGroup {
    protected $slug       = 'worldgraph-context';
    protected $label      = 'Story Context';
    protected $description = 'Read-only access to Story Graph context for posts, characters, and scenes.';

    public function register(): void {
        // worldgraph/post-context - Get full context for a post.
        $this->register_ability( 'worldgraph/post-context', [
            'label'       => 'Post Context',
            'description' => 'Retrieve full Story Graph context for a post including characters, scenes, and project data.',
            'input_schema' => [
                'type'  => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID.',
                    ],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'  => 'object',
                'properties' => [
                    'post'      => ['type' => 'object'],
                    'characters'=> ['type' => 'array'],
                    'scenes'    => ['type' => 'array'],
                    'project'   => ['type' => 'object'],
                ],
            ],
            'execute_callback' => function( $input ) {
                return ( new \WorldGraph\AI\AI_Context_Builder() )->build_post_context(
                    (int) $input['post_id']
                );
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'public' => true,
                'mcp'    => [ 'type' => 'resource' ],
                'uri'    => 'worldgraph://post-context/{post_id}',
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );

        // worldgraph/character-context - Get character context.
        $this->register_ability( 'worldgraph/character-context', [
            'label'       => 'Character Context',
            'description' => 'Retrieve character metadata, relationships, and scene appearances.',
            'input_schema' => [
                'type'  => 'object',
                'properties' => [
                    'character_id' => [
                        'type'        => 'integer',
                        'description' => 'Character post ID.',
                    ],
                ],
                'required' => ['character_id'],
            ],
            'output_schema' => [
                'type'  => 'object',
                'properties' => [
                    'character' => ['type' => 'object'],
                    'relationships' => ['type' => 'array'],
                    'scenes'    => ['type' => 'array'],
                ],
            ],
            'execute_callback' => function( $input ) {
                return ( new \WorldGraph\AI\AI_Context_Builder() )->build_character_context(
                    (int) $input['character_id']
                );
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'public' => true,
                'mcp'    => [ 'type' => 'resource' ],
                'uri'    => 'worldgraph://character/{character_id}',
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );

        // worldgraph/scene-context - Get scene context.
        $this->register_ability( 'worldgraph/scene-context', [
            'label'       => 'Scene Context',
            'description' => 'Retrieve scene metadata, characters, and adjacent scenes.',
            'input_schema' => [
                'type'  => 'object',
                'properties' => [
                    'scene_id' => [
                        'type'        => 'integer',
                        'description' => 'Scene post ID.',
                    ],
                ],
                'required' => ['scene_id'],
            ],
            'output_schema' => [
                'type'  => 'object',
                'properties' => [
                    'scene'     => ['type' => 'object'],
                    'characters'=> ['type' => 'array'],
                    'previous'  => ['type' => 'object'],
                    'next'      => ['type' => 'object'],
                ],
            ],
            'execute_callback' => function( $input ) {
                return ( new \WorldGraph\AI\AI_Context_Builder() )->build_scene_context(
                    (int) $input['scene_id']
                );
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'public' => true,
                'mcp'    => [ 'type' => 'resource' ],
                'uri'    => 'worldgraph://scene/{scene_id}',
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );
    }
}

/**
 * Prompt abilities for MCP prompt templates.
 *
 * Provides structured prompt templates for common AI tasks.
 */
class Prompt_Templates extends AbstractAbilityGroup {
    protected $slug       = 'worldgraph-prompts';
    protected $label      = 'Prompt Templates';
    protected $description = 'Structured prompt templates for story review and continuity checking.';

    public function register(): void {
        // worldgraph/templates-manifest - Discover active generation templates.
        $this->register_ability( 'worldgraph/templates-manifest', [
            'label'       => 'Generation Templates Manifest',
            'description' => 'Discover active World Graph Studio generation templates and their provider-neutral schemas.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [],
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'templates' => [
                        'type'  => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id'                  => [ 'type' => 'integer' ],
                                'slug'                => [ 'type' => 'string' ],
                                'name'                => [ 'type' => 'string' ],
                                'description'         => [ 'type' => 'string' ],
                                'generation_structure' => [ 'type' => 'string' ],
                                'modality'            => [ 'type' => 'string' ],
                                'output_type'         => [ 'type' => 'string' ],
                                'inputs'              => [ 'type' => 'object' ],
                                'required_nodes'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                                'models'              => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                                'provider_type'       => [ 'type' => 'string' ],
                                'version'             => [ 'type' => 'string' ],
                                'configuration_schema' => [ 'type' => 'object' ],
                                'default_values'      => [ 'type' => 'object' ],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback' => function() {
				\WorldGraph\Utils\Connection_Adapters::load( 'comfyui' );
                $templates = get_posts( [
                    'post_type'      => 'worldgraph_template',
                    'post_status'    => 'publish',
                    'posts_per_page' => -1,
                    'meta_key'       => 'status',
                    'meta_value'     => 'active',
                    'orderby'        => 'title',
                    'order'          => 'ASC',
                ] );

                $manifest = [];
                foreach ( $templates as $template ) {
                    $configuration = json_decode( (string) \WorldGraph\Utils\worldgraph_get_field_value( $template->ID, 'configuration_json' ), true );
                    $defaults = json_decode( (string) \WorldGraph\Utils\worldgraph_get_field_value( $template->ID, 'default_values' ), true );
                    $requirements = \WorldGraph\Utils\Comfy_Manifest::for_template( (int) $template->ID );

                    $manifest[] = [
                        'id'                   => (int) $template->ID,
                        'slug'                 => (string) $template->post_name,
                        'name'                 => (string) \WorldGraph\Utils\worldgraph_get_field_value( $template->ID, 'template_name' ),
                        'description'          => wp_strip_all_tags( (string) \WorldGraph\Utils\worldgraph_get_field_value( $template->ID, 'description' ) ),
                        'generation_structure' => (string) \WorldGraph\Utils\worldgraph_get_field_value( $template->ID, 'generation_structure' ),
                        'modality'             => is_wp_error( $requirements ) ? '' : (string) $requirements['modality'],
                        'output_type'          => is_wp_error( $requirements ) ? '' : (string) $requirements['output_type'],
                        'inputs'               => is_wp_error( $requirements ) ? [] : $requirements['inputs'],
                        'required_nodes'       => is_wp_error( $requirements ) ? [] : $requirements['nodes'],
                        'models'               => is_wp_error( $requirements ) ? [] : $requirements['models'],
                        'provider_type'        => (string) \WorldGraph\Utils\worldgraph_get_field_value( $template->ID, 'provider_type' ),
                        'version'              => (string) \WorldGraph\Utils\worldgraph_get_field_value( $template->ID, 'version' ),
                        'configuration_schema' => is_array( $configuration ) ? $configuration : [],
                        'default_values'       => is_array( $defaults ) ? $defaults : [],
                    ];
                }

                return [ 'templates' => $manifest ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'public' => true,
                'mcp'    => [ 'type' => 'resource' ],
                'uri'     => 'worldgraph://templates-manifest',
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );

        // worldgraph/template-requirements - Reciprocal ComfyUI requirement discovery.
        $this->register_ability( 'worldgraph/template-requirements', [
            'label'       => 'Generation Template Requirements',
            'description' => 'Report the ComfyUI node types and model files a World Graph Studio generation Template needs, and whether the configured ComfyUI instance already has them.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'template_id' => [ 'type' => 'integer', 'description' => 'Template post ID.' ],
                    'validate'    => [ 'type' => 'boolean', 'description' => 'Check the requirements against the configured ComfyUI instance.' ],
                ],
                'required'   => [ 'template_id' ],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'modality'    => [ 'type' => 'string' ],
                    'output_type' => [ 'type' => 'string' ],
                    'inputs'      => [ 'type' => 'object' ],
                    'nodes'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'models'      => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                    'downloads'   => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
                    'validation'  => [ 'type' => 'object' ],
                ],
            ],
            'execute_callback' => function( $input ) {
				\WorldGraph\Utils\Connection_Adapters::load( 'comfyui' );
                $template_id = (int) ( $input['template_id'] ?? 0 );
                $manifest = \WorldGraph\Utils\Comfy_Manifest::for_template( $template_id );
                if ( is_wp_error( $manifest ) ) {
                    return $manifest;
                }

                if ( isset( $input['validate'] ) && ! $input['validate'] ) {
                    return $manifest;
                }

                $report = \WorldGraph\Utils\Comfy_Manifest::validate( $template_id );
                $manifest['validation'] = is_wp_error( $report )
                    ? [ 'ok' => false, 'error' => $report->get_error_message() ]
                    : $report;

                return $manifest;
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'public' => true,
                'mcp'    => [ 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );

        // worldgraph/story-review-prompt - Story review prompt template.
        $this->register_ability( 'worldgraph/story-review-prompt', [
            'label'       => 'Story Review Prompt',
            'description' => 'Generate a structured prompt for AI story review and feedback.',
            'input_schema' => [
                'type'  => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID to review.',
                    ],
                    'focus' => [
                        'type'        => 'string',
                        'description' => 'Review focus: dialogue, plot, pacing, character (optional).',
                    ],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'  => 'object',
                'properties' => [
                    'system_prompt' => ['type' => 'string'],
                    'user_prompt'   => ['type' => 'string'],
                ],
            ],
            'execute_callback' => function( $input ) {
                $context_builder = new \WorldGraph\AI\AI_Context_Builder();
                $context = $context_builder->build_post_context(
                    (int) $input['post_id']
                );
                $post = get_post( (int) $input['post_id'] );
                $focus = isset( $input['focus'] ) ? $input['focus'] : 'story';

                $system_prompt = "You are a story review expert using the World Graph Studio framework. "
                    . "Review the content for narrative quality, structure, and consistency.";

                $user_prompt = "Review this content with a focus on: {$focus}\n\n"
                    . "Title: {$post->post_title}\n\n"
                    . "Content:\n{$post->post_content}\n\n";

                if ( ! empty( $context ) ) {
                    $user_prompt .= "Story Graph Context:\n"
                        . $context_builder->build_context_for_llm( $context ) . "\n\n";
                }

                return [
                    'system_prompt' => $system_prompt,
                    'user_prompt'   => $user_prompt,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'public' => true,
                'mcp'    => [ 'type' => 'prompt' ],
                'arguments' => [
                    [
                        'name'        => 'post_id',
                        'description' => 'Post ID to review.',
                        'type'        => 'integer',
                        'required'    => true,
                    ],
                    [
                        'name'        => 'focus',
                        'description' => 'Review focus area.',
                        'type'        => 'string',
                        'required'    => false,
                    ],
                ],
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );

        // worldgraph/continuity-prompt - Continuity check prompt template.
        $this->register_ability( 'worldgraph/continuity-prompt', [
            'label'       => 'Continuity Check Prompt',
            'description' => 'Generate a structured prompt for AI continuity checking.',
            'input_schema' => [
                'type'  => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID to check.',
                    ],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'  => 'object',
                'properties' => [
                    'system_prompt' => ['type' => 'string'],
                    'user_prompt'   => ['type' => 'string'],
                ],
            ],
            'execute_callback' => function( $input ) {
                $context_builder = new \WorldGraph\AI\AI_Context_Builder();
                $context = $context_builder->build_post_context(
                    (int) $input['post_id']
                );
                $post = get_post( (int) $input['post_id'] );

                $system_prompt = "You are a continuity expert using the World Graph Studio framework. "
                    . "Check the content for continuity errors against the Story Graph data.";

                $user_prompt = "Check this content for continuity errors:\n\n"
                    . "Title: {$post->post_title}\n\n"
                    . "Content:\n{$post->post_content}\n\n";

                if ( ! empty( $context ) ) {
                    $user_prompt .= "Story Graph Context:\n"
                        . $context_builder->build_context_for_llm( $context ) . "\n\n";
                }

                return [
                    'system_prompt' => $system_prompt,
                    'user_prompt'   => $user_prompt,
                ];
            },
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
            'meta' => [
                'public' => true,
                'mcp'    => [ 'type' => 'prompt' ],
                'arguments' => [
                    [
                        'name'        => 'post_id',
                        'description' => 'Post ID to check.',
                        'type'        => 'integer',
                        'required'    => true,
                    ],
                ],
                'annotations' => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ] );
    }
}

/**
 * Generate asset abilities.
 *
 * Text-to-image generation for World Graph Studio story elements, stored in the media
 * library and linked back to the source post.
 */
class Asset_Abilities extends AbstractAbilityGroup {
    protected $slug       = 'worldgraph-assets';
    protected $label      = 'Generate Assets';
    protected $description = 'Generate an initial image for a story element and attach it to the post.';

    public function register(): void {
        // worldgraph/suggest-asset-prompt - Build a text-to-image prompt from a story element.
        $this->register_ability( 'worldgraph/suggest-asset-prompt', [
            'label'       => 'Suggest Asset Prompt',
            'description' => 'Build a text-to-image prompt from a World Graph Studio story element.',
            'input_schema' => [
                'type'  => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Story element post ID.',
                    ],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'  => 'object',
                'properties' => [
                    'prompt' => ['type' => 'string'],
                ],
            ],
            'execute_callback' => function( $input ) {
                return [
                    'prompt' => \WorldGraph\Utils\Asset_Generator::build_prompt( (int) $input['post_id'] ),
                ];
            },
            'permission_callback' => function( $input ) {
                return current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) );
            },
        ] );

        // worldgraph/generate-asset - Generate and attach an image or Shot video.
        $this->register_ability( 'worldgraph/generate-asset', [
            'label'       => 'Generate Asset Media',
            'description' => 'Queue a story-aware image or Shot video generation for a story element.',
            'input_schema' => [
                'type'  => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Story element post ID.',
                    ],
                    'type' => [
                        'type'        => 'string',
                        'enum'        => ['image', 'video'],
                        'description' => 'Output type. Video is available when the item workflow defines it.',
                    ],
                    'prompt'  => [
                        'type'        => 'string',
                        'description' => 'Optional instructions appended to the prompt built from the story element.',
                    ],
                    'set_featured' => [
                        'type'        => 'boolean',
                        'description' => 'Set the generated image as the featured asset.',
                    ],
                    'create_asset' => [
                        'type'        => 'boolean',
                        'description' => 'Create a linked World Graph Studio Asset record.',
                    ],
                    'template_id' => [
                        'type'        => 'integer',
                        'description' => 'Optional active Template ID matching the output type. The representative-media default is resolved when omitted.',
                    ],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'  => 'object',
                'properties' => [
                    'generation_id' => ['type' => 'integer'],
                    'status'        => ['type' => 'string'],
                    'prompt'        => ['type' => 'string'],
                    'type'          => ['type' => 'string'],
                    'intent'        => ['type' => 'string'],
                ],
            ],
            'execute_callback' => function( $input ) {
                $plan = \WorldGraph\Utils\Generation_Workflows::plan( (int) $input['post_id'], 'item' );
                if ( is_wp_error( $plan ) ) {
                    return $plan;
                }
                $type = 'video' === sanitize_key( (string) ( $input['type'] ?? 'image' ) ) ? 'video' : 'image';
                $task = [];
                foreach ( (array) ( $plan['tasks'] ?? [] ) as $candidate ) {
                    if ( $type === ( $candidate['type'] ?? '' ) ) {
                        $task = (array) $candidate;
                        break;
                    }
                }
                if ( ! $task ) {
                    return new \WP_Error(
                        'worldgraph_generation_output_unavailable',
                        'video' === $type
                            ? 'This item has no direct video output. Generate video from a Shot.'
                            : 'This item has no direct image output.'
                    );
                }
                $template_id = absint( $input['template_id'] ?? 0 );
                if ( ! $template_id && $task ) {
                    $template_id = \WorldGraph\Utils\Generation_Workflows::resolve_template_id( $task );
                }
                return \WorldGraph\Utils\Asset_Generator::queue_for_post( (int) $input['post_id'], [
                    'type'         => $type,
                    'prompt'       => (string) ( $input['prompt'] ?? '' ),
                    'set_featured' => $input['set_featured'] ?? true,
                    'create_asset' => $input['create_asset'] ?? true,
                    'template_id'  => $template_id,
                    'intent'       => (string) ( $task['intent'] ?? '' ),
                ] );
            },
            'permission_callback' => function( $input ) {
                return current_user_can( 'edit_post', (int) ( $input['post_id'] ?? 0 ) )
                    && current_user_can( 'upload_files' );
            },
            'meta' => [
                'public' => true,
                'mcp'    => [ 'type' => 'tool' ],
                'annotations' => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ],
            ],
        ] );
    }
}

/**
 * Main World Graph Studio Abilities class.
 *
 * Registers the World Graph Studio AI Editor category and all ability groups.
 * Follows the SCF pattern of group-based registration.
 */
class Abilities {
    /**
     * Ability group instances.
     *
     * @var array
     */
    private $ability_groups = [];

    /**
     * Singleton instance.
     *
     * @var Abilities
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return Abilities
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor (singleton pattern).
     */
    private function __construct() {
        $this->ability_groups = [
            new Context_Resources(),
            new Prompt_Templates(),
            new Asset_Abilities(),
        ];

        if ( self::has_llm_endpoint() ) {
            array_unshift( $this->ability_groups, new Chat_Abilities() );
        }
    }

    /**
     * Determine whether an LLM endpoint has been configured for agent abilities.
     *
     * @return bool
     */
    private static function has_llm_endpoint(): bool {
        $url = trim( (string) get_option( 'worldgraph_ai_url', '' ) );

        return (bool) filter_var( $url, FILTER_VALIDATE_URL )
            && in_array( wp_parse_url( $url, PHP_URL_SCHEME ), [ 'http', 'https' ], true );
    }

    /**
     * Initialize abilities registration.
     *
     * Hooked into 'init' action.
     */
    public function init(): void {
        // Register the World Graph Studio AI Editor category.
        $this->register_category();

        // Register all ability groups.
        foreach ( $this->ability_groups as $group ) {
            $group->register();
        }
    }

    /**
     * Register the World Graph Studio AI Editor ability category.
     *
     * @return WP_Error|int Result of wp_register_ability_category.
     */
    private function register_category() {
        return \wp_register_ability_category( 'worldgraph-ai-editor', [
            'label'       => 'World Graph Studio AI Editor',
            'description' => 'Abilities for AI-powered story editing, content generation, and continuity checking.',
            'meta'        => [
                'public' => true,
            ],
        ] );
    }

    /**
     * Get registered ability groups.
     *
     * @return array
     */
    public function get_groups(): array {
        return $this->ability_groups;
    }

    /**
     * Get a specific ability group by slug.
     *
     * @param string $slug Group slug.
     * @return AbstractAbilityGroup|null
     */
    public function get_group( string $slug ): ?AbstractAbilityGroup {
        foreach ( $this->ability_groups as $group ) {
            if ( $group->get_slug() === $slug ) {
                return $group;
            }
        }
        return null;
    }
}
