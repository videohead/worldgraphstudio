<?php
/**
 * Provider Template provisioning lifecycle.
 *
 * @package WorldGraph
 */

namespace WorldGraph\Templates;

use WP_Error;
use WorldGraph\Utils\Connection_Adapters;
use WorldGraph\Utils\Connection_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-template-repository.php';

/**
 * Schedules and dispatches the manifest-declared Template provisioner.
 */
class Template_Manager {

	/** Generic background hook for all provider Template provisioners. */
	const HOOK = 'worldgraph_provision_connection_templates';

	/** Whether the manager registered its hook during this request. */
	private static bool $initialized = false;

	/** Register the generic provisioning worker once. */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		add_action( self::HOOK, [ __CLASS__, 'provision_for_connection' ], 10, 1 );
		self::$initialized = true;
	}

	/**
	 * Schedule an idempotent background provisioning pass for a Connection.
	 *
	 * @param int $connection_id Connection post ID.
	 * @param int $delay         Optional delay override in seconds; zero uses the adapter manifest.
	 * @return bool|WP_Error
	 */
	public static function schedule_for_connection( int $connection_id, int $delay = 0 ) {
		$context = self::context( $connection_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$provider_type = (string) $context['provider_type'];
		Connection_Adapters::load( $provider_type );
		$adapter   = (array) $context['adapter'];
		$templates = is_array( $adapter['templates'] ?? null ) ? $adapter['templates'] : [];
		if ( ! is_callable( $templates['provision'] ?? null ) ) {
			return false;
		}
		if ( $delay <= 0 ) {
			$delay = (int) ( $templates['delay'] ?? 5 );
		}

		if ( ! wp_next_scheduled( self::HOOK, [ $connection_id ] ) ) {
			$scheduled = wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK, [ $connection_id ] );
			if ( false === $scheduled && ! wp_next_scheduled( self::HOOK, [ $connection_id ] ) ) {
				return new WP_Error(
					'worldgraph_template_provision_schedule_failed',
					__( 'WordPress could not schedule provider Template provisioning.', 'worldgraph' )
				);
			}
		}

		return true;
	}

	/**
	 * Invoke the adapter manifest's stable one-argument Template provisioner.
	 *
	 * The `templates.provision` callable receives only the Connection post ID and
	 * returns an array result or WP_Error.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array|WP_Error
	 */
	public static function provision_for_connection( int $connection_id ) {
		$context = self::context( $connection_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$provider_type = (string) $context['provider_type'];
		Connection_Adapters::load( $provider_type );
		$adapter   = (array) $context['adapter'];
		$templates = is_array( $adapter['templates'] ?? null ) ? $adapter['templates'] : [];
		$callback  = $templates['provision'] ?? null;
		if ( ! is_callable( $callback ) ) {
			return new WP_Error(
				'worldgraph_template_provisioner_missing',
				__( 'This Connection adapter does not declare a Template provisioner.', 'worldgraph' )
			);
		}

		try {
			$result = call_user_func( $callback, $connection_id );
		} catch ( \Throwable $throwable ) {
			return new WP_Error(
				'worldgraph_template_provisioner_failed',
				__( 'The provider Template provisioner could not complete.', 'worldgraph' )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return new WP_Error(
				'worldgraph_template_provisioner_result_invalid',
				__( 'The provider Template provisioner returned an invalid result.', 'worldgraph' )
			);
		}

		return $result;
	}

	/**
	 * Resolve a published, enabled Connection with a registered adapter.
	 *
	 * @param int $connection_id Connection post ID.
	 * @return array|WP_Error
	 */
	private static function context( int $connection_id ) {
		$connection = Connection_Repository::get( $connection_id );
		if (
			! is_array( $connection )
			|| 'publish' !== (string) ( $connection['status_wp'] ?? '' )
			|| 'disabled' === (string) ( $connection['status'] ?? '' )
		) {
			return new WP_Error(
				'worldgraph_template_connection_invalid',
				__( 'Template provisioning requires a published, enabled Connection.', 'worldgraph' )
			);
		}

		$provider_type = sanitize_key( (string) ( $connection['provider_type'] ?? '' ) );
		$adapter       = '' === $provider_type ? null : Connection_Adapters::get( $provider_type );
		if ( ! is_array( $adapter ) ) {
			return new WP_Error(
				'worldgraph_template_adapter_missing',
				__( 'Template provisioning requires a registered Connection adapter.', 'worldgraph' )
			);
		}

		return [
			'connection'    => $connection,
			'provider_type' => $provider_type,
			'adapter'       => $adapter,
		];
	}
}
