<?php
/**
 * Main plugin bootstrap.
 *
 * @package Easy_Invoice_For_Zarinpal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EZINV_Plugin {
	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'load' ) );
	}

	/**
	 * Load the plugin after other plugins are available.
	 *
	 * @return void
	 */
	public static function load() {
		EZINV_DB::maybe_upgrade();
		EZINV_Settings::init();
		EZINV_Admin::init();
		EZINV_Invoice::init();
	}

}
