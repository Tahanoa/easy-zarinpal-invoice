<?php
/**
 * Uninstall handler.
 *
 * @package Easy_Invoice_For_Zarinpal
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! (bool) get_option( 'ezinv_delete_on_uninstall', false ) ) {
	return;
}

global $wpdb;

$ezinv_tables = array(
	$wpdb->prefix . 'easy_zarinpal_invoices',
);

foreach ( $ezinv_tables as $ezinv_table ) {
	$ezinv_drop_query = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $ezinv_table );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Uninstall cleanup of this plugin's own table; identifier is prepared immediately above.
	$wpdb->query( $ezinv_drop_query );
}

$ezinv_options = array(
	'ezinv_db_version',
	'ezinv_cache_generation',
	'ezinv_zarinpal_merchant_id',
	'ezinv_business_name',
	'ezinv_invoice_page_id',
	'ezinv_fixed_shipping_toman',
	'ezinv_enable_logging',
	'ezinv_debug_log_entries',
	'ezinv_delete_on_uninstall',
	'ezi_db_version',
	'ezi_zarinpal_merchant_id',
	'ezi_business_name',
	'ezi_invoice_page',
	'ezi_invoice_page_id',
	'ezi_fixed_shipping_toman',
	'ezi_enable_logging',
	'ezi_delete_on_uninstall',
);

foreach ( $ezinv_options as $ezinv_option ) {
	delete_option( $ezinv_option );
}

$ezinv_lock_like    = $wpdb->esc_like( 'ezinv_verify_lock_' ) . '%';
$ezinv_delete_query = $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $ezinv_lock_like );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Removes only this plugin's temporary verification-lock options during uninstall; query is prepared immediately above.
$wpdb->query( $ezinv_delete_query );
