<?php
/**
 * Backward-compatible Connection adapter registry facade.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/connections/class-adapter-registry.php';

/** @deprecated Use WorldGraph\Connections\Adapter_Registry. */
class Connection_Adapters extends \WorldGraph\Connections\Adapter_Registry {}
