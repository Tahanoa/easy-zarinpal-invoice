<?php
/**
 * ZarinPal API client.
 *
 * @package Easy_Invoice_For_Zarinpal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EZINV_Gateway {
	const REQUEST_URL = 'https://payment.zarinpal.com/pg/v4/payment/request.json';
	const VERIFY_URL  = 'https://payment.zarinpal.com/pg/v4/payment/verify.json';
	const INQUIRY_URL = 'https://payment.zarinpal.com/pg/v4/payment/inquiry.json';
	const START_URL   = 'https://payment.zarinpal.com/pg/StartPay/';

	/**
	 * Create a payment request.
	 *
	 * @param object $invoice Invoice object.
	 * @param string $callback_url Callback URL.
	 * @return array|WP_Error
	 */
	public static function request_payment( $invoice, $callback_url ) {
		$merchant_id = self::merchant_id();
		if ( is_wp_error( $merchant_id ) ) {
			return $merchant_id;
		}

		$payload = array(
			'merchant_id'  => $merchant_id,
			'amount'       => (int) $invoice->amount_irr,
			'currency'     => 'IRR',
			'description'  => $invoice->description ? (string) $invoice->description : (string) $invoice->title,
			'callback_url' => esc_url_raw( $callback_url ),
			'metadata'     => array(
				'order_id' => (string) $invoice->id,
			),
		);

		if ( ! empty( $invoice->mobile ) ) {
			$payload['metadata']['mobile'] = (string) $invoice->mobile;
		}
		if ( ! empty( $invoice->email ) ) {
			$payload['metadata']['email'] = (string) $invoice->email;
		}

		return self::post( self::REQUEST_URL, $payload, 'request', (int) $invoice->id );
	}

	/**
	 * Verify a transaction using server-side invoice amount and stored Authority.
	 *
	 * @param object $invoice Invoice object.
	 * @return array|WP_Error
	 */
	public static function verify_payment( $invoice ) {
		$merchant_id = self::merchant_id();
		if ( is_wp_error( $merchant_id ) ) {
			return $merchant_id;
		}

		return self::post(
			self::VERIFY_URL,
			array(
				'merchant_id' => $merchant_id,
				'amount'      => (int) $invoice->amount_irr,
				'authority'   => (string) $invoice->authority,
			),
			'verify',
			(int) $invoice->id
		);
	}

	/**
	 * Query transaction state. This never verifies a transaction.
	 *
	 * @param object $invoice Invoice object.
	 * @return array|WP_Error
	 */
	public static function inquiry( $invoice ) {
		$merchant_id = self::merchant_id();
		if ( is_wp_error( $merchant_id ) ) {
			return $merchant_id;
		}

		return self::post(
			self::INQUIRY_URL,
			array(
				'merchant_id' => $merchant_id,
				'authority'   => (string) $invoice->authority,
			),
			'inquiry',
			(int) $invoice->id
		);
	}

	/**
	 * Get and validate merchant ID.
	 *
	 * @return string|WP_Error
	 */
	private static function merchant_id() {
		$merchant_id = trim( (string) get_option( EZINV_Settings::OPT_MERCHANT_ID, '' ) );
		if ( 36 !== strlen( $merchant_id ) || ! preg_match( '/^[A-Za-z0-9-]{36}$/', $merchant_id ) ) {
			return new WP_Error( 'ezinv_missing_merchant', esc_html__( 'ZarinPal Merchant ID is not configured correctly.', 'easy-invoice-for-zarinpal' ) );
		}
		return $merchant_id;
	}

	/**
	 * Send a request through WordPress HTTP API and validate its response.
	 *
	 * @param string $url        Endpoint.
	 * @param array  $payload    JSON payload.
	 * @param string $operation  Operation label.
	 * @param int    $invoice_id Invoice ID for redacted logs.
	 * @return array|WP_Error
	 */
	private static function post( $url, array $payload, $operation, $invoice_id ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'             => 25,
				'redirection'         => 0,
				'limit_response_size' => 1048576,
				'headers'             => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'User-Agent'   => 'Easy-Invoice-for-ZarinPal/' . EZINV_VERSION,
				),
				'body'                => wp_json_encode( $payload ),
				'data_format'         => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			EZINV_Logger::log(
				'error',
				'Gateway connection failed.',
				array(
					'invoice_id' => $invoice_id,
					'operation'  => $operation,
					'error_code' => $response->get_error_code(),
				)
			);
			return new WP_Error( 'ezinv_gateway_connection', esc_html__( 'Could not connect to the payment gateway. Please try again.', 'easy-invoice-for-zarinpal' ) );
		}

		$http_status = (int) wp_remote_retrieve_response_code( $response );
		$body        = (string) wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( $http_status < 200 || $http_status >= 300 ) {
			EZINV_Logger::log(
				'error',
				'Gateway returned a non-success HTTP status.',
				array(
					'invoice_id' => $invoice_id,
					'operation'  => $operation,
					'http_status'=> $http_status,
				)
			);
			return new WP_Error( 'ezinv_gateway_http', sprintf( /* translators: %d: HTTP response status code. */
					esc_html__( 'Payment gateway returned HTTP status %d.', 'easy-invoice-for-zarinpal' ), $http_status ) );
		}

		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			EZINV_Logger::log(
				'error',
				'Gateway returned invalid JSON.',
				array(
					'invoice_id' => $invoice_id,
					'operation'  => $operation,
					'http_status'=> $http_status,
				)
			);
			return new WP_Error( 'ezinv_gateway_json', esc_html__( 'The payment gateway returned an invalid response.', 'easy-invoice-for-zarinpal' ) );
		}

		$gateway_code = isset( $data['data']['code'] ) ? (int) $data['data']['code'] : 0;
		EZINV_Logger::log(
			'info',
			'Gateway response received.',
			array(
				'invoice_id'  => $invoice_id,
				'operation'   => $operation,
				'http_status' => $http_status,
				'gateway_code'=> $gateway_code,
			)
		);

		return $data;
	}

	/**
	 * Return a safe gateway error message.
	 *
	 * @param array  $response Gateway response.
	 * @param string $fallback Fallback message.
	 * @return string
	 */
	public static function error_message( array $response, $fallback ) {
		$candidates = array(
			isset( $response['data']['message'] ) ? $response['data']['message'] : '',
			isset( $response['message'] ) ? $response['message'] : '',
		);

		foreach ( $candidates as $candidate ) {
			if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) ) {
				return sanitize_text_field( (string) $candidate );
			}
		}
		return $fallback;
	}
}
