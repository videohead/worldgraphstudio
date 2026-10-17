<?php
/**
 * Pure helpers for ComfyUI-TemplateVars API-format workflows.
 *
 * ComfyUI-TemplateVars (https://github.com/boobkake22/ComfyUI-TemplateVars)
 * declares named prompt variables with `TemplateVariable` nodes whose
 * `inputs` are `{"name": string, "value": string}`. This class discovers
 * those literal variables, describes them as World Graph Studio run
 * controls, and writes validated values back. It has no WordPress
 * dependencies so it can be unit-tested standalone.
 *
 * @package WorldGraphComfyTemplateVars
 */

namespace WorldGraphComfyTemplateVars;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\Template_Vars' ) ) {

	/**
	 * Discover, describe, and apply TemplateVariable nodes.
	 */
	final class Template_Vars {

		/** ComfyUI node class that declares one named variable. */
		const NODE_CLASS = 'TemplateVariable';

		/** Variable that receives the composed Story Graph prompt. */
		const PROMPT_VARIABLE = 'prompt';

		/** Placeholder World Graph Studio replaces with the composed prompt. */
		const PROMPT_PLACEHOLDER = '{{prompt}}';

		/** Run-control key prefix; keeps keys letter-initial and namespaced. */
		const CONTROL_PREFIX = 'template_var_';

		/** Maximum variables advertised as run controls. */
		const MAX_VARIABLES = 24;

		/** Maximum API workflow size this helper will inspect. */
		const MAX_NODES = 512;

		/** Name contract enforced by ComfyUI-TemplateVars itself. */
		const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_-]*$/D';

		/**
		 * Literal variables declared in an API-format workflow.
		 *
		 * Linked values (e.g. `["12", 0]`) are owned by the graph and skipped.
		 * The first literal value wins when a name is declared more than once.
		 *
		 * @param array $workflow API-format workflow.
		 * @return array<string,string> Variable name => current literal value.
		 */
		public static function variables( array $workflow ): array {
			$variables = [];
			if ( count( $workflow ) > self::MAX_NODES ) {
				return $variables;
			}

			foreach ( $workflow as $node ) {
				$name = self::node_variable_name( $node );
				if ( null === $name || array_key_exists( $name, $variables ) ) {
					continue;
				}
				$value = $node['inputs']['value'] ?? '';
				if ( is_string( $value ) ) {
					$variables[ $name ] = $value;
				}
			}

			return $variables;
		}

		/** Whether the workflow declares a literal `prompt` variable. */
		public static function has_prompt_variable( array $workflow ): bool {
			return array_key_exists( self::PROMPT_VARIABLE, self::variables( $workflow ) );
		}

		/**
		 * Route the composed Story Graph prompt into the `prompt` variable.
		 *
		 * @param array $workflow API-format workflow.
		 * @return array Workflow with `{{prompt}}` in each `prompt` variable.
		 */
		public static function apply_prompt_placeholder( array $workflow ): array {
			return self::apply( $workflow, [ self::PROMPT_VARIABLE => self::PROMPT_PLACEHOLDER ] );
		}

		/**
		 * Set literal variable values by name.
		 *
		 * @param array                $workflow API-format workflow.
		 * @param array<string,scalar> $values   Variable name => new value.
		 * @return array
		 */
		public static function apply( array $workflow, array $values ): array {
			if ( empty( $values ) || count( $workflow ) > self::MAX_NODES ) {
				return $workflow;
			}

			foreach ( $workflow as $node_id => $node ) {
				$name = self::node_variable_name( $node );
				if ( null === $name || ! array_key_exists( $name, $values ) || ! is_scalar( $values[ $name ] ) ) {
					continue;
				}
				if ( array_key_exists( 'value', $node['inputs'] ) && ! is_string( $node['inputs']['value'] ) ) {
					continue;
				}
				$workflow[ $node_id ]['inputs']['value'] = (string) $values[ $name ];
			}

			return $workflow;
		}

		/**
		 * Run-control descriptors for every user-editable variable.
		 *
		 * The `prompt` variable is excluded: the Story Graph owns it.
		 *
		 * @param array $workflow API-format workflow.
		 * @return array<int,array<string,mixed>>
		 */
		public static function control_fields( array $workflow ): array {
			$fields = [];
			foreach ( self::variables( $workflow ) as $name => $value ) {
				if ( self::PROMPT_VARIABLE === $name ) {
					continue;
				}
				$key = self::control_key( $name );
				if ( null === $key ) {
					continue;
				}
				$fields[] = [
					'key'         => $key,
					'label'       => ucwords( str_replace( [ '_', '-' ], ' ', $name ) ),
					'type'        => 'textarea',
					'group'       => 'conditioning',
					'description' => sprintf( 'ComfyUI template variable {%s}. Leave unchanged to use the workflow value.', $name ),
					'default'     => $value,
				];
				if ( count( $fields ) >= self::MAX_VARIABLES ) {
					break;
				}
			}

			return $fields;
		}

		/**
		 * Map validated run values back to variable names.
		 *
		 * @param array<string,scalar> $values Run values keyed by control key.
		 * @return array<string,string> Variable name => value.
		 */
		public static function values_by_name( array $values ): array {
			$by_name = [];
			foreach ( $values as $key => $value ) {
				$name = self::name_for_control_key( (string) $key );
				if ( null !== $name && self::PROMPT_VARIABLE !== $name && is_scalar( $value ) ) {
					$by_name[ $name ] = (string) $value;
				}
			}

			return $by_name;
		}

		/**
		 * Run-control key for a variable name, or null when it cannot be one.
		 *
		 * @param string $name Variable name.
		 * @return string|null
		 */
		public static function control_key( string $name ): ?string {
			if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
				return null;
			}
			$key = self::CONTROL_PREFIX . $name;

			return strlen( $key ) <= 64 ? $key : null;
		}

		/**
		 * Variable name for a run-control key, or null for other keys.
		 *
		 * @param string $key Run-control key.
		 * @return string|null
		 */
		public static function name_for_control_key( string $key ): ?string {
			if ( 0 !== strpos( $key, self::CONTROL_PREFIX ) ) {
				return null;
			}
			$name = substr( $key, strlen( self::CONTROL_PREFIX ) );

			return 1 === preg_match( self::NAME_PATTERN, $name ) ? $name : null;
		}

		/** Name of a TemplateVariable node with a literal name, or null. */
		private static function node_variable_name( $node ): ?string {
			if ( ! is_array( $node ) || self::NODE_CLASS !== ( $node['class_type'] ?? '' ) || ! is_array( $node['inputs'] ?? null ) ) {
				return null;
			}
			$name = $node['inputs']['name'] ?? null;
			if ( ! is_string( $name ) ) {
				return null;
			}
			// ComfyUI-TemplateVars strips names before validating them.
			$name = trim( $name );

			return 1 === preg_match( self::NAME_PATTERN, $name ) ? $name : null;
		}
	}
}
