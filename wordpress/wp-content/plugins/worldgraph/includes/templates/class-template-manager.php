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

	/** Maximum operator-facing status message length. */
	private const MAX_STATUS_MESSAGE_LENGTH = 500;

	/** Maximum number of Template IDs copied into the observer summary. */
	private const MAX_STATUS_TEMPLATE_IDS = 100;

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
			self::record_failure( $connection_id, self::templates_for_connection( $connection_id ), 'schedule', $context );
			return $context;
		}

		$provider_type = (string) $context['provider_type'];
		$loaded       = Connection_Adapters::load( $provider_type );
		$adapter      = (array) $context['adapter'];
		$templates    = is_array( $adapter['templates'] ?? null ) ? $adapter['templates'] : [];
		$callback     = $templates['provision'] ?? null;
		if ( empty( $callback ) ) {
			return false;
		}
		if ( ! $loaded || ! is_callable( $callback ) ) {
			$error = new WP_Error(
				'worldgraph_template_provisioner_missing',
				__( 'This Connection adapter could not load its Template provisioner.', 'worldgraph' )
			);
			self::record_failure( $connection_id, $templates, 'schedule', $error );
			return $error;
		}
		if ( $delay <= 0 ) {
			$delay = (int) ( $templates['delay'] ?? 5 );
		}

		if ( ! wp_next_scheduled( self::HOOK, [ $connection_id ] ) ) {
			$scheduled = wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK, [ $connection_id ] );
			if ( false === $scheduled && ! wp_next_scheduled( self::HOOK, [ $connection_id ] ) ) {
				$error = new WP_Error(
					'worldgraph_template_provision_schedule_failed',
					__( 'WordPress could not schedule provider Template provisioning.', 'worldgraph' )
				);
				self::record_failure( $connection_id, $templates, 'schedule', $error );
				return $error;
			}
		}

		self::notify_status( $connection_id, 'schedule', true, '', '', [] );
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
			self::record_failure( $connection_id, self::templates_for_connection( $connection_id ), 'provision', $context );
			return $context;
		}

		$provider_type = (string) $context['provider_type'];
		$loaded       = Connection_Adapters::load( $provider_type );
		$adapter      = (array) $context['adapter'];
		$templates    = is_array( $adapter['templates'] ?? null ) ? $adapter['templates'] : [];
		$callback     = $templates['provision'] ?? null;
		if ( ! $loaded || ! is_callable( $callback ) ) {
			$error = new WP_Error(
				'worldgraph_template_provisioner_missing',
				__( 'This Connection adapter does not declare a Template provisioner.', 'worldgraph' )
			);
			self::record_failure( $connection_id, $templates, 'provision', $error );
			return $error;
		}

		try {
			$result = call_user_func( $callback, $connection_id );
		} catch ( \Throwable ) {
			$error = new WP_Error(
				'worldgraph_template_provisioner_failed',
				__( 'The provider Template provisioner could not complete.', 'worldgraph' )
			);
			self::record_failure( $connection_id, $templates, 'provision', $error );
			return $error;
		}

		if ( is_wp_error( $result ) ) {
			self::record_failure( $connection_id, $templates, 'provision', $result );
			return $result;
		}
		if ( ! is_array( $result ) ) {
			$error = new WP_Error(
				'worldgraph_template_provisioner_result_invalid',
				__( 'The provider Template provisioner returned an invalid result.', 'worldgraph' )
			);
			self::record_failure( $connection_id, $templates, 'provision', $error );
			return $error;
		}

		self::record_success( $connection_id, $templates, $result );
		return $result;
	}

	/** Persist a bounded provisioning error and notify internal observers. */
	private static function record_failure( int $connection_id, array $templates, string $phase, WP_Error $error ): void {
		$message = self::normalize_status_message( $error->get_error_message() );
		if ( '' === $message ) {
			$message = __( 'Provider Template provisioning failed.', 'worldgraph' );
		}
		$prefix  = self::status_meta_prefix( $templates );
		if ( '' !== $prefix && $connection_id > 0 ) {
			update_post_meta( $connection_id, $prefix . '_error', $message );
		}

		self::notify_status(
			$connection_id,
			$phase,
			false,
			sanitize_key( (string) $error->get_error_code() ),
			$message,
			[]
		);
	}

	/** Persist a successful sync without erasing a provider's partial warning. */
	private static function record_success( int $connection_id, array $templates, array $result ): void {
		$raw_warning = $result['warning'] ?? '';
		$warning     = is_scalar( $raw_warning )
			? self::normalize_status_message( (string) $raw_warning )
			: '';
		$prefix      = self::status_meta_prefix( $templates );
		if ( '' !== $prefix && $connection_id > 0 ) {
			update_post_meta( $connection_id, $prefix . '_synced_at', gmdate( 'Y-m-d H:i:s' ) );
			if ( '' !== $warning ) {
				update_post_meta( $connection_id, $prefix . '_error', $warning );
			} else {
				delete_post_meta( $connection_id, $prefix . '_error' );
			}
		}

		$template_ids = [];
		foreach ( (array) ( $result['template_ids'] ?? [] ) as $template_id ) {
			if ( is_scalar( $template_id ) && absint( $template_id ) > 0 ) {
				$template_ids[] = absint( $template_id );
				if ( count( $template_ids ) >= self::MAX_STATUS_TEMPLATE_IDS ) {
					break;
				}
			}
		}
		$template_ids = array_values( array_unique( $template_ids ) );
		self::notify_status(
			$connection_id,
			'provision',
			true,
			'' !== $warning ? 'worldgraph_template_provision_warning' : '',
			$warning,
			$template_ids
		);
	}

	/** Normalize the optional manifest prefix used by the Connections screen. */
	private static function status_meta_prefix( array $templates ): string {
		$prefix = $templates['status_meta_prefix'] ?? '';
		$prefix = is_scalar( $prefix ) ? sanitize_key( (string) $prefix ) : '';
		return substr( $prefix, 0, 100 );
	}

	/** Read status metadata even when the Connection itself is not provisionable. */
	private static function templates_for_connection( int $connection_id ): array {
		$connection = Connection_Repository::get( $connection_id );
		if ( ! is_array( $connection ) ) {
			return [];
		}

		$provider_type = sanitize_key( (string) ( $connection['provider_type'] ?? '' ) );
		$adapter       = '' !== $provider_type ? Connection_Adapters::get( $provider_type ) : null;
		return is_array( $adapter ) && is_array( $adapter['templates'] ?? null )
			? $adapter['templates']
			: [];
	}

	/** Sanitize and bound a status message before persistence or notification. */
	private static function normalize_status_message( string $message ): string {
		$message = sanitize_text_field( $message );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $message, 0, self::MAX_STATUS_MESSAGE_LENGTH );
		}

		return substr( $message, 0, self::MAX_STATUS_MESSAGE_LENGTH );
	}

	/**
	 * Notify internal observers without exposing an unbounded provider result.
	 *
	 * @param array<int, int> $template_ids Provisioned Template post IDs.
	 */
	private static function notify_status( int $connection_id, string $phase, bool $success, string $error_code, string $message, array $template_ids ): void {
		/**
		 * Fires after common Template scheduling or provisioning reaches an outcome.
		 *
		 * @param int   $connection_id Connection post ID.
		 * @param array $status        Bounded, non-secret status summary.
		 */
		try {
			do_action(
				'worldgraph_template_provisioning_status',
				$connection_id,
				[
					'phase'        => sanitize_key( $phase ),
					'success'      => $success,
					'error_code'   => substr( sanitize_key( $error_code ), 0, 100 ),
					'message'      => self::normalize_status_message( $message ),
					'template_ids' => array_values( array_map( 'absint', $template_ids ) ),
				]
			);
		} catch ( \Throwable ) {
			// Diagnostic observers are best-effort and cannot change the outcome.
		}
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
