<?php
/**
 * Plugin Name: World Graph Studio - ComfyUI Template Variables
 * Plugin URI: https://github.com/videohead/storyos
 * Description: Runs ComfyUI-TemplateVars workflows: routes the Story Graph prompt into the `prompt` variable and exposes every other TemplateVariable as an editable, Story Graph-bindable run control.
 * Version: 1.0.0
 * Author: World Graph Studio Contributors
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: worldgraph
 * Requires Plugins: worldgraph
 * Requires at least: 6.2
 * Requires PHP: 8.1
 *
 * @package WorldGraphComfyTemplateVars
 */

namespace WorldGraphComfyTemplateVars;

defined( 'ABSPATH' ) || exit;

define( 'WORLDGRAPH_COMFY_TEMPLATE_VARS_VERSION', '1.0.0' );
define( 'WORLDGRAPH_COMFY_TEMPLATE_VARS_OPTION', 'worldgraph_comfy_template_vars_enabled' );

require_once __DIR__ . '/includes/class-template-vars.php';
require_once __DIR__ . '/includes/class-bindings.php';

/**
 * Whether the integration is enabled. Defaults on: it only acts on workflows
 * that contain ComfyUI-TemplateVars `TemplateVariable` nodes.
 */
function is_enabled(): bool {
	return (bool) get_option( WORLDGRAPH_COMFY_TEMPLATE_VARS_OPTION, true );
}

/**
 * Advertise each non-prompt TemplateVariable as a run control.
 *
 * @param array $fields   Extension field descriptors.
 * @param array $workflow Server-owned API-format workflow, or [].
 * @return array
 */
function run_control_fields( $fields, $workflow ): array {
	$fields = is_array( $fields ) ? $fields : [];
	if ( ! is_array( $workflow ) || empty( $workflow ) ) {
		return $fields;
	}

	return array_merge( $fields, Template_Vars::control_fields( $workflow ) );
}

/**
 * Write validated variable run values into their TemplateVariable nodes.
 *
 * @param array $workflow API-format workflow.
 * @param array $values   Validated run values.
 * @return array
 */
function apply_run_values( $workflow, $values ): array {
	if ( ! is_array( $workflow ) ) {
		return [];
	}

	return Template_Vars::apply( $workflow, Template_Vars::values_by_name( is_array( $values ) ? $values : [] ) );
}

/**
 * Claim the composed Story Graph prompt for a `prompt` TemplateVariable.
 *
 * @param array $workflow API-format workflow.
 * @return array
 */
function prompt_placeholder_workflow( $workflow ): array {
	if ( ! is_array( $workflow ) ) {
		return [];
	}

	return Template_Vars::has_prompt_variable( $workflow ) ? Template_Vars::apply_prompt_placeholder( $workflow ) : $workflow;
}

/**
 * Resolve Story Graph-bound variable values for one source item.
 *
 * @param array $values      Values from earlier filters.
 * @param int   $source_id   Story Graph source post ID.
 * @param int   $template_id Template post ID.
 * @return array
 */
function bound_run_values( $values, $source_id, $template_id ): array {
	$values = is_array( $values ) ? $values : [];

	return array_merge( $values, Bindings::run_values( (int) $template_id, (int) $source_id ) );
}

if ( is_enabled() ) {
	add_filter( 'worldgraph_template_run_control_fields', __NAMESPACE__ . '\\run_control_fields', 10, 2 );
	add_filter( 'worldgraph_template_run_controls_apply_workflow', __NAMESPACE__ . '\\apply_run_values', 10, 2 );
	add_filter( 'worldgraph_comfy_prompt_placeholder_workflow', __NAMESPACE__ . '\\prompt_placeholder_workflow', 10, 1 );
	add_filter( 'worldgraph_template_bound_run_values', __NAMESPACE__ . '\\bound_run_values', 10, 3 );
}
