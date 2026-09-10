<?php
/**
 * Public invoice rendering and payment flow.
 *
 * @package Easy_Invoice_For_Zarinpal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EZINV_Invoice {
	/**
	 * Register public hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'ezinv_invoice', array( __CLASS__, 'shortcode' ) );
		// Backward compatibility with the previous 1.4.x private build.
		add_shortcode( 'ezi_invoice', array( __CLASS__, 'shortcode' ) );

		add_action( 'admin_post_ezinv_pay', array( __CLASS__, 'start_payment' ) );
		add_action( 'admin_post_nopriv_ezinv_pay', array( __CLASS__, 'start_payment' ) );
		add_action( 'admin_post_ezinv_verify', array( __CLASS__, 'callback' ) );
		add_action( 'admin_post_nopriv_ezinv_verify', array( __CLASS__, 'callback' ) );
		// Legacy in-flight gateway callbacks after upgrading from older versions.
		add_action( 'admin_post_ezi_verify', array( __CLASS__, 'callback' ) );
		add_action( 'admin_post_nopriv_ezi_verify', array( __CLASS__, 'callback' ) );
		add_action( 'template_redirect', array( __CLASS__, 'send_invoice_nocache_headers' ), 0 );
		add_filter( 'wp_robots', array( __CLASS__, 'invoice_robots' ) );
	}

	/**
	 * Render the invoice inside the current theme via shortcode.
	 *
	 * @return string
	 */
	public static function shortcode() {
		$raw_token = EZINV_Request::get_text( 'ezinv_invoice' );
		if ( '' === $raw_token ) {
			$raw_token = EZINV_Request::get_text( 'ezi_invoice' );
		}
		$token = self::sanitize_token( $raw_token );

		wp_enqueue_style( 'ezinv-public', EZINV_PLUGIN_URL . 'public/css/public.css', array(), EZINV_VERSION );

		if ( '' === $token ) {
			return '<div class="ezinv-alert ezinv-alert-error">' . esc_html__( 'The invoice link is invalid.', 'easy-invoice-for-zarinpal' ) . '</div>';
		}

		$invoice = EZINV_DB::get_by_token( $token );
		if ( ! $invoice ) {
			return '<div class="ezinv-alert ezinv-alert-error">' . esc_html__( 'The invoice was not found or has been removed.', 'easy-invoice-for-zarinpal' ) . '</div>';
		}

		$business_name = (string) get_option( EZINV_Settings::OPT_BUSINESS_NAME, get_bloginfo( 'name' ) );
		$items         = self::decode_items( $invoice->items_json );
		$payment_raw   = EZINV_Request::get_text( 'payment' );
		$payment_state = sanitize_key( (string) $payment_raw );

		ob_start();
		?>
		<div class="ezinv-public-invoice">
			<div class="ezinv-public-head">
				<div>
					<div class="ezinv-public-business"><?php echo esc_html( $business_name ); ?></div>
					<div class="ezinv-public-number"><?php echo esc_html( sprintf( /* translators: %d: invoice ID. */
					__( 'Invoice #%d', 'easy-invoice-for-zarinpal' ), (int) $invoice->id ) ); ?></div>
				</div>
				<span class="ezinv-status ezinv-status-<?php echo esc_attr( sanitize_html_class( (string) $invoice->status ) ); ?>"><?php echo esc_html( self::status_label( (string) $invoice->status ) ); ?></span>
			</div>

			<h2 class="ezinv-public-title"><?php echo esc_html( $invoice->title ); ?></h2>

			<?php echo wp_kses_post( self::payment_notice( $payment_state, (string) $invoice->status ) ); ?>

			<?php if ( ! empty( $invoice->customer_name ) ) : ?>
				<div class="ezinv-public-customer">
					<span><?php echo esc_html__( 'Customer', 'easy-invoice-for-zarinpal' ); ?></span>
					<strong><?php echo esc_html( $invoice->customer_name ); ?></strong>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $items ) ) : ?>
				<div class="ezinv-public-table-wrap">
					<table class="ezinv-public-table">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Product', 'easy-invoice-for-zarinpal' ); ?></th>
								<th><?php echo esc_html__( 'Qty', 'easy-invoice-for-zarinpal' ); ?></th>
								<th><?php echo esc_html__( 'Unit price', 'easy-invoice-for-zarinpal' ); ?></th>
								<th><?php echo esc_html__( 'Total', 'easy-invoice-for-zarinpal' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $items as $item ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( isset( $item['name'] ) ? $item['name'] : __( 'Product', 'easy-invoice-for-zarinpal' ) ); ?></strong>
										<?php if ( ! empty( $item['sku'] ) ) : ?>
											<small><?php echo esc_html( 'SKU: ' . $item['sku'] ); ?></small>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( number_format_i18n( max( 1, absint( isset( $item['quantity'] ) ? $item['quantity'] : 1 ) ) ) ); ?></td>
									<td><?php echo esc_html( self::format_toman( isset( $item['unit_price_irr'] ) ? $item['unit_price_irr'] : 0 ) ); ?></td>
									<td><strong><?php echo esc_html( self::format_toman( isset( $item['line_total_irr'] ) ? $item['line_total_irr'] : 0 ) ); ?></strong></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $invoice->description ) ) : ?>
				<div class="ezinv-public-desc"><?php echo nl2br( esc_html( $invoice->description ) ); ?></div>
			<?php endif; ?>

			<div class="ezinv-public-totals">
				<div><span><?php echo esc_html__( 'Subtotal', 'easy-invoice-for-zarinpal' ); ?></span><strong><?php echo esc_html( self::format_toman( $invoice->subtotal_irr ) ); ?></strong></div>
				<?php if ( (int) $invoice->shipping_irr > 0 ) : ?>
					<div><span><?php echo esc_html__( 'Shipping', 'easy-invoice-for-zarinpal' ); ?></span><strong><?php echo esc_html( self::format_toman( $invoice->shipping_irr ) ); ?></strong></div>
				<?php endif; ?>
				<div class="ezinv-public-total"><span><?php echo esc_html__( 'Amount due', 'easy-invoice-for-zarinpal' ); ?></span><strong><?php echo esc_html( self::format_toman( $invoice->amount_irr ) ); ?></strong></div>
			</div>

			<?php if ( 'paid' === $invoice->status ) : ?>
				<div class="ezinv-public-paid">
					<?php echo esc_html__( 'This invoice has been paid successfully.', 'easy-invoice-for-zarinpal' ); ?>
					<?php if ( ! empty( $invoice->ref_id ) ) : ?>
						<br><small><?php echo esc_html( sprintf( /* translators: %s: gateway reference ID. */
					__( 'Gateway reference: %s', 'easy-invoice-for-zarinpal' ), $invoice->ref_id ) ); ?></small>
					<?php endif; ?>
				</div>
			<?php elseif ( self::merchant_configured() ) : ?>
				<form class="ezinv-payment-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ezinv_pay">
					<input type="hidden" name="token" value="<?php echo esc_attr( $invoice->token ); ?>">
					<?php wp_nonce_field( 'ezinv_pay_invoice_' . $invoice->token, 'ezinv_payment_nonce' ); ?>
					<button class="ezinv-public-pay" type="submit"><?php echo esc_html__( 'Pay invoice online', 'easy-invoice-for-zarinpal' ); ?></button>
				</form>
			<?php else : ?>
				<div class="ezinv-alert ezinv-alert-error"><?php echo esc_html__( 'The payment gateway has not been configured by the site administrator.', 'easy-invoice-for-zarinpal' ); ?></div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Start a payment. This is a state-changing public action and therefore
	 * requires POST plus a WordPress nonce tied to the invoice token.
	 *
	 * @return void
	 */
	public static function start_payment() {
		if ( ! self::is_post_request() ) {
			wp_die( esc_html__( 'Invalid request method.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 405 ) );
		}

		$token_raw = EZINV_Request::post_text( 'token' );
		$token     = self::sanitize_token( $token_raw );
		if ( '' === $token ) {
			wp_die( esc_html__( 'Invalid invoice token.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 400 ) );
		}

		$nonce = EZINV_Request::post_text( 'ezinv_payment_nonce' );
		if ( ! wp_verify_nonce( $nonce, 'ezinv_pay_invoice_' . $token ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the invoice page and try again.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 403 ) );
		}

		$invoice = EZINV_DB::get_by_token( $token );
		if ( ! $invoice ) {
			wp_die( esc_html__( 'Invoice not found.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 404 ) );
		}

		if ( 'paid' === $invoice->status ) {
			self::redirect_to_invoice( $invoice->token, 'success' );
		}

		if ( 'verifying' === $invoice->status ) {
			self::redirect_to_invoice( $invoice->token, 'processing' );
		}

		// Reuse an already issued authority to avoid multiple active Authorities for one invoice.
		if ( 'waiting_payment' === $invoice->status && self::valid_authority( $invoice->authority ) ) {
			self::redirect_to_gateway( $invoice->authority );
		}

		$callback_url = add_query_arg(
			array(
				'action' => 'ezinv_verify',
				'token'  => $invoice->token,
			),
			admin_url( 'admin-post.php' )
		);

		$result = EZINV_Gateway::request_payment( $invoice, $callback_url );
		if ( is_wp_error( $result ) ) {
			EZINV_DB::update(
				$invoice->id,
				array(
					'gateway_status' => 'REQUEST_ERROR',
					'updated_at'     => current_time( 'mysql' ),
				),
				array( '%s', '%s' )
			);
			self::redirect_to_invoice( $invoice->token, 'gateway_error' );
		}

		$code      = isset( $result['data']['code'] ) ? (int) $result['data']['code'] : 0;
		$authority = isset( $result['data']['authority'] ) ? self::sanitize_authority( $result['data']['authority'] ) : '';

		if ( 100 !== $code || '' === $authority ) {
			EZINV_DB::update(
				$invoice->id,
				array(
					'gateway_status' => 'REQUEST_FAILED_' . $code,
					'updated_at'     => current_time( 'mysql' ),
				),
				array( '%s', '%s' )
			);
			self::redirect_to_invoice( $invoice->token, 'gateway_error' );
		}

		$stored = EZINV_DB::update(
			$invoice->id,
			array(
				'authority'      => $authority,
				'status'         => 'waiting_payment',
				'gateway_status' => 'IN_BANK',
				'updated_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( ! $stored ) {
			EZINV_Logger::log( 'error', 'Could not persist payment Authority.', array( 'invoice_id' => (int) $invoice->id, 'operation' => 'request' ) );
			self::redirect_to_invoice( $invoice->token, 'gateway_error' );
		}

		self::redirect_to_gateway( $authority );
	}

	/**
	 * Handle the ZarinPal callback.
	 *
	 * A callback cannot use a WordPress nonce because it originates from an
	 * external payment service. Security is provided by an unguessable invoice
	 * token, strict Status/Authority validation, constant-time comparison with
	 * the stored Authority, and a server-to-server Verify call using the amount
	 * stored in the database. Callback query parameters never mark an invoice paid.
	 *
	 * @return void
	 */
	public static function callback() {
		if ( ! self::is_get_request() ) {
			wp_die( esc_html__( 'Invalid callback method.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 405 ) );
		}

		$token_raw     = EZINV_Request::get_text( 'token' );
		$status_raw    = EZINV_Request::get_text( 'Status' );
		$authority_raw = EZINV_Request::get_text( 'Authority' );
		$token         = self::sanitize_token( $token_raw );
		$status        = sanitize_key( (string) $status_raw );
		$authority     = self::sanitize_authority( $authority_raw );

		if ( '' === $token || ! in_array( strtoupper( $status ), array( 'OK', 'NOK' ), true ) || '' === $authority ) {
			wp_die( esc_html__( 'Invalid payment callback.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 400 ) );
		}

		$invoice = EZINV_DB::get_by_token( $token );
		if ( ! $invoice ) {
			wp_die( esc_html__( 'Invoice not found.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 404 ) );
		}

		// Validate Authority before changing any local state, including cancellation.
		if ( ! self::valid_authority( $invoice->authority ) || ! hash_equals( (string) $invoice->authority, $authority ) ) {
			EZINV_Logger::log( 'warning', 'Callback Authority mismatch.', array( 'invoice_id' => (int) $invoice->id, 'operation' => 'callback' ) );
			wp_die( esc_html__( 'The payment callback could not be authenticated.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 403 ) );
		}

		if ( 'paid' === $invoice->status ) {
			self::redirect_to_invoice( $invoice->token, 'success' );
		}

		if ( 'NOK' === strtoupper( $status ) ) {
			EZINV_DB::update(
				$invoice->id,
				array(
					'status'         => 'cancelled',
					'gateway_status' => 'FAILED',
					'updated_at'     => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s' )
			);
			self::redirect_to_invoice( $invoice->token, 'cancelled' );
		}

		$verified = self::verify_invoice( $invoice );
		if ( true === $verified ) {
			self::redirect_to_invoice( $invoice->token, 'success' );
		}

		if ( is_wp_error( $verified ) && 'ezinv_verification_busy' === $verified->get_error_code() ) {
			self::redirect_to_invoice( $invoice->token, 'processing' );
		}

		if ( is_wp_error( $verified ) && 'ezinv_gateway_rejected' === $verified->get_error_code() ) {
			self::redirect_to_invoice( $invoice->token, 'failed' );
		}

		self::redirect_to_invoice( $invoice->token, 'verify_pending' );
	}

	/**
	 * Securely verify an invoice using only server-side stored values.
	 * Used by callback and authorized admin re-verification.
	 *
	 * @param object $invoice Invoice object.
	 * @return true|WP_Error
	 */
	public static function verify_invoice( $invoice ) {
		if ( ! $invoice || empty( $invoice->id ) ) {
			return new WP_Error( 'ezinv_invalid_invoice', esc_html__( 'Invalid invoice.', 'easy-invoice-for-zarinpal' ) );
		}
		if ( 'paid' === $invoice->status ) {
			return true;
		}
		if ( ! self::valid_authority( $invoice->authority ) || (int) $invoice->amount_irr < 1 ) {
			return new WP_Error( 'ezinv_invalid_payment_state', esc_html__( 'Invoice payment state is incomplete.', 'easy-invoice-for-zarinpal' ) );
		}

		$invoice_id = (int) $invoice->id;
		if ( ! self::acquire_verification_lock( $invoice_id ) ) {
			return new WP_Error( 'ezinv_verification_busy', esc_html__( 'Payment verification is already in progress.', 'easy-invoice-for-zarinpal' ) );
		}

		try {
			$invoice = EZINV_DB::get_by_id( $invoice_id );
			if ( ! $invoice ) {
				return new WP_Error( 'ezinv_invalid_invoice', esc_html__( 'Invoice not found.', 'easy-invoice-for-zarinpal' ) );
			}
			if ( 'paid' === $invoice->status ) {
				return true;
			}

			EZINV_DB::update(
				$invoice->id,
				array(
					'status'     => 'verifying',
					'updated_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s' )
			);

			$result = EZINV_Gateway::verify_payment( $invoice );
			if ( is_wp_error( $result ) ) {
				EZINV_DB::update(
					$invoice->id,
					array(
						'status'         => 'waiting_payment',
						'gateway_status' => 'VERIFY_ERROR',
						'updated_at'     => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%s' )
				);
				return $result;
			}

			$code = isset( $result['data']['code'] ) ? (int) $result['data']['code'] : 0;
			if ( 100 === $code || 101 === $code ) {
				$ref_id   = isset( $result['data']['ref_id'] ) ? preg_replace( '/[^0-9]/', '', (string) $result['data']['ref_id'] ) : (string) $invoice->ref_id;
				$card_pan = isset( $result['data']['card_pan'] ) ? preg_replace( '/[^0-9*]/', '', (string) $result['data']['card_pan'] ) : (string) $invoice->card_pan;

				$updated = EZINV_DB::update(
					$invoice->id,
					array(
						'status'         => 'paid',
						'ref_id'         => substr( $ref_id, 0, 100 ),
						'card_pan'       => substr( $card_pan, 0, 100 ),
						'gateway_status' => 'VERIFIED',
						'paid_at'        => current_time( 'mysql' ),
						'updated_at'     => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				if ( ! $updated ) {
					EZINV_Logger::log( 'error', 'Gateway verified payment but local paid state could not be stored.', array( 'invoice_id' => (int) $invoice->id, 'operation' => 'verify', 'gateway_code' => $code ) );
					return new WP_Error( 'ezinv_db_update_failed', esc_html__( 'Payment was verified by the gateway, but the local invoice record could not be updated. Please re-verify from the admin.', 'easy-invoice-for-zarinpal' ) );
				}
				EZINV_Logger::log( 'info', 'Invoice verified successfully.', array( 'invoice_id' => (int) $invoice->id, 'operation' => 'verify', 'gateway_code' => $code ) );
				return true;
			}

			EZINV_DB::update(
				$invoice->id,
				array(
					'status'         => 'failed',
					'gateway_status' => 'VERIFY_FAILED_' . $code,
					'updated_at'     => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s' )
			);
			EZINV_Logger::log( 'warning', 'Gateway rejected invoice verification.', array( 'invoice_id' => (int) $invoice->id, 'operation' => 'verify', 'gateway_code' => $code ) );
			return new WP_Error( 'ezinv_gateway_rejected', EZINV_Gateway::error_message( $result, esc_html__( 'Payment could not be verified.', 'easy-invoice-for-zarinpal' ) ) );
		} finally {
			self::release_verification_lock( $invoice_id );
		}
	}

	/**
	 * Build public invoice URL.
	 *
	 * @param string $token Invoice token.
	 * @return string
	 */
	public static function invoice_url( $token ) {
		$base = EZINV_Settings::get_invoice_page_url();
		if ( '' === $base ) {
			$base = home_url( '/' );
		}
		return add_query_arg( 'ezinv_invoice', $token, $base );
	}

	/**
	 * Convert stored IRR to display Toman.
	 *
	 * @param mixed $amount_irr Amount in IRR.
	 * @return string
	 */
	public static function format_toman( $amount_irr ) {
		$amount_irr = max( 0, (int) $amount_irr );
		return /* translators: %s: formatted amount. */
					sprintf( __( '%s Toman', 'easy-invoice-for-zarinpal' ), number_format_i18n( (int) round( $amount_irr / 10 ) ) );
	}

	/**
	 * Decode stored invoice items.
	 *
	 * @param mixed $json JSON value.
	 * @return array
	 */
	public static function decode_items( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$items = json_decode( $json, true );
		return is_array( $items ) ? $items : array();
	}


	/**
	 * Prevent public invoice URLs from being cached by WordPress-aware caches.
	 * This does not replace or bypass the active theme/template.
	 *
	 * @return void
	 */
	public static function send_invoice_nocache_headers() {
		if ( self::request_contains_invoice_token() ) {
			nocache_headers();
		}
	}

	/**
	 * Ask search engines not to index public invoice-link URLs.
	 *
	 * @param array $robots Existing robots directives.
	 * @return array
	 */
	public static function invoice_robots( $robots ) {
		if ( self::request_contains_invoice_token() ) {
			$robots['noindex']   = true;
			$robots['nofollow']  = true;
			$robots['noarchive'] = true;
		}
		return $robots;
	}

	/**
	 * Check supported public invoice query arguments without trusting their value.
	 *
	 * @return bool
	 */
	private static function request_contains_invoice_token() {
		$keys = array( 'ezinv_invoice', 'ezi_invoice' );
		foreach ( $keys as $key ) {
			if ( '' !== self::sanitize_token( EZINV_Request::get_text( $key ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sanitize a public invoice token.
	 *
	 * @param mixed $token Raw token.
	 * @return string
	 */
	public static function sanitize_token( $token ) {
		if ( ! is_scalar( $token ) ) {
			return '';
		}
		$token = sanitize_text_field( (string) $token );
		if ( strlen( $token ) < 20 || strlen( $token ) > 64 || ! preg_match( '/^[A-Za-z0-9]+$/', $token ) ) {
			return '';
		}
		return $token;
	}

	/**
	 * Sanitize a gateway Authority value.
	 *
	 * @param mixed $authority Raw Authority.
	 * @return string
	 */
	public static function sanitize_authority( $authority ) {
		if ( ! is_scalar( $authority ) ) {
			return '';
		}
		$authority = sanitize_text_field( (string) $authority );
		if ( strlen( $authority ) < 10 || strlen( $authority ) > 100 || ! preg_match( '/^[A-Za-z0-9]+$/', $authority ) ) {
			return '';
		}
		return $authority;
	}

	/**
	 * Validate an Authority already stored locally.
	 *
	 * @param mixed $authority Authority.
	 * @return bool
	 */
	public static function valid_authority( $authority ) {
		return '' !== self::sanitize_authority( $authority );
	}

	/**
	 * Check whether the Merchant ID is configured.
	 *
	 * @return bool
	 */
	private static function merchant_configured() {
		$merchant_id = trim( (string) get_option( EZINV_Settings::OPT_MERCHANT_ID, '' ) );
		return 36 === strlen( $merchant_id ) && (bool) preg_match( '/^[A-Za-z0-9-]{36}$/', $merchant_id );
	}

	/**
	 * Redirect to the external ZarinPal start page.
	 *
	 * @param string $authority Validated Authority.
	 * @return void
	 */
	private static function redirect_to_gateway( $authority ) {
		$authority = self::sanitize_authority( $authority );
		if ( '' === $authority ) {
			wp_die( esc_html__( 'Invalid payment Authority.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 400 ) );
		}

		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_gateway_redirect_host' ), 10, 2 );
		wp_safe_redirect( esc_url_raw( EZINV_Gateway::START_URL . rawurlencode( $authority ) ) );
		exit;
	}

	/**
	 * Allow only the documented ZarinPal payment host for the gateway redirect.
	 *
	 * @param array  $hosts Allowed hosts.
	 * @param string $host  Redirect host being checked.
	 * @return array
	 */
	public static function allow_gateway_redirect_host( $hosts, $host ) {
		if ( 'payment.zarinpal.com' === strtolower( (string) $host ) ) {
			$hosts[] = 'payment.zarinpal.com';
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Redirect to a local invoice URL.
	 *
	 * @param string $token Invoice token.
	 * @param string $state Public state code.
	 * @return void
	 */
	private static function redirect_to_invoice( $token, $state ) {
		$url = add_query_arg( 'payment', sanitize_key( $state ), self::invoice_url( $token ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Create a short-lived, atomic database lock using the unique option name.
	 *
	 * @param int $invoice_id Invoice ID.
	 * @return bool
	 */
	private static function acquire_verification_lock( $invoice_id ) {
		$key      = 'ezinv_verify_lock_' . absint( $invoice_id );
		$existing = absint( get_option( $key, 0 ) );
		$now      = time();

		if ( $existing && ( $now - $existing ) < 120 ) {
			return false;
		}
		if ( $existing ) {
			delete_option( $key );
		}

		return add_option( $key, $now, '', 'no' );
	}

	/**
	 * Release verification lock.
	 *
	 * @param int $invoice_id Invoice ID.
	 * @return void
	 */
	private static function release_verification_lock( $invoice_id ) {
		delete_option( 'ezinv_verify_lock_' . absint( $invoice_id ) );
	}

	/**
	 * Detect a POST request.
	 *
	 * @return bool
	 */
	private static function is_post_request() {
		return 'POST' === EZINV_Request::method();
	}

	/**
	 * Detect a GET request.
	 *
	 * @return bool
	 */
	private static function is_get_request() {
		return 'GET' === EZINV_Request::method();
	}

	/**
	 * Human-readable local status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( $status ) {
		$labels = array(
			'pending'         => __( 'Pending', 'easy-invoice-for-zarinpal' ),
			'waiting_payment' => __( 'Awaiting payment', 'easy-invoice-for-zarinpal' ),
			'verifying'       => __( 'Verifying', 'easy-invoice-for-zarinpal' ),
			'paid'            => __( 'Paid', 'easy-invoice-for-zarinpal' ),
			'cancelled'       => __( 'Cancelled', 'easy-invoice-for-zarinpal' ),
			'failed'          => __( 'Failed', 'easy-invoice-for-zarinpal' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Unknown', 'easy-invoice-for-zarinpal' );
	}

	/**
	 * Get a safe public notice.
	 *
	 * @param string $state          State code from the local redirect.
	 * @param string $invoice_status Authoritative local invoice status.
	 * @return string
	 */
	private static function payment_notice( $state, $invoice_status ) {
		$state          = sanitize_key( (string) $state );
		$invoice_status = sanitize_key( (string) $invoice_status );

		// A query string can never manufacture a successful-payment message.
		if ( 'paid' === $invoice_status ) {
			$state = 'success';
		} elseif ( 'success' === $state ) {
			$state = '';
		}

		$notices = array(
			'success'       => array( 'success', __( 'Payment was verified successfully.', 'easy-invoice-for-zarinpal' ) ),
			'cancelled'     => array( 'warning', __( 'Payment was cancelled or not completed.', 'easy-invoice-for-zarinpal' ) ),
			'failed'        => array( 'error', __( 'The gateway did not verify this payment.', 'easy-invoice-for-zarinpal' ) ),
			'gateway_error' => array( 'error', __( 'The payment gateway is temporarily unavailable. Please try again.', 'easy-invoice-for-zarinpal' ) ),
			'verify_pending'=> array( 'warning', __( 'The payment result could not be confirmed yet. Please contact the site administrator before paying again.', 'easy-invoice-for-zarinpal' ) ),
			'processing'    => array( 'warning', __( 'Payment verification is in progress. Please refresh this page shortly.', 'easy-invoice-for-zarinpal' ) ),
		);

		if ( ! isset( $notices[ $state ] ) ) {
			return '';
		}

		$type = sanitize_html_class( $notices[ $state ][0] );
		$text = esc_html( $notices[ $state ][1] );
		return '<div class="ezinv-alert ezinv-alert-' . esc_attr( $type ) . '">' . $text . '</div>';
	}
}
