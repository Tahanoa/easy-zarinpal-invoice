<?php
/**
 * WordPress admin interface.
 *
 * @package Easy_Invoice_For_Zarinpal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EZINV_Admin {
	const NOTICE_TRANSIENT_PREFIX = 'ezinv_admin_notice_';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_ezinv_search_products', array( __CLASS__, 'ajax_search_products' ) );
		add_action( 'admin_post_ezinv_create_invoice', array( __CLASS__, 'create_invoice' ) );
		add_action( 'admin_post_ezinv_delete_invoice', array( __CLASS__, 'delete_invoice' ) );
		add_action( 'admin_post_ezinv_inquiry_invoice', array( __CLASS__, 'inquiry_invoice' ) );
		add_action( 'admin_post_ezinv_reverify_invoice', array( __CLASS__, 'reverify_invoice' ) );
		add_action( 'admin_post_ezinv_clear_logs', array( __CLASS__, 'clear_logs' ) );
	}

	/**
	 * Register menu pages.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			esc_html__( 'Payment Invoices', 'easy-invoice-for-zarinpal' ),
			esc_html__( 'Invoices', 'easy-invoice-for-zarinpal' ),
			'manage_options',
			'ezinv-invoices',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-media-spreadsheet',
			56
		);

		add_submenu_page(
			'ezinv-invoices',
			esc_html__( 'Invoice Settings', 'easy-invoice-for-zarinpal' ),
			esc_html__( 'Settings', 'easy-invoice-for-zarinpal' ),
			'manage_options',
			'ezinv-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * Load local assets only on this plugin's admin screens.
	 *
	 * @param string $hook_suffix Current admin hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		$allowed = array( 'toplevel_page_ezinv-invoices', 'invoices_page_ezinv-settings' );
		if ( ! in_array( $hook_suffix, $allowed, true ) ) {
			return;
		}

		wp_enqueue_style( 'ezinv-admin', EZINV_PLUGIN_URL . 'admin/css/admin.css', array(), EZINV_VERSION );
		wp_enqueue_script( 'ezinv-admin', EZINV_PLUGIN_URL . 'admin/js/admin.js', array(), EZINV_VERSION, true );

		wp_localize_script(
			'ezinv-admin',
			'ezinvAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'productNonce'  => wp_create_nonce( 'ezinv_product_search' ),
				'shippingToman' => (int) get_option( EZINV_Settings::OPT_FIXED_SHIPPING, 0 ),
				'i18n'          => array(
					'noResults'      => esc_html__( 'No products found.', 'easy-invoice-for-zarinpal' ),
					'searchError'    => esc_html__( 'Product search failed.', 'easy-invoice-for-zarinpal' ),
					'remove'         => esc_html__( 'Remove', 'easy-invoice-for-zarinpal' ),
					'emptyProducts'  => esc_html__( 'No products selected yet.', 'easy-invoice-for-zarinpal' ),
					'toman'          => esc_html__( 'Toman', 'easy-invoice-for-zarinpal' ),
					'copied'         => esc_html__( 'Link copied.', 'easy-invoice-for-zarinpal' ),
					'copyFailed'     => esc_html__( 'Could not copy the link.', 'easy-invoice-for-zarinpal' ),
					'copyLabel'      => esc_html__( 'Copy link', 'easy-invoice-for-zarinpal' ),
					'deleteConfirm'  => esc_html__( 'Delete this invoice?', 'easy-invoice-for-zarinpal' ),
				),
			)
		);
	}

	/**
	 * Render invoice dashboard.
	 *
	 * @return void
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$invoices           = EZINV_DB::get_recent_invoices( 200 );
		$woocommerce_active = self::woocommerce_available();
		$shipping_toman     = max( 0, (int) get_option( EZINV_Settings::OPT_FIXED_SHIPPING, 0 ) );
		$invoice_page_url   = EZINV_Settings::get_invoice_page_url();
		$merchant_configured= self::merchant_configured();

		self::render_notice();
		?>
		<div class="wrap ezinv-admin">
			<div class="ezinv-admin-heading">
				<div>
					<h1><?php echo esc_html__( 'Payment Invoices', 'easy-invoice-for-zarinpal' ); ?></h1>
					<p><?php echo esc_html__( 'Create secure payment links using a manual amount or WooCommerce products.', 'easy-invoice-for-zarinpal' ); ?></p>
				</div>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ezinv-settings' ) ); ?>"><?php echo esc_html__( 'Settings', 'easy-invoice-for-zarinpal' ); ?></a>
			</div>

			<?php if ( ! $merchant_configured ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html__( 'ZarinPal Merchant ID is not configured. Invoices can be created, but payment cannot start until it is configured.', 'easy-invoice-for-zarinpal' ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' === $invoice_page_url ) : ?>
				<div class="notice notice-warning inline"><p><?php echo wp_kses_post( __( 'Select a published invoice page in Settings and place <code>[ezinv_invoice]</code> on that page.', 'easy-invoice-for-zarinpal' ) ); ?></p></div>
			<?php endif; ?>

			<div class="ezinv-grid">
				<section class="ezinv-card">
					<h2><?php echo esc_html__( 'Create a new invoice', 'easy-invoice-for-zarinpal' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ezinv-create-form">
						<input type="hidden" name="action" value="ezinv_create_invoice">
						<?php wp_nonce_field( 'ezinv_create_invoice', 'ezinv_create_nonce' ); ?>

						<div class="ezinv-field">
							<label for="ezinv-title"><?php echo esc_html__( 'Invoice title', 'easy-invoice-for-zarinpal' ); ?> <span aria-hidden="true">*</span></label>
							<input type="text" id="ezinv-title" name="title" maxlength="255" required>
						</div>

						<div class="ezinv-products-box">
							<h3><?php echo esc_html__( 'WooCommerce products', 'easy-invoice-for-zarinpal' ); ?></h3>
							<?php if ( $woocommerce_active ) : ?>
								<label class="screen-reader-text" for="ezinv-product-search"><?php echo esc_html__( 'Search products', 'easy-invoice-for-zarinpal' ); ?></label>
								<div class="ezinv-search-wrap">
									<input type="search" id="ezinv-product-search" autocomplete="off" placeholder="<?php echo esc_attr__( 'Search by product name or SKU…', 'easy-invoice-for-zarinpal' ); ?>">
									<div id="ezinv-product-results" class="ezinv-product-results" role="listbox" aria-label="<?php echo esc_attr__( 'Product search results', 'easy-invoice-for-zarinpal' ); ?>"></div>
								</div>
								<p class="description"><?php echo esc_html__( 'Product prices are read again from WooCommerce on submit and saved as a snapshot. Browser-submitted prices are never trusted.', 'easy-invoice-for-zarinpal' ); ?></p>
							<?php else : ?>
								<div class="ezinv-soft-warning"><?php echo esc_html__( 'WooCommerce is not active. You can still create invoices with a manual amount.', 'easy-invoice-for-zarinpal' ); ?></div>
							<?php endif; ?>

							<div class="ezinv-table-scroll">
								<table class="ezinv-items-table">
									<thead><tr><th><?php echo esc_html__( 'Product', 'easy-invoice-for-zarinpal' ); ?></th><th><?php echo esc_html__( 'Unit price', 'easy-invoice-for-zarinpal' ); ?></th><th><?php echo esc_html__( 'Qty', 'easy-invoice-for-zarinpal' ); ?></th><th><?php echo esc_html__( 'Line total', 'easy-invoice-for-zarinpal' ); ?></th><th><span class="screen-reader-text"><?php echo esc_html__( 'Actions', 'easy-invoice-for-zarinpal' ); ?></span></th></tr></thead>
									<tbody id="ezinv-items-body"><tr class="ezinv-empty-row"><td colspan="5" class="ezinv-empty-products"><?php echo esc_html__( 'No products selected yet.', 'easy-invoice-for-zarinpal' ); ?></td></tr></tbody>
								</table>
							</div>
						</div>

						<div class="ezinv-field ezinv-manual-field">
							<label for="ezinv-amount"><?php echo esc_html__( 'Manual amount (Toman)', 'easy-invoice-for-zarinpal' ); ?></label>
							<input type="number" id="ezinv-amount" name="amount_toman" min="0" step="1" inputmode="numeric">
							<p class="description"><?php echo esc_html__( 'Used only when no WooCommerce product is selected.', 'easy-invoice-for-zarinpal' ); ?></p>
						</div>

						<div class="ezinv-shipping-box">
							<label><input type="checkbox" id="ezinv-include-shipping" name="include_shipping" value="1"> <?php echo esc_html__( 'Add the configured fixed shipping fee to this invoice', 'easy-invoice-for-zarinpal' ); ?></label>
							<p><?php echo esc_html( /* translators: %s: shipping amount in Toman. */
					sprintf( __( 'Current fixed shipping fee: %s Toman', 'easy-invoice-for-zarinpal' ), number_format_i18n( $shipping_toman ) ) ); ?></p>
						</div>

						<div class="ezinv-summary" aria-live="polite">
							<div><span><?php echo esc_html__( 'Items / manual amount', 'easy-invoice-for-zarinpal' ); ?></span><strong id="ezinv-summary-subtotal">0</strong></div>
							<div><span><?php echo esc_html__( 'Shipping', 'easy-invoice-for-zarinpal' ); ?></span><strong id="ezinv-summary-shipping">0</strong></div>
							<div class="ezinv-summary-total"><span><?php echo esc_html__( 'Invoice total', 'easy-invoice-for-zarinpal' ); ?></span><strong id="ezinv-summary-total">0</strong></div>
						</div>

						<div class="ezinv-form-grid">
							<div class="ezinv-field"><label for="ezinv-customer-name"><?php echo esc_html__( 'Customer name', 'easy-invoice-for-zarinpal' ); ?></label><input type="text" id="ezinv-customer-name" name="customer_name" maxlength="190"></div>
							<div class="ezinv-field"><label for="ezinv-mobile"><?php echo esc_html__( 'Mobile', 'easy-invoice-for-zarinpal' ); ?></label><input type="tel" id="ezinv-mobile" name="mobile" maxlength="20" inputmode="tel"></div>
							<div class="ezinv-field"><label for="ezinv-email"><?php echo esc_html__( 'Email', 'easy-invoice-for-zarinpal' ); ?></label><input type="email" id="ezinv-email" name="email" maxlength="190"></div>
						</div>

						<div class="ezinv-field">
							<label for="ezinv-description"><?php echo esc_html__( 'Description', 'easy-invoice-for-zarinpal' ); ?></label>
							<textarea id="ezinv-description" name="description" rows="4" maxlength="2000"></textarea>
						</div>

						<?php submit_button( esc_html__( 'Create invoice', 'easy-invoice-for-zarinpal' ), 'primary ezinv-submit', 'submit', false ); ?>
					</form>
				</section>

				<aside class="ezinv-card ezinv-side-card">
					<h2><?php echo esc_html__( 'Status', 'easy-invoice-for-zarinpal' ); ?></h2>
					<div class="ezinv-side-stat"><span><?php echo esc_html__( 'ZarinPal', 'easy-invoice-for-zarinpal' ); ?></span><strong><?php echo $merchant_configured ? esc_html__( 'Configured', 'easy-invoice-for-zarinpal' ) : esc_html__( 'Not configured', 'easy-invoice-for-zarinpal' ); ?></strong></div>
					<div class="ezinv-side-stat"><span><?php echo esc_html__( 'WooCommerce', 'easy-invoice-for-zarinpal' ); ?></span><strong><?php echo $woocommerce_active ? esc_html__( 'Active', 'easy-invoice-for-zarinpal' ) : esc_html__( 'Not active', 'easy-invoice-for-zarinpal' ); ?></strong></div>
					<div class="ezinv-side-stat"><span><?php echo esc_html__( 'Fixed shipping', 'easy-invoice-for-zarinpal' ); ?></span><strong><?php echo esc_html( number_format_i18n( $shipping_toman ) . ' ' . __( 'Toman', 'easy-invoice-for-zarinpal' ) ); ?></strong></div>
					<div class="ezinv-side-stat"><span><?php echo esc_html__( 'Invoice shortcode', 'easy-invoice-for-zarinpal' ); ?></span><code>[ezinv_invoice]</code></div>
				</aside>
			</div>

			<section class="ezinv-card ezinv-list-card">
				<h2><?php echo esc_html__( 'Recent invoices', 'easy-invoice-for-zarinpal' ); ?></h2>
				<div class="ezinv-table-scroll">
					<table class="widefat striped ezinv-invoice-table">
						<thead><tr><th>#</th><th><?php echo esc_html__( 'Invoice', 'easy-invoice-for-zarinpal' ); ?></th><th><?php echo esc_html__( 'Customer', 'easy-invoice-for-zarinpal' ); ?></th><th><?php echo esc_html__( 'Amount', 'easy-invoice-for-zarinpal' ); ?></th><th><?php echo esc_html__( 'Status', 'easy-invoice-for-zarinpal' ); ?></th><th><?php echo esc_html__( 'Reference', 'easy-invoice-for-zarinpal' ); ?></th><th><?php echo esc_html__( 'Created', 'easy-invoice-for-zarinpal' ); ?></th><th><?php echo esc_html__( 'Actions', 'easy-invoice-for-zarinpal' ); ?></th></tr></thead>
						<tbody>
						<?php if ( empty( $invoices ) ) : ?>
							<tr><td colspan="8"><?php echo esc_html__( 'No invoices have been created yet.', 'easy-invoice-for-zarinpal' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $invoices as $invoice ) : ?>
								<?php $invoice_url = EZINV_Invoice::invoice_url( $invoice->token ); ?>
								<tr>
									<td><?php echo esc_html( (string) (int) $invoice->id ); ?></td>
									<td><strong><?php echo esc_html( $invoice->title ); ?></strong><?php echo wp_kses_post( self::items_count_text( $invoice ) ); ?></td>
									<td><?php echo esc_html( $invoice->customer_name ? $invoice->customer_name : '—' ); ?></td>
									<td><strong><?php echo esc_html( EZINV_Invoice::format_toman( $invoice->amount_irr ) ); ?></strong></td>
									<td><?php echo wp_kses_post( self::status_badge( $invoice ) ); ?></td>
									<td><?php echo esc_html( $invoice->ref_id ? $invoice->ref_id : '—' ); ?></td>
									<td><?php echo esc_html( mysql2date( 'Y/m/d H:i', $invoice->created_at ) ); ?></td>
									<td>
										<div class="ezinv-actions">
											<a class="button button-small" href="<?php echo esc_url( $invoice_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'View', 'easy-invoice-for-zarinpal' ); ?></a>
											<button type="button" class="button button-small ezinv-copy" data-url="<?php echo esc_attr( $invoice_url ); ?>"><?php echo esc_html__( 'Copy link', 'easy-invoice-for-zarinpal' ); ?></button>
											<?php if ( ! empty( $invoice->authority ) ) : ?>
												<?php self::action_form( 'ezinv_inquiry_invoice', $invoice->id, 'ezinv_inquiry_' . (int) $invoice->id, __( 'Inquiry', 'easy-invoice-for-zarinpal' ) ); ?>
												<?php if ( 'paid' !== $invoice->status ) : ?>
													<?php self::action_form( 'ezinv_reverify_invoice', $invoice->id, 'ezinv_reverify_' . (int) $invoice->id, __( 'Re-verify', 'easy-invoice-for-zarinpal' ) ); ?>
												<?php endif; ?>
											<?php endif; ?>
											<?php self::action_form( 'ezinv_delete_invoice', $invoice->id, 'ezinv_delete_' . (int) $invoice->id, __( 'Delete', 'easy-invoice-for-zarinpal' ), 'ezinv-delete-form' ); ?>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Render plugin settings.
	 *
	 * @return void
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page_id = absint( get_option( EZINV_Settings::OPT_INVOICE_PAGE_ID, 0 ) );
		?>
		<div class="wrap ezinv-admin">
			<div class="ezinv-admin-heading"><div><h1><?php echo esc_html__( 'Invoice Settings', 'easy-invoice-for-zarinpal' ); ?></h1><p><?php echo esc_html__( 'Configure the payment gateway, invoice page, shipping, privacy-friendly logs, and uninstall behavior.', 'easy-invoice-for-zarinpal' ); ?></p></div></div>
			<?php self::render_notice(); ?>
			<?php settings_errors(); ?>
			<div class="ezinv-card ezinv-settings-card">
				<form method="post" action="options.php">
					<?php settings_fields( 'ezinv_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ezinv-merchant-id"><?php echo esc_html__( 'ZarinPal Merchant ID', 'easy-invoice-for-zarinpal' ); ?></label></th>
							<td><input class="regular-text code" id="ezinv-merchant-id" name="<?php echo esc_attr( EZINV_Settings::OPT_MERCHANT_ID ); ?>" value="<?php echo esc_attr( get_option( EZINV_Settings::OPT_MERCHANT_ID, '' ) ); ?>" maxlength="36" autocomplete="off"><p class="description"><?php echo esc_html__( 'The 36-character Merchant ID from your ZarinPal account.', 'easy-invoice-for-zarinpal' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="ezinv-business-name"><?php echo esc_html__( 'Business name', 'easy-invoice-for-zarinpal' ); ?></label></th>
							<td><input class="regular-text" id="ezinv-business-name" name="<?php echo esc_attr( EZINV_Settings::OPT_BUSINESS_NAME ); ?>" value="<?php echo esc_attr( get_option( EZINV_Settings::OPT_BUSINESS_NAME, get_bloginfo( 'name' ) ) ); ?>" maxlength="190"></td>
						</tr>
						<tr>
							<th scope="row"><label for="ezinv-invoice-page"><?php echo esc_html__( 'Invoice page', 'easy-invoice-for-zarinpal' ); ?></label></th>
							<td>
								<?php
								wp_dropdown_pages(
									array(
										'name'              => esc_attr( EZINV_Settings::OPT_INVOICE_PAGE_ID ),
										'id'                => 'ezinv-invoice-page',
										'selected'          => absint( $page_id ),
										'show_option_none'  => esc_html__( '— Select a page —', 'easy-invoice-for-zarinpal' ),
										'option_none_value' => '0',
									)
								);
								?>
								<p class="description"><?php echo wp_kses_post( __( 'Create a normal WordPress page with your preferred slug, place <code>[ezinv_invoice]</code> in its content, then select it here. The theme remains responsible for the header and footer.', 'easy-invoice-for-zarinpal' ) ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ezinv-fixed-shipping"><?php echo esc_html__( 'Fixed shipping fee', 'easy-invoice-for-zarinpal' ); ?></label></th>
							<td><input type="number" min="0" step="1" class="regular-text" id="ezinv-fixed-shipping" name="<?php echo esc_attr( EZINV_Settings::OPT_FIXED_SHIPPING ); ?>" value="<?php echo esc_attr( (string) absint( get_option( EZINV_Settings::OPT_FIXED_SHIPPING, 0 ) ) ); ?>"> <?php echo esc_html__( 'Toman', 'easy-invoice-for-zarinpal' ); ?><p class="description"><?php echo esc_html__( 'This amount can be enabled or disabled for each invoice.', 'easy-invoice-for-zarinpal' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Debug logging', 'easy-invoice-for-zarinpal' ); ?></th>
							<td><input type="hidden" name="<?php echo esc_attr( EZINV_Settings::OPT_ENABLE_LOGGING ); ?>" value="0"><label><input type="checkbox" name="<?php echo esc_attr( EZINV_Settings::OPT_ENABLE_LOGGING ); ?>" value="1" <?php checked( (bool) get_option( EZINV_Settings::OPT_ENABLE_LOGGING, false ) ); ?>> <?php echo esc_html__( 'Enable redacted gateway debug logging', 'easy-invoice-for-zarinpal' ); ?></label><p class="description"><?php echo esc_html__( 'Disabled by default. Up to 100 redacted events are kept in a non-autoloaded WordPress option. Merchant IDs, invoice tokens, Authority values, card numbers, names, email addresses, and phone numbers are never stored by this logger.', 'easy-invoice-for-zarinpal' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Data on uninstall', 'easy-invoice-for-zarinpal' ); ?></th>
							<td><input type="hidden" name="<?php echo esc_attr( EZINV_Settings::OPT_DELETE_ON_UNINSTALL ); ?>" value="0"><label><input type="checkbox" name="<?php echo esc_attr( EZINV_Settings::OPT_DELETE_ON_UNINSTALL ); ?>" value="1" <?php checked( (bool) get_option( EZINV_Settings::OPT_DELETE_ON_UNINSTALL, false ) ); ?>> <?php echo esc_html__( 'Delete plugin settings and invoice database table when the plugin is deleted', 'easy-invoice-for-zarinpal' ); ?></label><p class="description"><?php echo esc_html__( 'Leave this disabled if invoice records should survive plugin removal.', 'easy-invoice-for-zarinpal' ); ?></p></td>
						</tr>
					</table>
					<?php submit_button( esc_html__( 'Save settings', 'easy-invoice-for-zarinpal' ) ); ?>
				</form>
			</div>


			<?php $log_entries = EZINV_Logger::get_entries(); ?>
			<div class="ezinv-card ezinv-settings-card">
				<h2><?php echo esc_html__( 'Debug log', 'easy-invoice-for-zarinpal' ); ?></h2>
				<p><?php echo esc_html__( 'Only redacted operational events are stored. The newest 100 entries are retained.', 'easy-invoice-for-zarinpal' ); ?></p>
				<?php if ( empty( $log_entries ) ) : ?>
					<p><em><?php echo esc_html__( 'No debug events are stored.', 'easy-invoice-for-zarinpal' ); ?></em></p>
				<?php else : ?>
					<div class="ezinv-table-scroll">
						<table class="widefat striped">
							<thead><tr><th><?php echo esc_html__( 'Time', 'easy-invoice-for-zarinpal' ); ?></th><th><?php echo esc_html__( 'Level', 'easy-invoice-for-zarinpal' ); ?></th><th><?php echo esc_html__( 'Event', 'easy-invoice-for-zarinpal' ); ?></th><th><?php echo esc_html__( 'Context', 'easy-invoice-for-zarinpal' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( array_reverse( $log_entries ) as $entry ) : ?>
								<?php
								$time    = isset( $entry['time'] ) ? sanitize_text_field( (string) $entry['time'] ) : '';
								$level   = isset( $entry['level'] ) ? sanitize_text_field( (string) $entry['level'] ) : '';
								$message = isset( $entry['message'] ) ? sanitize_text_field( (string) $entry['message'] ) : '';
								$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? wp_json_encode( $entry['context'] ) : '';
								?>
								<tr><td><?php echo esc_html( $time ); ?></td><td><?php echo esc_html( $level ); ?></td><td><?php echo esc_html( $message ); ?></td><td><code><?php echo esc_html( (string) $context ); ?></code></td></tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ezinv_clear_logs">
					<?php wp_nonce_field( 'ezinv_clear_logs', 'ezinv_clear_logs_nonce' ); ?>
					<?php submit_button( esc_html__( 'Clear debug log', 'easy-invoice-for-zarinpal' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<div class="ezinv-card ezinv-service-note">
				<h2><?php echo esc_html__( 'External payment service', 'easy-invoice-for-zarinpal' ); ?></h2>
				<p><?php echo esc_html__( 'Payments use ZarinPal. When a payer starts a payment, invoice amount, description, invoice ID, and optional email/mobile fields are sent to ZarinPal. No telemetry is sent to the plugin author.', 'easy-invoice-for-zarinpal' ); ?></p>
				<p><a href="https://www.zarinpal.com/" target="_blank" rel="noopener noreferrer">ZarinPal</a> · <a href="https://www.zarinpal.com/terms" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Terms', 'easy-invoice-for-zarinpal' ); ?></a> · <a href="https://www.zarinpal.com/policy" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Privacy policy', 'easy-invoice-for-zarinpal' ); ?></a></p>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX product search.
	 *
	 * @return void
	 */
	public static function ajax_search_products() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You are not allowed to search products.', 'easy-invoice-for-zarinpal' ) ), 403 );
		}

		check_ajax_referer( 'ezinv_product_search', 'nonce' );

		if ( ! self::woocommerce_available() ) {
			wp_send_json_error( array( 'message' => esc_html__( 'WooCommerce is not active.', 'easy-invoice-for-zarinpal' ) ), 400 );
		}

		$term_raw = EZINV_Request::post_text( 'term' );
		$term     = sanitize_text_field( (string) $term_raw );
		$term = self::limit_text( $term, 100 );
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array() );
		}

		$multiplier = self::woocommerce_to_irr_multiplier();
		if ( $multiplier <= 0 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'WooCommerce currency must be IRR, IRT/TMN, or provide a conversion filter.', 'easy-invoice-for-zarinpal' ) ), 400 );
		}

		$query = new WP_Query(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'publish',
				's'              => $term,
				'posts_per_page' => 15,
				'fields'         => 'ids',
				'orderby'        => 'relevance',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		global $wpdb;
		$sku_cache_key = 'ezinv_sku_' . hash( 'sha256', strtolower( $term ) );
		$sku_found     = false;
		$sku_ids       = wp_cache_get( $sku_cache_key, EZINV_DB::CACHE_GROUP, false, $sku_found );
		if ( ! $sku_found || ! is_array( $sku_ids ) ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Partial SKU lookup has no equivalent WooCommerce API; the query is prepared and cached immediately below.
			$sku_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value LIKE %s LIMIT %d',
					$wpdb->postmeta,
					'_sku',
					$like,
					15
				)
			);
			$sku_ids = array_map( 'absint', (array) $sku_ids );
			wp_cache_set( $sku_cache_key, $sku_ids, EZINV_DB::CACHE_GROUP, 300 );
		}

		$ids = array_values( array_unique( array_merge( array_map( 'absint', $query->posts ), $sku_ids ) ) );
		$ids = array_slice( $ids, 0, 20 );

		$results = array();
		$seen    = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! $product->exists() ) {
				continue;
			}

			$candidates = array();
			if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_children' ) ) {
				foreach ( array_slice( $product->get_children(), 0, 20 ) as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation && $variation->exists() ) {
						$candidates[] = $variation;
					}
				}
			} else {
				$candidates[] = $product;
			}

			foreach ( $candidates as $candidate ) {
				$product_id = (int) $candidate->get_id();
				if ( isset( $seen[ $product_id ] ) || 'publish' !== $candidate->get_status() ) {
					continue;
				}

				$price_irr = self::woocommerce_price_to_irr( $candidate->get_price() );
				if ( is_wp_error( $price_irr ) ) {
					continue;
				}

				$seen[ $product_id ] = true;
				$results[] = array(
					'id'          => $product_id,
					'name'        => self::product_display_name( $candidate ),
					'sku'         => sanitize_text_field( (string) $candidate->get_sku() ),
					'price_toman' => (int) round( $price_irr / 10 ),
				);

				if ( count( $results ) >= 20 ) {
					break 2;
				}
			}
		}

		wp_send_json_success( $results );
	}

	/**
	 * Create invoice admin action.
	 *
	 * @return void
	 */
	public static function create_invoice() {
		self::require_admin_post( 'ezinv_create_invoice', 'ezinv_create_nonce' );

		$title_raw       = EZINV_Request::post_text( 'title' );
		$name_raw        = EZINV_Request::post_text( 'customer_name' );
		$mobile_input    = EZINV_Request::post_text( 'mobile' );
		$email_input     = EZINV_Request::post_email( 'email' );
		$description_raw = EZINV_Request::post_textarea( 'description' );
		$shipping_raw    = EZINV_Request::post_int( 'include_shipping' );

		$title            = sanitize_text_field( (string) $title_raw );
		$customer_name    = sanitize_text_field( (string) $name_raw );
		$mobile_raw       = sanitize_text_field( (string) $mobile_input );
		$email_raw        = sanitize_text_field( (string) $email_input );
		$description      = sanitize_textarea_field( (string) $description_raw );
		$include_shipping = '1' === sanitize_text_field( (string) $shipping_raw );

		$title         = self::limit_text( $title, 255 );
		$customer_name = self::limit_text( $customer_name, 190 );
		$description   = self::limit_text( $description, 2000 );

		if ( '' === $title ) {
			self::redirect_with_notice( __( 'Invoice title is required.', 'easy-invoice-for-zarinpal' ), 'error' );
		}

		$mobile = self::sanitize_mobile( $mobile_raw );
		if ( '' !== $mobile_raw && '' === $mobile ) {
			self::redirect_with_notice( __( 'The mobile number format is invalid.', 'easy-invoice-for-zarinpal' ), 'error' );
		}

		$email = '';
		if ( '' !== $email_raw ) {
			$email = sanitize_email( $email_raw );
			if ( '' === $email || ! is_email( $email ) ) {
				self::redirect_with_notice( __( 'The email address is invalid.', 'easy-invoice-for-zarinpal' ), 'error' );
			}
		}

		$product_ids_raw = EZINV_Request::post_ids( 'product_ids' );
		$quantities_raw  = EZINV_Request::post_ids( 'quantities' );
		$product_quantities = array();

		foreach ( $product_ids_raw as $index => $raw_product_id ) {
			$product_id = is_scalar( $raw_product_id ) ? absint( $raw_product_id ) : 0;
			$raw_quantity = isset( $quantities_raw[ $index ] ) && is_scalar( $quantities_raw[ $index ] ) ? $quantities_raw[ $index ] : 1;
			$quantity   = absint( $raw_quantity );
			$quantity   = max( 1, min( 999, $quantity ) );
			if ( ! $product_id ) {
				continue;
			}
			if ( ! isset( $product_quantities[ $product_id ] ) ) {
				$product_quantities[ $product_id ] = 0;
			}
			$product_quantities[ $product_id ] = min( 999, $product_quantities[ $product_id ] + $quantity );
		}

		$items        = array();
		$subtotal_irr = 0;

		if ( ! empty( $product_quantities ) ) {
			if ( ! self::woocommerce_available() ) {
				self::redirect_with_notice( __( 'WooCommerce products were submitted, but WooCommerce is not active.', 'easy-invoice-for-zarinpal' ), 'error' );
			}
			if ( self::woocommerce_to_irr_multiplier() <= 0 ) {
				self::redirect_with_notice( __( 'The WooCommerce currency cannot be converted safely to Iranian Rial.', 'easy-invoice-for-zarinpal' ), 'error' );
			}

			foreach ( $product_quantities as $product_id => $quantity ) {
				$product = wc_get_product( $product_id );
				if ( ! $product || ! $product->exists() || 'publish' !== $product->get_status() ) {
					self::redirect_with_notice( __( 'One of the selected WooCommerce products is no longer available.', 'easy-invoice-for-zarinpal' ), 'error' );
				}

				$unit_irr = self::woocommerce_price_to_irr( $product->get_price() );
				if ( is_wp_error( $unit_irr ) ) {
					self::redirect_with_notice( $unit_irr->get_error_message(), 'error' );
				}

				if ( $unit_irr > intdiv( PHP_INT_MAX, max( 1, $quantity ) ) ) {
					self::redirect_with_notice( __( 'The selected product total is too large.', 'easy-invoice-for-zarinpal' ), 'error' );
				}
				$line_total = $unit_irr * $quantity;
				if ( $subtotal_irr > PHP_INT_MAX - $line_total ) {
					self::redirect_with_notice( __( 'The invoice total is too large.', 'easy-invoice-for-zarinpal' ), 'error' );
				}
				$subtotal_irr += $line_total;

				$items[] = array(
					'product_id'     => (int) $product->get_id(),
					'name'           => self::product_display_name( $product ),
					'sku'            => sanitize_text_field( (string) $product->get_sku() ),
					'quantity'       => (int) $quantity,
					'unit_price_irr' => (int) $unit_irr,
					'line_total_irr' => (int) $line_total,
				);
			}
		} else {
			$manual_toman = self::parse_toman_amount( EZINV_Request::post_text( 'amount_toman' ) );
			if ( is_wp_error( $manual_toman ) ) {
				self::redirect_with_notice( $manual_toman->get_error_message(), 'error' );
			}
			$subtotal_irr = $manual_toman * 10;
		}

		$shipping_toman = $include_shipping ? max( 0, (int) get_option( EZINV_Settings::OPT_FIXED_SHIPPING, 0 ) ) : 0;
		if ( $shipping_toman > intdiv( PHP_INT_MAX, 10 ) ) {
			self::redirect_with_notice( __( 'The configured shipping amount is too large.', 'easy-invoice-for-zarinpal' ), 'error' );
		}
		$shipping_irr = $shipping_toman * 10;

		if ( $subtotal_irr < 1 || $subtotal_irr > PHP_INT_MAX - $shipping_irr ) {
			self::redirect_with_notice( __( 'The final invoice amount is invalid.', 'easy-invoice-for-zarinpal' ), 'error' );
		}
		$amount_irr = $subtotal_irr + $shipping_irr;

		$token = self::generate_token();
		$now   = current_time( 'mysql' );
		$result = EZINV_DB::insert_invoice(
			array(
				'token'         => $token,
				'title'         => $title,
				'customer_name' => $customer_name,
				'mobile'        => $mobile,
				'email'         => $email,
				'items_json'    => ! empty( $items ) ? wp_json_encode( $items ) : null,
				'subtotal_irr'  => (int) $subtotal_irr,
				'shipping_irr'  => (int) $shipping_irr,
				'amount_irr'    => (int) $amount_irr,
				'description'   => $description,
				'status'        => 'pending',
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);

		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( $result->get_error_message(), 'error' );
		}

		self::redirect_with_notice( __( 'Invoice created successfully.', 'easy-invoice-for-zarinpal' ), 'success' );
	}

	/**
	 * Delete invoice action.
	 *
	 * @return void
	 */
	public static function delete_invoice() {
		self::require_admin_post_for_invoice( 'ezinv_delete' );
		$id_raw = EZINV_Request::post_int( 'invoice_id' );
		$id     = absint( $id_raw );
		if ( ! $id || ! EZINV_DB::get_by_id( $id ) ) {
			self::redirect_with_notice( __( 'Invoice not found.', 'easy-invoice-for-zarinpal' ), 'error' );
		}
		if ( ! EZINV_DB::delete( $id ) ) {
			self::redirect_with_notice( __( 'The invoice could not be deleted.', 'easy-invoice-for-zarinpal' ), 'error' );
		}
		self::redirect_with_notice( __( 'Invoice deleted.', 'easy-invoice-for-zarinpal' ), 'success' );
	}

	/**
	 * Inquiry action. Never marks an invoice paid.
	 *
	 * @return void
	 */
	public static function inquiry_invoice() {
		self::require_admin_post_for_invoice( 'ezinv_inquiry' );
		$id_raw  = EZINV_Request::post_int( 'invoice_id' );
		$id      = absint( $id_raw );
		$invoice = EZINV_DB::get_by_id( $id );
		if ( ! $invoice || ! EZINV_Invoice::valid_authority( $invoice->authority ) ) {
			self::redirect_with_notice( __( 'This invoice has no valid payment Authority to query.', 'easy-invoice-for-zarinpal' ), 'error' );
		}

		$result = EZINV_Gateway::inquiry( $invoice );
		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( $result->get_error_message(), 'error' );
		}

		$status         = isset( $result['data']['status'] ) ? strtoupper( sanitize_key( (string) $result['data']['status'] ) ) : '';
		$allowed_status = array( 'VERIFIED', 'PAID', 'IN_BANK', 'FAILED', 'REVERSED' );
		if ( ! in_array( $status, $allowed_status, true ) ) {
			self::redirect_with_notice( EZINV_Gateway::error_message( $result, __( 'The gateway inquiry returned an invalid response.', 'easy-invoice-for-zarinpal' ) ), 'error' );
		}

		$updated = EZINV_DB::update(
			$id,
			array(
				'gateway_status' => $status,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s' )
		);
		if ( ! $updated ) {
			self::redirect_with_notice( __( 'The gateway status could not be saved.', 'easy-invoice-for-zarinpal' ), 'error' );
		}
		self::redirect_with_notice( /* translators: %s: gateway status. */
					sprintf( __( 'Gateway inquiry status: %s. Inquiry does not verify or mark a transaction paid.', 'easy-invoice-for-zarinpal' ), $status ), 'success' );
	}

	/**
	 * Authorized admin re-verification for recovery after callback/API errors.
	 *
	 * @return void
	 */
	public static function reverify_invoice() {
		self::require_admin_post_for_invoice( 'ezinv_reverify' );
		$id_raw  = EZINV_Request::post_int( 'invoice_id' );
		$id      = absint( $id_raw );
		$invoice = EZINV_DB::get_by_id( $id );
		if ( ! $invoice || ! EZINV_Invoice::valid_authority( $invoice->authority ) ) {
			self::redirect_with_notice( __( 'This invoice cannot be re-verified because its payment Authority is missing.', 'easy-invoice-for-zarinpal' ), 'error' );
		}

		$result = EZINV_Invoice::verify_invoice( $invoice );
		if ( true === $result ) {
			self::redirect_with_notice( __( 'Payment verified successfully.', 'easy-invoice-for-zarinpal' ), 'success' );
		}
		self::redirect_with_notice( is_wp_error( $result ) ? $result->get_error_message() : __( 'Payment verification failed.', 'easy-invoice-for-zarinpal' ), 'error' );
	}


	/**
	 * Clear the redacted debug log.
	 *
	 * @return void
	 */
	public static function clear_logs() {
		self::require_admin_post( 'ezinv_clear_logs', 'ezinv_clear_logs_nonce' );
		EZINV_Logger::clear();
		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'message' => __( 'Debug log cleared.', 'easy-invoice-for-zarinpal' ),
				'type'    => 'success',
			),
			60
		);
		wp_safe_redirect( admin_url( 'admin.php?page=ezinv-settings' ) );
		exit;
	}

	/**
	 * Require admin capability, POST, and a named nonce.
	 *
	 * @param string $action     Nonce action.
	 * @param string $nonce_name Nonce field name.
	 * @return void
	 */
	private static function require_admin_post( $action, $nonce_name ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 403 ) );
		}
		if ( 'POST' !== EZINV_Request::method() ) {
			wp_die( esc_html__( 'Invalid request method.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 405 ) );
		}

		check_admin_referer( $action, $nonce_name );
	}

	/**
	 * Require an invoice-specific admin nonce.
	 *
	 * @param string $prefix Nonce action prefix.
	 * @return void
	 */
	private static function require_admin_post_for_invoice( $prefix ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 403 ) );
		}
		if ( 'POST' !== EZINV_Request::method() ) {
			wp_die( esc_html__( 'Invalid request method.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 405 ) );
		}

		$id = EZINV_Request::post_int( 'invoice_id' );
		if ( ! $id ) {
			wp_die( esc_html__( 'Invalid invoice ID.', 'easy-invoice-for-zarinpal' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( $prefix . '_' . $id );
	}

	/**
	 * Print a compact POST action form.
	 *
	 * @param string $action Admin-post action.
	 * @param int    $id     Invoice ID.
	 * @param string $nonce_action Nonce action.
	 * @param string $label Button label.
	 * @param string $class Optional form class.
	 * @return void
	 */
	private static function action_form( $action, $id, $nonce_action, $label, $class = '' ) {
		?>
		<form class="ezinv-inline-form <?php echo esc_attr( $class ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="invoice_id" value="<?php echo esc_attr( (string) absint( $id ) ); ?>">
			<?php wp_nonce_field( $nonce_action ); ?>
			<button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Set a user-scoped transient notice and redirect back to dashboard.
	 *
	 * @param string $message Message.
	 * @param string $type    success|error|warning.
	 * @return void
	 */
	private static function redirect_with_notice( $message, $type ) {
		$user_id = get_current_user_id();
		$type    = in_array( $type, array( 'success', 'error', 'warning' ), true ) ? $type : 'success';
		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . $user_id,
			array(
				'message' => sanitize_text_field( (string) $message ),
				'type'    => $type,
			),
			60
		);
		wp_safe_redirect( admin_url( 'admin.php?page=ezinv-invoices' ) );
		exit;
	}

	/**
	 * Show current user's admin notice.
	 *
	 * @return void
	 */
	private static function render_notice() {
		$key    = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$type = isset( $notice['type'] ) && in_array( $notice['type'], array( 'success', 'error', 'warning' ), true ) ? $notice['type'] : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}

	/**
	 * Generate cryptographically strong public invoice token.
	 *
	 * @return string
	 */
	private static function generate_token() {
		try {
			return bin2hex( random_bytes( 24 ) );
		} catch ( Exception $exception ) {
			EZINV_Logger::log( 'warning', 'random_bytes fallback used.', array( 'operation' => 'token_generation', 'error_code' => get_class( $exception ) ) );
			return wp_generate_password( 48, false, false );
		}
	}

	/**
	 * Validate manual Toman amount.
	 *
	 * @param mixed $raw Raw amount.
	 * @return int|WP_Error
	 */
	private static function parse_toman_amount( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return new WP_Error( 'ezinv_invalid_amount', esc_html__( 'Enter a valid manual amount.', 'easy-invoice-for-zarinpal' ) );
		}
		$value = sanitize_text_field( (string) $raw );
		$value = str_replace( array( ',', ' ' ), '', $value );
		if ( '' === $value || ! ctype_digit( $value ) ) {
			return new WP_Error( 'ezinv_invalid_amount', esc_html__( 'Enter a positive whole-number amount in Toman.', 'easy-invoice-for-zarinpal' ) );
		}

		$max_toman = intdiv( PHP_INT_MAX, 10 );
		if ( (float) $value < 1 || (float) $value > $max_toman ) {
			return new WP_Error( 'ezinv_amount_range', esc_html__( 'The manual amount is outside the supported range.', 'easy-invoice-for-zarinpal' ) );
		}
		return (int) $value;
	}

	/**
	 * Normalize optional mobile number.
	 *
	 * @param string $mobile Raw mobile.
	 * @return string
	 */
	private static function sanitize_mobile( $mobile ) {
		$mobile = preg_replace( '/[^0-9+]/', '', (string) $mobile );
		if ( ! is_string( $mobile ) || '' === $mobile ) {
			return '';
		}
		if ( ! preg_match( '/^\+?[0-9]{7,15}$/', $mobile ) ) {
			return '';
		}
		return $mobile;
	}

	/**
	 * WooCommerce availability.
	 *
	 * @return bool
	 */
	private static function woocommerce_available() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Convert WooCommerce currency to IRR multiplier.
	 *
	 * @return float
	 */
	private static function woocommerce_to_irr_multiplier() {
		if ( ! self::woocommerce_available() || ! function_exists( 'get_woocommerce_currency' ) ) {
			return 0;
		}
		$currency = strtoupper( (string) get_woocommerce_currency() );
		if ( 'IRR' === $currency ) {
			return 1;
		}
		if ( in_array( $currency, array( 'IRT', 'TMN', 'TOMAN', 'IRTOMAN' ), true ) ) {
			return 10;
		}
		return (float) apply_filters( 'ezinv_woocommerce_price_to_irr_multiplier', 0, $currency );
	}

	/**
	 * Convert WooCommerce price to integer IRR.
	 *
	 * @param mixed $price Product price.
	 * @return int|WP_Error
	 */
	private static function woocommerce_price_to_irr( $price ) {
		$multiplier = self::woocommerce_to_irr_multiplier();
		if ( $multiplier <= 0 || ! is_numeric( $price ) ) {
			return new WP_Error( 'ezinv_invalid_product_price', esc_html__( 'A selected product has no valid price.', 'easy-invoice-for-zarinpal' ) );
		}

		$value = (float) $price * $multiplier;
		if ( ! is_finite( $value ) || $value < 0 || $value > PHP_INT_MAX ) {
			return new WP_Error( 'ezinv_product_price_range', esc_html__( 'A selected product price is outside the supported range.', 'easy-invoice-for-zarinpal' ) );
		}
		return (int) round( $value );
	}

	/**
	 * Product name including variation attributes.
	 *
	 * @param object $product WooCommerce product.
	 * @return string
	 */
	private static function product_display_name( $product ) {
		$name = wp_strip_all_tags( (string) $product->get_name() );
		if ( $product->is_type( 'variation' ) && function_exists( 'wc_get_formatted_variation' ) ) {
			$variation = trim( wp_strip_all_tags( wc_get_formatted_variation( $product, true, true, true ) ) );
			if ( '' !== $variation ) {
				$name .= ' — ' . $variation;
			}
		}
		return self::limit_text( $name, 255 );
	}

	/**
	 * Limit text without assuming mbstring is installed.
	 *
	 * @param string $text Text.
	 * @param int    $length Max characters/bytes fallback.
	 * @return string
	 */
	private static function limit_text( $text, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( (string) $text, 0, absint( $length ) );
		}
		return substr( (string) $text, 0, absint( $length ) );
	}

	/**
	 * Check merchant setting shape.
	 *
	 * @return bool
	 */
	private static function merchant_configured() {
		$merchant_id = trim( (string) get_option( EZINV_Settings::OPT_MERCHANT_ID, '' ) );
		return 36 === strlen( $merchant_id ) && (bool) preg_match( '/^[A-Za-z0-9-]{36}$/', $merchant_id );
	}

	/**
	 * Count invoice item quantity for admin list.
	 *
	 * @param object $invoice Invoice.
	 * @return string
	 */
	private static function items_count_text( $invoice ) {
		$items = EZINV_Invoice::decode_items( $invoice->items_json );
		if ( empty( $items ) ) {
			return '<br><small>' . esc_html__( 'Manual amount', 'easy-invoice-for-zarinpal' ) . '</small>';
		}
		$count = 0;
		foreach ( $items as $item ) {
			$count += max( 1, absint( isset( $item['quantity'] ) ? $item['quantity'] : 1 ) );
		}
		return '<br><small>' . esc_html( sprintf( /* translators: %s: number of items. */
					_n( '%s item', '%s items', $count, 'easy-invoice-for-zarinpal' ), number_format_i18n( $count ) ) ) . '</small>';
	}

	/**
	 * Status badge HTML.
	 *
	 * @param object $invoice Invoice.
	 * @return string
	 */
	private static function status_badge( $invoice ) {
		$labels = array(
			'pending'         => __( 'Pending', 'easy-invoice-for-zarinpal' ),
			'waiting_payment' => __( 'Awaiting payment', 'easy-invoice-for-zarinpal' ),
			'verifying'       => __( 'Verifying', 'easy-invoice-for-zarinpal' ),
			'paid'            => __( 'Paid', 'easy-invoice-for-zarinpal' ),
			'cancelled'       => __( 'Cancelled', 'easy-invoice-for-zarinpal' ),
			'failed'          => __( 'Failed', 'easy-invoice-for-zarinpal' ),
		);
		$status = sanitize_key( (string) $invoice->status );
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		return '<span class="ezinv-admin-status ezinv-admin-status-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( $label ) . '</span>';
	}
}
