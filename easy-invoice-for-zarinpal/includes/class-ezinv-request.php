<?php
/**
 * Request input helpers.
 *
 * This class only reads and sanitizes request values. Authorization and nonce
 * verification remain the responsibility of the action handler that performs
 * a state-changing operation.
 *
 * @package Easy_Invoice_For_Zarinpal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EZINV_Request {
	/**
	 * Return an unslashed POST snapshot.
	 *
	 * Reading POST data is centralized here so every public accessor can apply
	 * type-specific sanitization. State-changing handlers verify their own nonce
	 * before consuming these values.
	 *
	 * @return array
	 */
	private static function post_data() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This helper only reads request data; mutating handlers perform nonce verification before processing it.
		$data = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Return an unslashed GET snapshot.
	 *
	 * GET values in this plugin are used for read-only public invoice routing or
	 * an external payment callback. Callback authenticity is established by
	 * strict token/Authority validation plus server-to-server verification.
	 *
	 * @return array
	 */
	private static function get_data() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only query args and external gateway callbacks cannot use a WordPress nonce; callers validate values before use.
		$data = isset( $_GET ) && is_array( $_GET ) ? wp_unslash( $_GET ) : array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Get a sanitized POST text field.
	 *
	 * @param string $key     Field key.
	 * @param string $default Default value.
	 * @return string
	 */
	public static function post_text( $key, $default = '' ) {
		$data = self::post_data();
		if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
			return $default;
		}
		return sanitize_text_field( (string) $data[ $key ] );
	}

	/**
	 * Get a sanitized POST textarea field.
	 *
	 * @param string $key     Field key.
	 * @param string $default Default value.
	 * @return string
	 */
	public static function post_textarea( $key, $default = '' ) {
		$data = self::post_data();
		if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
			return $default;
		}
		return sanitize_textarea_field( (string) $data[ $key ] );
	}

	/**
	 * Get a sanitized positive integer POST field.
	 *
	 * @param string $key     Field key.
	 * @param int    $default Default value.
	 * @return int
	 */
	public static function post_int( $key, $default = 0 ) {
		$data = self::post_data();
		if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
			return absint( $default );
		}
		return absint( $data[ $key ] );
	}

	/**
	 * Get a sanitized POST email field.
	 *
	 * @param string $key     Field key.
	 * @param string $default Default value.
	 * @return string
	 */
	public static function post_email( $key, $default = '' ) {
		$data = self::post_data();
		if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
			return $default;
		}
		return sanitize_email( (string) $data[ $key ] );
	}

	/**
	 * Get a flat POST array as absolute integers while preserving indexes.
	 *
	 * @param string $key Field key.
	 * @return array
	 */
	public static function post_ids( $key ) {
		$data = self::post_data();
		if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			return array();
		}

		$ids = array();
		foreach ( array_slice( $data[ $key ], 0, 500, true ) as $index => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$ids[ $index ] = absint( $value );
		}
		return $ids;
	}

	/**
	 * Get a sanitized GET text field.
	 *
	 * @param string $key     Field key.
	 * @param string $default Default value.
	 * @return string
	 */
	public static function get_text( $key, $default = '' ) {
		$data = self::get_data();
		if ( ! isset( $data[ $key ] ) || ! is_scalar( $data[ $key ] ) ) {
			return $default;
		}
		return sanitize_text_field( (string) $data[ $key ] );
	}

	/**
	 * Get the HTTP request method.
	 *
	 * @return string
	 */
	public static function method() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_scalar( $_SERVER['REQUEST_METHOD'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
			: '';
		return strtoupper( $method );
	}
}
