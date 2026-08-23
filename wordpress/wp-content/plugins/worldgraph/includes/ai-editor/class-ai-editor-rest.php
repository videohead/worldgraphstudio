<?php
/**
 * AI Editor REST Controller — handles /worldgraph/v1/ai/* endpoints.
 *
 * @package WorldGraph
 */

namespace WorldGraph\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Editor REST controller class.
 */
class AI_Editor_REST {

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route( 'worldgraph/v1', '/ai/chat', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'chat' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'prompt'        => [
					'required' => true,
					'type'     => 'string',
				],
				'post_id'       => [
					'required' => false,
					'type'     => 'integer',
				],
				'agent'         => [
					'required' => false,
					'type'     => 'string',
				],
				'action'        => [
					'required' => false,
					'type'     => 'string',
					'default'  => 'chat',
				],
				'messages'      => [
					'required' => false,
					'type'     => 'array',
					'default'  => [],
					'items'    => [
						'type'       => 'object',
						'properties' => [
							'role'    => [ 'type' => 'string' ],
							'content' => [ 'type' => 'string' ],
						],
					],
				],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/ai/analyze', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'analyze' ],
			'permission_callback' => [ $this, 'check_optional_edit_post_permission' ],
			'args'                => [
				'prompt'    => [
					'required' => true,
					'type'     => 'string',
				],
				'post_id'   => [
					'required' => false,
					'type'     => 'integer',
				],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/ai/generate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate' ],
			'permission_callback' => [ $this, 'check_optional_edit_post_permission' ],
			'args'                => [
				'prompt'    => [
					'required' => true,
					'type'     => 'string',
				],
				'post_id'   => [
					'required' => false,
					'type'     => 'integer',
				],
				'agent'     => [
					'required' => false,
					'type'     => 'string',
				],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/ai/continuity', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'continuity_check' ],
			'permission_callback' => [ $this, 'check_edit_post_permission' ],
			'args'                => [
				'post_id' => [
					'required' => true,
					'type'     => 'integer',
				],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/ai/context', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_context' ],
			'permission_callback' => [ $this, 'check_read_post_permission' ],
			'args'                => [
				'post_id' => [
					'required' => true,
					'type'     => 'integer',
				],
			],
		] );

		register_rest_route( 'worldgraph/v1', '/ai/agents', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_agents' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( 'worldgraph/v1', '/ai/settings', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_settings' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( 'worldgraph/v1', '/ai/health', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'health_check' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );
	}

	/**
	 * Chat endpoint.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response REST response.
	 */
	public function chat( \WP_REST_Request $request ): \WP_REST_Response {
		// Sanitize all input parameters.
		$prompt    = sanitize_textarea_field( $request->get_param( 'prompt' ) );
		$post_id   = absint( $request->get_param( 'post_id' ) );
		$agent     = sanitize_text_field( $request->get_param( 'agent' ) );
		$action    = sanitize_text_field( $request->get_param( 'action' ) ) ?: 'chat';
		$messages  = $this->sanitize_chat_messages( $request->get_param( 'messages' ) );

		if ( empty( $prompt ) || strlen( $prompt ) > 10000 || is_wp_error( $messages ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => is_wp_error( $messages ) ? $messages->get_error_message() : __( 'Invalid prompt length.', 'worldgraph' ),
			], 400 );
		}

		// Validate action against allowed values.
		$allowed_actions = [ 'chat', 'analyze', 'generate', 'continuity' ];
		if ( ! in_array( $action, $allowed_actions, true ) ) {
			$action = 'chat';
		}

		$maf_bridge     = new AI_MAF_Bridge( new AI_LLM_Client() );
		$enabled_agents = $maf_bridge->get_enabled_agents();
		if ( ! empty( $agent ) && ! isset( $enabled_agents[ $agent ] ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => __( 'The selected agent is not available.', 'worldgraph' ),
			], 400 );
		}

		// Build context if post_id provided.
		$context = [];
		if ( $post_id ) {
			// Verify post exists and user has permission.
			if ( ! get_post( $post_id ) ) {
				return new \WP_REST_Response( [
					'success' => false,
					'error'   => __( 'Invalid post ID.', 'worldgraph' ),
				], 400 );
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new \WP_REST_Response( [
					'success' => false,
					'error'   => __( 'You cannot use this post as chat context.', 'worldgraph' ),
				], 403 );
			}
			$post_type = get_post_type( $post_id );
			if ( ! $post_type ) {
				return new \WP_REST_Response( [
					'success' => false,
					'error'   => 'Invalid post ID.',
				], 400 );
			}

			$context_builder = new AI_Context_Builder();
			$context = $context_builder->build_post_context( $post_id );
		}

		// Route to agent if not specified.
		if ( empty( $agent ) ) {
			$router = new AI_Agent_Router();
			$route_result = $router->route( $prompt );
			$agent = $route_result['agent'];
			if ( ! isset( $enabled_agents[ $agent ] ) ) {
				$agent = (string) array_key_first( $enabled_agents );
			}
		}

		if ( empty( $agent ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => __( 'No filmmaking agents are enabled.', 'worldgraph' ),
			], 503 );
		}

		// Get agent skills for this context.
		$agent_skills = new AI_Agent_Skills();
		$post_type = $context['post_type'] ?? '';
		$content = $context['content'] ?? '';
		$skill_content = '';
		$relevant_skills = $agent_skills->detect_relevant_skills( $post_type, $content );
		if ( ! empty( $relevant_skills ) ) {
			$skill_sections = [];
			foreach ( $relevant_skills as $skill_name ) {
				$skill = $agent_skills->get_skill( $skill_name );
				if ( $skill && ! empty( $skill['content'] ) ) {
					$skill_sections[] = $skill['content'];
				}
			}
			$skill_content = implode( "\n\n", $skill_sections );
		}

		// Get the agent's system prompt.
		$agent_data = $maf_bridge->get_agent( $agent );
		$system_prompt = $agent_data['system_prompt'] ?? '';
		$action_prompts = [
			'analyze'    => __( 'Analyze the current Story Graph element in response to the user. Be specific, constructive, and production-aware.', 'worldgraph' ),
			'generate'   => __( 'Develop a concrete creative or production suggestion in response to the user. Do not modify WordPress content.', 'worldgraph' ),
			'continuity' => __( 'Focus on continuity risks involving character, timeline, location, props, wardrobe, and production feasibility.', 'worldgraph' ),
		];
		if ( isset( $action_prompts[ $action ] ) ) {
			$system_prompt .= "\n\nTurn instruction: " . $action_prompts[ $action ];
		}

		// Add skill content to system prompt.
		if ( ! empty( $skill_content ) ) {
			$system_prompt .= "\n\n" . $skill_content;
		}

		// Call the LLM.
		$llm_client = new AI_LLM_Client();
		$result = $llm_client->chat( $prompt, [
			'system_prompt' => $system_prompt,
			'context'       => $context,
			'messages'      => $messages,
		] );

		return new \WP_REST_Response( [
			'success' => empty( $result['error'] ),
			'data'    => $result['content'] ?? '',
			'agent'   => esc_html( $agent ),
			'backend' => esc_html( $result['backend'] ?? 'unknown' ),
			'action'  => esc_html( $action ),
			'post_id' => $post_id,
			'error'   => ! empty( $result['error'] ) ? esc_html( $result['error'] ) : null,
		], empty( $result['error'] ) ? 200 : 500 );
	}

	/**
	 * Sanitize bounded prior chat turns supplied by the browser.
	 *
	 * System messages are intentionally rejected: the server owns the system
	 * prompt and Story Graph context.
	 *
	 * @param mixed $messages Raw REST parameter.
	 * @return array<int, array{role:string,content:string}>|\WP_Error
	 */
	private function sanitize_chat_messages( $messages ) {
		if ( null === $messages || [] === $messages ) {
			return [];
		}

		if ( ! is_array( $messages ) || count( $messages ) > 20 ) {
			return new \WP_Error( 'invalid_chat_history', __( 'Chat history must contain at most 20 messages.', 'worldgraph' ) );
		}

		$sanitized = [];
		$total_length = 0;
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] ) || ! in_array( $message['role'], [ 'user', 'assistant' ], true ) ) {
				return new \WP_Error( 'invalid_chat_message', __( 'Each chat message must have a user or assistant role and content.', 'worldgraph' ) );
			}

			$content = sanitize_textarea_field( (string) $message['content'] );
			$total_length += strlen( $content );
			if ( '' === $content || strlen( $content ) > 10000 || $total_length > 40000 ) {
				return new \WP_Error( 'invalid_chat_history', __( 'Chat history is empty or exceeds the allowed size.', 'worldgraph' ) );
			}

			$sanitized[] = [
				'role'    => $message['role'],
				'content' => $content,
			];
		}

		return $sanitized;
	}

	/**
	 * Analyze endpoint.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response REST response.
	 */
	public function analyze( \WP_REST_Request $request ): \WP_REST_Response {
		// Sanitize input parameters.
		$prompt        = sanitize_text_field( $request->get_param( 'prompt' ) );
		$post_id       = absint( $request->get_param( 'post_id' ) );
		$authorization = $this->check_optional_edit_post_permission( $request );
		if ( true !== $authorization ) {
			return $this->authorization_error_response( $authorization );
		}

		// Validate prompt length to prevent abuse.
		if ( empty( $prompt ) || strlen( $prompt ) > 10000 ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'Invalid prompt length.',
			], 400 );
		}

		$context_builder = new AI_Context_Builder();
		$context = $post_id ? $context_builder->build_post_context( $post_id ) : [];

		$analysis_prompt = "Analyze the following content and provide detailed feedback:\n\n{$prompt}\n\n";
		if ( ! empty( $context ) ) {
			$analysis_prompt .= "\nContext:\n" . $context_builder->build_context_for_llm( $context );
		}

		$llm_client = new AI_LLM_Client();
		$result = $llm_client->chat( $analysis_prompt, [
			'system_prompt' => 'You are an expert film and content analyst. Provide detailed, constructive analysis.',
			'context'       => $context,
		] );

		return new \WP_REST_Response( [
			'success' => empty( $result['error'] ),
			'data'    => $result['content'] ?? '',
			'error'   => ! empty( $result['error'] ) ? esc_html( $result['error'] ) : null,
		], empty( $result['error'] ) ? 200 : 500 );
	}

	/**
	 * Generate endpoint.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response REST response.
	 */
	public function generate( \WP_REST_Request $request ): \WP_REST_Response {
		// Sanitize input parameters.
		$prompt        = sanitize_text_field( $request->get_param( 'prompt' ) );
		$post_id       = absint( $request->get_param( 'post_id' ) );
		$agent         = sanitize_text_field( $request->get_param( 'agent' ) );
		$authorization = $this->check_optional_edit_post_permission( $request );
		if ( true !== $authorization ) {
			return $this->authorization_error_response( $authorization );
		}

		// Validate prompt length.
		if ( empty( $prompt ) || strlen( $prompt ) > 10000 ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => 'Invalid prompt length.',
			], 400 );
		}

		$context_builder = new AI_Context_Builder();
		$context = $post_id ? $context_builder->build_post_context( $post_id ) : [];

		// Route to agent if not specified.
		if ( empty( $agent ) ) {
			$router = new AI_Agent_Router();
			$route_result = $router->route( $prompt );
			$agent = $route_result['agent'];
		}

		$maf_bridge = new AI_MAF_Bridge( new AI_LLM_Client() );
		$result = $maf_bridge->run_agent( $agent, $prompt, $context );

		return new \WP_REST_Response( [
			'success' => empty( $result['error'] ),
			'data'    => $result['content'] ?? '',
			'agent'   => esc_html( $agent ),
			'error'   => ! empty( $result['error'] ) ? esc_html( $result['error'] ) : null,
		], empty( $result['error'] ) ? 200 : 500 );
	}

	/**
	 * Continuity check endpoint.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response REST response.
	 */
	public function continuity_check( \WP_REST_Request $request ): \WP_REST_Response {
		// Sanitize input.
		$post_id       = absint( $request->get_param( 'post_id' ) );
		$authorization = $this->check_edit_post_permission( $request );
		if ( true !== $authorization ) {
			return $this->authorization_error_response( $authorization );
		}

		$context_builder = new AI_Context_Builder();
		$context = $context_builder->build_post_context( $post_id );

		$continuity_prompt = "Check the following scene for continuity errors with the overall story:\n\n";
		if ( isset( $context['scene_content'] ) ) {
			$continuity_prompt .= "Scene: " . wp_strip_all_tags( $context['scene_content'] ) . "\n\n";
		}
		$continuity_prompt .= "Check for:\n1. Character consistency\n2. Timeline errors\n3. Location inconsistencies\n4. Plot holes\n\n";
		if ( isset( $context['project_logline'] ) ) {
			$continuity_prompt .= "Project Logline: " . wp_strip_all_tags( $context['project_logline'] ) . "\n\n";
		}

		$llm_client = new AI_LLM_Client();
		$result = $llm_client->chat( $continuity_prompt, [
			'system_prompt' => 'You are a continuity expert. Identify any inconsistencies in the story.',
			'context'       => $context,
		] );

		return new \WP_REST_Response( [
			'success' => empty( $result['error'] ),
			'data'    => $result['content'] ?? '',
			'error'   => ! empty( $result['error'] ) ? esc_html( $result['error'] ) : null,
		], empty( $result['error'] ) ? 200 : 500 );
	}

	/**
	 * Get context endpoint.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response REST response.
	 */
	public function get_context( \WP_REST_Request $request ): \WP_REST_Response {
		// Sanitize input.
		$post_id       = absint( $request->get_param( 'post_id' ) );
		$authorization = $this->check_read_post_permission( $request );
		if ( true !== $authorization ) {
			return $this->authorization_error_response( $authorization );
		}

		$context_builder = new AI_Context_Builder();
		$context = $context_builder->build_post_context( $post_id );

		return new \WP_REST_Response( [
			'success' => true,
			'data'    => $context,
		], 200 );
	}

	/**
	 * Get agents endpoint.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response REST response.
	 */
	public function get_agents( \WP_REST_Request $request ): \WP_REST_Response {
		$maf_bridge = new AI_MAF_Bridge( new AI_LLM_Client() );
		$agents = $maf_bridge->get_enabled_agents();

		// Format for frontend with proper escaping.
		$formatted = [];
		foreach ( $agents as $name => $agent ) {
			$formatted[] = [
				'name'        => esc_html( $name ),
				'label'       => esc_html( trim( preg_replace( '/(?<!^)([A-Z])/', ' $1', $name ) ) ),
				'description' => esc_html( $agent['description'] ?? '' ),
				'department'  => esc_html( $agent['department'] ?? '' ),
			];
		}

		return new \WP_REST_Response( [
			'success' => true,
			'data'    => $formatted,
		], 200 );
	}

	/**
	 * Get settings endpoint.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response REST response.
	 */
	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'success' => true,
			'data'    => [
				'backend'        => esc_html( get_option( 'worldgraph_ai_backend', 'local' ) ),
				'url'            => esc_url_raw( get_option( 'worldgraph_ai_url', 'http://localhost:11434' ) ),
				'model'          => esc_html( get_option( 'worldgraph_ai_model', 'qwen3.6:35b-a3b-q4_K_M' ) ),
				'max_tokens'     => absint( get_option( 'worldgraph_ai_max_tokens', 4096 ) ),
				'temperature'    => floatval( get_option( 'worldgraph_ai_temperature', 0.7 ) ),
				'fallback_enabled' => rest_sanitize_boolean( get_option( 'worldgraph_ai_fallback_enabled', true ) ),
				'rate_limit'     => absint( get_option( 'worldgraph_ai_rate_limit', 10 ) ),
				'cache_ttl'      => absint( get_option( 'worldgraph_ai_cache_ttl', 3600 ) ),
			],
		], 200 );
	}

	/**
	 * Health check endpoint.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response REST response.
	 */
	public function health_check( \WP_REST_Request $request ): \WP_REST_Response {
		$llm_client = new AI_LLM_Client();
		$health = $llm_client->health_check();

		return new \WP_REST_Response( [
			'success' => true,
			'data'    => [
				'backend'       => $health['backend'] ?? 'unknown',
				'healthy'       => ! empty( $health['healthy'] ),
				'error'         => $health['error'] ?? '',
				'url'           => $health['url'] ?? '',
				'cache_enabled' => true,
				'rate_limiting' => true,
			],
		], 200 );
	}

	/**
	 * Check permission for AI endpoints with an optional editable post context.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error True when authorized, otherwise false or an error.
	 */
	public function check_optional_edit_post_permission( \WP_REST_Request $request ) {
		return $this->check_post_permission( $request, 'edit_post', false );
	}

	/**
	 * Check permission for AI endpoints that require an editable post context.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error True when authorized, otherwise false or an error.
	 */
	public function check_edit_post_permission( \WP_REST_Request $request ) {
		return $this->check_post_permission( $request, 'edit_post', true );
	}

	/**
	 * Check permission for AI endpoints that return a readable post context.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error True when authorized, otherwise false or an error.
	 */
	public function check_read_post_permission( \WP_REST_Request $request ) {
		return $this->check_post_permission( $request, 'read_post', true );
	}

	/**
	 * Authorize access to a post supplied as AI context.
	 *
	 * @param \WP_REST_Request $request    REST request.
	 * @param string           $capability Object-level post capability.
	 * @param bool             $required   Whether the request must include a post ID.
	 * @return bool|\WP_Error True when authorized, otherwise false or an error.
	 */
	private function check_post_permission( \WP_REST_Request $request, string $capability, bool $required ) {
		if ( ! $this->check_permission() ) {
			return false;
		}

		$raw_post_id = $request->get_param( 'post_id' );
		if ( null === $raw_post_id || '' === $raw_post_id ) {
			if ( ! $required ) {
				return true;
			}

			return new \WP_Error(
				'worldgraph_ai_post_invalid',
				__( 'A valid post ID is required.', 'worldgraph' ),
				[ 'status' => 400 ]
			);
		}

		$post_id = filter_var(
			$raw_post_id,
			FILTER_VALIDATE_INT,
			[ 'options' => [ 'min_range' => 1 ] ]
		);
		if ( false === $post_id ) {
			return new \WP_Error(
				'worldgraph_ai_post_invalid',
				__( 'A valid post ID is required.', 'worldgraph' ),
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'worldgraph_ai_post_invalid',
				__( 'The requested post does not exist.', 'worldgraph' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! current_user_can( $capability, $post_id ) ) {
			return new \WP_Error(
				'worldgraph_ai_post_forbidden',
				__( 'You are not allowed to use this post as AI context.', 'worldgraph' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Convert an authorization failure into the endpoint response shape.
	 *
	 * REST dispatch normally returns permission errors before invoking a handler.
	 * This keeps the same checks effective when handlers are called directly.
	 *
	 * @param bool|\WP_Error $authorization Authorization result.
	 * @return \WP_REST_Response REST response.
	 */
	private function authorization_error_response( $authorization ): \WP_REST_Response {
		$status  = 403;
		$message = __( 'You are not allowed to use this AI endpoint.', 'worldgraph' );

		if ( is_wp_error( $authorization ) ) {
			$message = $authorization->get_error_message();
			$data    = $authorization->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$status = absint( $data['status'] );
			}
		}

		return new \WP_REST_Response( [
			'success' => false,
			'error'   => $message,
		], $status );
	}

	/**
	 * Check permission for AI endpoints.
	 *
	 * @return bool True if user has permission.
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'edit_posts' );
	}
}
