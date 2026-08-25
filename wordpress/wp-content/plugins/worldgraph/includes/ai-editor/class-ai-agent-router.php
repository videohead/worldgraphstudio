<?php
/**
 * AI Agent Router — routes user requests to appropriate advisors.
 *
 * @package WorldGraph
 */

namespace WorldGraph\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent Router class.
 */
class AI_Agent_Router {

	/**
	 * Keyword-to-agent mappings.
	 *
	 * @var array
	 */
	private $keyword_mappings = [
		'story_search' => [
			'keywords' => [
				'story graph search', 'find in the graph', 'graph search',
				'entity search', 'connected to', 'entities connected', 'related entity',
				'story entity', 'in the story graph',
			],
			'agents' => [ 'StoryGraphAnalyst' ],
		],
		'continuity' => [
			'keywords' => [
				'continuity', 'continuity issue', 'continuity error', 'continuity check',
				'continuity problem', 'relationship conflict', 'orphaned asset',
			],
			'agents' => [ 'ContinuityAnalyst' ],
		],
		'notes_intake' => [
			'keywords' => [
				'organize my notes', 'story notes', 'random notes', 'scattered notes',
				'turn my notes into', 'brainstorm into', 'notes into a story graph',
				'new to world graph', 'where do i start', 'import my outline',
			],
			'agents' => [ 'StoryNotesOrganizer' ],
		],
		'relationships' => [
			'keywords' => [
				'relationship analytics', 'relationship analysis', 'graph density',
				'co-occurrence', 'connected entities', 'isolated entity',
			],
			'agents' => [ 'RelationshipAnalyst' ],
		],
		'development' => [
			'keywords' => [
				'development compass', 'develop next', 'story development',
				'missing foundation', 'scene exposure', 'next story graph element',
			],
			'agents' => [ 'DevelopmentAdvisor' ],
		],
		'comfyui' => [
			'keywords' => [
				'comfyui', 'comfy ui', 'workflow', 'checkpoint', 'sampler',
				'custom node', 'model file', 'safetensors', 'generation server',
			],
			'agents' => [ 'ComfyTechnician' ],
		],
		'story' => [
			'keywords' => [
				'character', 'dialogue', 'plot', 'scene', 'story', 'narrative',
				'arc', 'conflict', 'resolution', 'protagonist', 'antagonist',
				'write', 'draft', 'rewrite', 'edit', 'continue',
			],
			'agents' => [ 'Screenwriter', 'Director' ],
		],
		'prompt' => [
			'keywords' => [
				'prompt', 'image', 'generate', 'visual', 'scene', 'shot',
				'composition', 'lighting', 'camera', 'style', 'look',
				'concept', 'art', 'illustration', 'render',
			],
			'agents' => [ 'PrevisualizationArtist', 'ArtDirector' ],
		],
		'production' => [
			'keywords' => [
				'schedule', 'budget', 'crew', 'cast', 'location', 'permit',
				'call sheet', 'production', 'logistics', 'resource',
				'equipment', 'transport', 'catering', 'insurance',
			],
			'agents' => [ 'Producer', 'CameraOperator', 'SoundMixer', 'KeyGrip' ],
		],
		'technical' => [
			'keywords' => [
				'format', 'spec', 'template', 'technical', 'export',
				'import', 'csv', 'json', 'xml', 'edl', 'cff',
				'screenplay', 'final draft', 'fade in', 'fade out',
			],
			'agents' => [ 'DIT', 'Editor' ],
		],
		'editorial' => [
			'keywords' => [
				'continuity', 'consistency', 'check', 'review', 'analyze',
				'feedback', 'critique', 'suggestion', 'improve',
				'pacing', 'rhythm', 'flow', 'structure',
			],
			'agents' => [ 'Editor', 'ScriptSupervisor' ],
		],
	];

	/**
	 * Route a user request to the best agent.
	 *
	 * @param string $prompt User prompt.
	 * @param array  $options Optional routing options and context.
	 * @return array Routing result with agent name, confidence, and routing strategy.
	 */
	public function route( string $prompt, array $options = [] ): array {
		return $this->route_local( $prompt );
	}

	/**
	 * Route to local WordPress agent.
	 *
	 * @param string $prompt User prompt.
	 * @return array Routing result.
	 */
	private function route_local( string $prompt ): array {
		$prompt_lower = strtolower( $prompt );
		$best_agent   = null;
		$best_score   = 0;

		foreach ( $this->keyword_mappings as $category => $mapping ) {
			$score = 0;
			foreach ( $mapping['keywords'] as $keyword ) {
				if ( strpos( $prompt_lower, $keyword ) !== false ) {
					$score++;
				}
			}

			if ( $score > $best_score ) {
				$best_score = $score;
				$best_agent = $mapping['agents'][0];
			}
		}

		// Default to the Director if no specialist keywords match.
		if ( ! $best_agent ) {
			$best_agent = 'Director';
			$best_score = 0;
		}

		return [
			'agent'      => $best_agent,
			'routing'    => 'local',
			'confidence' => min( $best_score / 3, 1.0 ), // Normalize to 0-1.
			'category'   => $this->get_category_for_agent( $best_agent ),
		];
	}

	/**
	 * Get the category for an agent.
	 *
	 * @param string $agent Agent name.
	 * @return string Category name.
	 */
	private function get_category_for_agent( string $agent ): string {
		foreach ( $this->keyword_mappings as $category => $mapping ) {
			if ( in_array( $agent, $mapping['agents'], true ) ) {
				return $category;
			}
		}
		return 'story';
	}

	/**
	 * Get all available agents with their categories.
	 *
	 * @return array Available agents.
	 */
	public function get_available_agents(): array {
		$agents = [];

		foreach ( $this->keyword_mappings as $category => $mapping ) {
			foreach ( $mapping['agents'] as $agent ) {
				$agents[] = [
					'name'      => $agent,
					'category'  => $category,
					'keywords'  => $mapping['keywords'],
				];
			}
		}

		return $agents;
	}

	/**
	 * Get agents by category.
	 *
	 * @param string $category Category name.
	 * @return array Agents in category.
	 */
	public function get_agents_by_category( string $category ): array {
		if ( isset( $this->keyword_mappings[ $category ] ) ) {
			return $this->keyword_mappings[ $category ]['agents'];
		}
		return [];
	}
}
