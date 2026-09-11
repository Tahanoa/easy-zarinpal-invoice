<?php
/**
 * Plugin Name: Easy Invoice for ZarinPal
 * Description: Create secure payment invoices with custom amounts or WooCommerce products and accept payments through ZarinPal.
 * Version: 1.5.8
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: Taha Farzaneh
 * Author URI: https://profiles.wordpress.org/tahanoa/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: easy-invoice-for-zarinpal
 * Domain Path: /languages
 *
 * @package Easy_Invoice_For_Zarinpal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EZINV_VERSION', '1.5.8' );
define( 'EZINV_DB_VERSION', '1.5.0' );
define( 'EZINV_PLUGIN_FILE', __FILE__ );
define( 'EZINV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EZINV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EZINV_PLUGIN_DIR . 'includes/class-ezinv-request.php';
require_once EZINV_PLUGIN_DIR . 'includes/class-ezinv-logger.php';
require_once EZINV_PLUGIN_DIR . 'includes/class-ezinv-settings.php';
require_once EZINV_PLUGIN_DIR . 'includes/class-ezinv-db.php';
require_once EZINV_PLUGIN_DIR . 'includes/class-ezinv-gateway.php';
require_once EZINV_PLUGIN_DIR . 'includes/class-ezinv-invoice.php';
require_once EZINV_PLUGIN_DIR . 'includes/class-ezinv-admin.php';
require_once EZINV_PLUGIN_DIR . 'includes/class-ezinv-plugin.php';

register_activation_hook( EZINV_PLUGIN_FILE, array( 'EZINV_DB', 'activate' ) );

EZINV_Plugin::init();
