<?php
/**
 * Backward-compatible Connection test service facade.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/connections/class-connection-test-service.php';

/** @deprecated Use WorldGraph\Connections\Connection_Test_Service. */
class Connection_Tester extends \WorldGraph\Connections\Connection_Test_Service {}
