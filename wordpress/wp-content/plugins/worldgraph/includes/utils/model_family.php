<?php
/**
 * Model family registry.
 *
 * World Graph Studio follows ComfyUI's own terminology and taxonomy for this instead of
 * inventing a parallel one. Every entry's `node_prefixes` were read from a
 * live ComfyUI `/object_info` catalog's `class_type` names (LTXV's own nodes,
 * ComfyUI's native `Wan*` nodes, the `Minimax`/`MiniMax` API node packs, and
 * the `SCAIL*`/`WanSCAIL*` nodes a Wan-based Template may use), and
 * `checkpoint_folder` is one of the `models/` sub-directories from
 * {@see Comfy_Manifest::MODEL_FIELDS} that family's loader node reads from.
 * Those are the single source of truth here; Template options, admin list
 * columns, and discovery output all read from this list, and a pasted
 * workflow can be matched against it automatically instead of relying on a
 * free-text label.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model family registry.
 */
class Model_Family {

	const LTXV    = 'ltxv';
	const WAN     = 'wan';
	const MINIMAX = 'minimax';
	const SCAIL   = 'scail';

	/**
	 * Registered model families, keyed by slug.
	 *
	 * @return array<string, array{label: string, node_prefixes: array<int, string>, checkpoint_folder: string, modalities: array<int, string>}>
	 */
	public static function all(): array {
		$families = [
			self::LTXV    => [
				'label'             => 'LTXV (LTX-Video)',
				// Native nodes (LTXVConditioning, LTXVImgToVideo, …) and the
				// Comfy Cloud API nodes (LtxvApiTextToVideo, LtxApi25…).
				'node_prefixes'     => [ 'LTXV', 'Ltxv', 'LtxApi' ],
				'checkpoint_folder' => 'checkpoints',
				'modalities'        => [
					Generation_Modality::TEXT_TO_VIDEO,
					Generation_Modality::TEXT_IMAGE_TO_VIDEO,
					Generation_Modality::VIDEO_TO_VIDEO,
				],
			],
			self::WAN     => [
				'label'             => 'Wan',
				// ComfyUI's native Wan* nodes (WanImageToVideo, Wan22FunControlToVideo, …)
				// and the Comfy Cloud Wan API nodes (WanTextToVideoApi, Wan2TextToVideoApi).
				'node_prefixes'     => [ 'Wan' ],
				'checkpoint_folder' => 'diffusion_models',
				'modalities'        => [
					Generation_Modality::TEXT_TO_VIDEO,
					Generation_Modality::TEXT_IMAGE_TO_VIDEO,
					Generation_Modality::VIDEO_TO_VIDEO,
				],
			],
			self::MINIMAX => [
				'label'             => 'MiniMax',
				// Two node packs ship this API family under different casing:
				// Minimax(TextToVideoNode|HailuoVideoNode…) and MiniMax(H3…|Music3…).
				'node_prefixes'     => [ 'Minimax', 'MiniMax' ],
				'checkpoint_folder' => '', // Cloud-hosted; no local model file.
				'modalities'        => [
					Generation_Modality::TEXT_TO_VIDEO,
					Generation_Modality::TEXT_IMAGE_TO_VIDEO,
				],
			],
			self::SCAIL   => [
				'label'             => 'SCAIL (Wan)',
				// A Wan conditioning technique (WanSCAILToVideo, SCAIL2ColoredMask),
				// not an independent checkpoint family.
				'node_prefixes'     => [ 'SCAIL', 'WanSCAIL' ],
				'checkpoint_folder' => 'diffusion_models',
				'modalities'        => [
					Generation_Modality::TEXT_TO_VIDEO,
					Generation_Modality::TEXT_IMAGE_TO_VIDEO,
					Generation_Modality::VIDEO_TO_VIDEO,
				],
			],
		];

		/**
		 * Filter the registered model families, so a site or add-on can list a
		 * family this registry does not yet know about.
		 *
		 * @param array<string, array> $families Model families keyed by slug.
		 */
		return (array) apply_filters( 'worldgraph_model_families', $families );
	}

	/**
	 * Registered model family slugs.
	 *
	 * @return array<int, string>
	 */
	public static function slugs(): array {
		return array_keys( self::all() );
	}

	/**
	 * Slug => label map, for admin select fields.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array_map(
			static function ( array $family ): string {
				return (string) $family['label'];
			},
			self::all()
		);
	}

	/**
	 * Look up a model family definition.
	 *
	 * @param string $slug Model family slug.
	 * @return array|null
	 */
	public static function get( string $slug ): ?array {
		$all = self::all();

		return $all[ sanitize_key( $slug ) ] ?? null;
	}

	/**
	 * Reduce arbitrary input to a registered model family slug, or '' when the
	 * Template does not name one of the known families.
	 *
	 * @param string $slug Candidate slug.
	 * @return string
	 */
	public static function sanitize( string $slug ): string {
		$key = sanitize_key( $slug );
		if ( array_key_exists( $key, self::all() ) ) {
			return $key;
		}

		$name = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', $slug ) );
		if ( 1 === preg_match( '/^wan(?:video)?(?:v?\d.*)?$/', $name ) ) {
			return self::WAN;
		}
		if ( 1 === preg_match( '/^ltx(?:v|video)?(?:v?\d.*)?$/', $name ) ) {
			return self::LTXV;
		}

		return '';
	}

	/**
	 * Human-readable label for a model family slug.
	 *
	 * @param string $slug Model family slug.
	 * @return string
	 */
	public static function label( string $slug ): string {
		$family = self::get( $slug );

		return $family ? (string) $family['label'] : '';
	}

	/**
	 * Model families compatible with a given modality, for filtering the
	 * Template editor's Model Family choices to what the selected Modality
	 * can actually run.
	 *
	 * @param string $modality Modality slug.
	 * @return array<string, array>
	 */
	public static function for_modality( string $modality ): array {
		$modality = Generation_Modality::sanitize( $modality );

		return array_filter(
			self::all(),
			static function ( array $family ) use ( $modality ): bool {
				return in_array( $modality, (array) $family['modalities'], true );
			}
		);
	}

	/**
	 * The `models/` sub-directory a family's loader node reads from, per
	 * {@see Comfy_Manifest::MODEL_FIELDS}. Empty for a cloud-hosted family
	 * with no local model file (e.g. MiniMax).
	 *
	 * @param string $slug Model family slug.
	 * @return string
	 */
	public static function checkpoint_folder( string $slug ): string {
		$family = self::get( $slug );

		return $family ? (string) $family['checkpoint_folder'] : '';
	}

	/**
	 * Detect which registered family a ComfyUI API-format workflow belongs to
	 * by matching its nodes' `class_type` prefixes against a live ComfyUI
	 * `/object_info` catalog's naming (e.g. `LTXV...`, `Wan...`, `Minimax...`).
	 * Lets a pasted custom workflow be categorized from what it actually
	 * contains instead of a manual guess. Longer, more specific prefixes (e.g.
	 * SCAIL's `WanSCAIL...`) are matched before the shorter `Wan` prefix they
	 * also start with.
	 *
	 * @param array $workflow Decoded ComfyUI API-format workflow.
	 * @return string Model family slug, or '' when no registered prefix matches.
	 */
	public static function detect_from_workflow( array $workflow ): string {
		$class_types = [];
		foreach ( $workflow as $node ) {
			if ( is_array( $node ) && ! empty( $node['class_type'] ) ) {
				$class_types[] = (string) $node['class_type'];
			}
		}

		return self::for_nodes( $class_types );
	}

	/**
	 * Detect the family from a flat list of node class names, as advertised by
	 * a Comfy MCP template descriptor that carries `required_nodes` without a
	 * full workflow graph.
	 *
	 * @param array<int, string> $class_types Node class names.
	 * @return string Model family slug, or '' when no registered prefix matches.
	 */
	public static function for_nodes( array $class_types ): string {
		$candidates = [];
		foreach ( self::all() as $slug => $family ) {
			foreach ( (array) $family['node_prefixes'] as $prefix ) {
				if ( '' !== $prefix ) {
					$candidates[] = [ 'slug' => $slug, 'prefix' => $prefix ];
				}
			}
		}
		usort( $candidates, static function ( array $a, array $b ): int {
			return strlen( $b['prefix'] ) <=> strlen( $a['prefix'] );
		} );

		foreach ( $candidates as $candidate ) {
			foreach ( $class_types as $class_type ) {
				if ( is_string( $class_type ) && 0 === stripos( $class_type, $candidate['prefix'] ) ) {
					return $candidate['slug'];
				}
			}
		}

		return '';
	}
}
