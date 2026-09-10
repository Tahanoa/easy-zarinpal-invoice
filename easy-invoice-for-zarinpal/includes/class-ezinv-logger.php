<?php
/**
 * Privacy-conscious rolling debug logger.
 *
 * @package Easy_Invoice_For_Zarinpal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EZINV_Logger {
	const MAX_ENTRIES = 100;

	/**
	 * Log an event when debug logging is enabled.
	 *
	 * Never log Merchant ID, invoice tokens, Authority values, card numbers,
	 * customer names, email addresses or phone numbers.
	 *
	 * @param string $level   Log level.
	 * @param string $message Human-readable message.
	 * @param array  $context Safe context fields.
	 * @return void
	 */
	public static function log( $level, $message, array $context = array() ) {
		if ( ! (bool) get_option( EZINV_Settings::OPT_ENABLE_LOGGING, false ) ) {
			return;
		}

		$allowed_keys = array( 'invoice_id', 'operation', 'http_status', 'gateway_code', 'error_code' );
		$safe_context = array();

		foreach ( $allowed_keys as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$safe_context[ $key ] = sanitize_text_field( (string) $context[ $key ] );
			}
		}

		$entry = array(
			'time'    => current_time( 'mysql' ),
			'level'   => strtoupper( sanitize_key( $level ) ),
			'message' => sanitize_text_field( (string) $message ),
			'context' => $safe_context,
		);

		$entries = self::get_entries();
		$entries[] = $entry;
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		if ( false === get_option( EZINV_Settings::OPT_LOG_ENTRIES, false ) ) {
			add_option( EZINV_Settings::OPT_LOG_ENTRIES, $entries, '', 'no' );
			return;
		}

		update_option( EZINV_Settings::OPT_LOG_ENTRIES, $entries, false );
	}

	/**
	 * Get sanitized stored log entries.
	 *
	 * @return array
	 */
	public static function get_entries() {
		$entries = get_option( EZINV_Settings::OPT_LOG_ENTRIES, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		return array_slice( $entries, -self::MAX_ENTRIES );
	}

	/**
	 * Clear stored debug entries.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( EZINV_Settings::OPT_LOG_ENTRIES );
	}
}
