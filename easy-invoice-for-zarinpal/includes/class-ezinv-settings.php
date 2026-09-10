<?php
/**
 * Plugin settings.
 *
 * @package Easy_Invoice_For_Zarinpal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EZINV_Settings {
	const OPT_MERCHANT_ID         = 'ezinv_zarinpal_merchant_id';
	const OPT_BUSINESS_NAME       = 'ezinv_business_name';
	const OPT_INVOICE_PAGE_ID     = 'ezinv_invoice_page_id';
	const OPT_LEGACY_INVOICE_PAGE = 'ezi_invoice_page';
	const OPT_FIXED_SHIPPING      = 'ezinv_fixed_shipping_toman';
	const OPT_ENABLE_LOGGING      = 'ezinv_enable_logging';
	const OPT_LOG_ENTRIES         = 'ezinv_debug_log_entries';
	const OPT_DELETE_ON_UNINSTALL = 'ezinv_delete_on_uninstall';

	/**
	 * Register settings hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_privacy_eraser' ) );
	}

	/**
	 * Register settings with the Settings API.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			'ezinv_settings',
			self::OPT_MERCHANT_ID,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_merchant_id' ),
				'default'           => '',
			)
		);

		register_setting(
			'ezinv_settings',
			self::OPT_BUSINESS_NAME,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_business_name' ),
				'default'           => get_bloginfo( 'name' ),
			)
		);

		register_setting(
			'ezinv_settings',
			self::OPT_INVOICE_PAGE_ID,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_invoice_page_id' ),
				'default'           => 0,
			)
		);

		register_setting(
			'ezinv_settings',
			self::OPT_FIXED_SHIPPING,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_nonnegative_int' ),
				'default'           => 0,
			)
		);

		register_setting(
			'ezinv_settings',
			self::OPT_ENABLE_LOGGING,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				'default'           => false,
			)
		);

		register_setting(
			'ezinv_settings',
			self::OPT_DELETE_ON_UNINSTALL,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ),
				'default'           => false,
			)
		);
	}

	/**
	 * Validate a ZarinPal merchant ID.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_merchant_id( $value ) {
		$value = sanitize_text_field( (string) $value );
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( 36 !== strlen( $value ) || ! preg_match( '/^[A-Za-z0-9-]{36}$/', $value ) ) {
			add_settings_error(
				self::OPT_MERCHANT_ID,
				'ezinv_invalid_merchant',
				esc_html__( 'Merchant ID must be exactly 36 characters and contain only letters, numbers, and hyphens.', 'easy-invoice-for-zarinpal' )
			);
			return (string) get_option( self::OPT_MERCHANT_ID, '' );
		}

		return $value;
	}

	/**
	 * Validate the selected invoice page.
	 *
	 * @param mixed $value Raw page ID.
	 * @return int
	 */
	public static function sanitize_invoice_page_id( $value ) {
		$page_id = absint( $value );
		if ( 0 === $page_id ) {
			return 0;
		}

		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
			add_settings_error(
				self::OPT_INVOICE_PAGE_ID,
				'ezinv_invalid_invoice_page',
				esc_html__( 'Please select a published WordPress page for the invoice shortcode.', 'easy-invoice-for-zarinpal' )
			);
			return (int) get_option( self::OPT_INVOICE_PAGE_ID, 0 );
		}

		return $page_id;
	}

	/**
	 * Sanitize a non-negative integer.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_nonnegative_int( $value ) {
		if ( ! is_scalar( $value ) ) {
			return 0;
		}
		$value = preg_replace( '/[^0-9]/', '', (string) $value );
		if ( ! is_string( $value ) || '' === $value ) {
			return 0;
		}
		$max_toman = intdiv( PHP_INT_MAX, 10 );
		if ( (float) $value > $max_toman ) {
			add_settings_error(
				self::OPT_FIXED_SHIPPING,
				'ezinv_shipping_too_large',
				esc_html__( 'The fixed shipping amount is too large.', 'easy-invoice-for-zarinpal' )
			);
			return (int) get_option( self::OPT_FIXED_SHIPPING, 0 );
		}
		return (int) $value;
	}

	/**
	 * Sanitize the business name with the database field length in mind.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_business_name( $value ) {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, 190 );
		}
		return substr( $value, 0, 190 );
	}

	/**
	 * Sanitize checkbox values.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function sanitize_checkbox( $value ) {
		return ! empty( $value );
	}

	/**
	 * Return the configured invoice page URL.
	 *
	 * @return string
	 */
	public static function get_invoice_page_url() {
		$page_id = absint( get_option( self::OPT_INVOICE_PAGE_ID, 0 ) );
		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( is_string( $url ) ) {
				return $url;
			}
		}
		return '';
	}

	/**
	 * Suggest privacy policy text for site owners.
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'This site may use Easy Invoice for ZarinPal to create payment invoices. The plugin can store invoice details such as customer name, mobile number, email address, selected products, amounts, payment status, masked card number, and gateway reference ID in the site database.', 'easy-invoice-for-zarinpal' ) . '</p>';
		$content .= '<p>' . esc_html__( 'When a payment is initiated, the invoice amount, description, order ID, and—when provided—customer mobile number and email address are sent to ZarinPal to process the payment. The plugin does not send telemetry or analytics to the plugin author.', 'easy-invoice-for-zarinpal' ) . '</p>';

		wp_add_privacy_policy_content( 'Easy Invoice for ZarinPal', wp_kses_post( $content ) );
	}

	/**
	 * Register a WordPress personal-data exporter for invoice customer data.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_privacy_exporter( $exporters ) {
		$exporters['easy-invoice-for-zarinpal'] = array(
			'exporter_friendly_name' => __( 'Easy Invoice for ZarinPal invoices', 'easy-invoice-for-zarinpal' ),
			'callback'               => array( __CLASS__, 'privacy_exporter' ),
		);
		return $exporters;
	}

	/**
	 * Export invoice data matched by customer email.
	 *
	 * @param string $email_address Requested email.
	 * @param int    $page          Export page.
	 * @return array
	 */
	public static function privacy_exporter( $email_address, $page = 1 ) {
		$email = sanitize_email( $email_address );
		if ( '' === $email || ! is_email( $email ) ) {
			return array( 'data' => array(), 'done' => true );
		}

		$per_page = 50;
		$invoices = EZINV_DB::get_by_customer_email( $email, max( 1, absint( $page ) ), $per_page );
		$data      = array();

		foreach ( $invoices as $invoice ) {
			$data[] = array(
				'group_id'    => 'easy-invoice-for-zarinpal-invoices',
				'group_label' => __( 'Payment invoices', 'easy-invoice-for-zarinpal' ),
				'item_id'     => 'invoice-' . (int) $invoice->id,
				'data'        => array(
					array( 'name' => __( 'Invoice ID', 'easy-invoice-for-zarinpal' ), 'value' => (string) (int) $invoice->id ),
					array( 'name' => __( 'Invoice title', 'easy-invoice-for-zarinpal' ), 'value' => (string) $invoice->title ),
					array( 'name' => __( 'Customer name', 'easy-invoice-for-zarinpal' ), 'value' => (string) $invoice->customer_name ),
					array( 'name' => __( 'Mobile', 'easy-invoice-for-zarinpal' ), 'value' => (string) $invoice->mobile ),
					array( 'name' => __( 'Email', 'easy-invoice-for-zarinpal' ), 'value' => (string) $invoice->email ),
					array( 'name' => __( 'Amount (IRR)', 'easy-invoice-for-zarinpal' ), 'value' => (string) $invoice->amount_irr ),
					array( 'name' => __( 'Payment status', 'easy-invoice-for-zarinpal' ), 'value' => (string) $invoice->status ),
					array( 'name' => __( 'Gateway reference', 'easy-invoice-for-zarinpal' ), 'value' => (string) $invoice->ref_id ),
					array( 'name' => __( 'Masked card number', 'easy-invoice-for-zarinpal' ), 'value' => (string) $invoice->card_pan ),
					array( 'name' => __( 'Created at', 'easy-invoice-for-zarinpal' ), 'value' => (string) $invoice->created_at ),
					array( 'name' => __( 'Paid at', 'easy-invoice-for-zarinpal' ), 'value' => (string) $invoice->paid_at ),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $invoices ) < $per_page,
		);
	}

	/**
	 * Register a WordPress personal-data eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_privacy_eraser( $erasers ) {
		$erasers['easy-invoice-for-zarinpal'] = array(
			'eraser_friendly_name' => __( 'Easy Invoice for ZarinPal customer contact data', 'easy-invoice-for-zarinpal' ),
			'callback'             => array( __CLASS__, 'privacy_eraser' ),
		);
		return $erasers;
	}

	/**
	 * Erase contact fields while retaining financial transaction records.
	 *
	 * @param string $email_address Requested email.
	 * @param int    $page          Eraser page (unused; operation is bounded by exact email match).
	 * @return array
	 */
	public static function privacy_eraser( $email_address, $page = 1 ) {
		$email = sanitize_email( $email_address );
		if ( '' === $email || ! is_email( $email ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$removed = EZINV_DB::anonymize_customer_by_email( $email );
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $removed > 0,
			'messages'       => $removed > 0 ? array( __( 'Customer name, mobile, and email were erased. Financial transaction records were retained.', 'easy-invoice-for-zarinpal' ) ) : array(),
			'done'           => true,
		);
	}

}
