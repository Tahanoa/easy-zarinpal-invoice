<?php
/**
 * Database layer.
 *
 * @package Easy_Invoice_For_Zarinpal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EZINV_DB {
	const TABLE_SUFFIX         = 'easy_zarinpal_invoices';
	const OPT_DB_VERSION       = 'ezinv_db_version';
	const OPT_CACHE_GENERATION = 'ezinv_cache_generation';
	const CACHE_GROUP          = 'easy_invoice_for_zarinpal';

	/**
	 * Get current table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_schema();
		self::migrate_legacy_options();
		update_option( self::OPT_DB_VERSION, EZINV_DB_VERSION, false );
		if ( ! get_option( self::OPT_CACHE_GENERATION, 0 ) ) {
			add_option( self::OPT_CACHE_GENERATION, 1, '', false );
		}
	}

	/**
	 * Upgrade schema when needed.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( EZINV_DB_VERSION !== (string) get_option( self::OPT_DB_VERSION, '' ) ) {
			self::activate();
		}
	}

	/**
	 * Install/update database schema.
	 *
	 * @return void
	 */
	private static function install_schema() {
		global $wpdb;

		$table             = self::table();
		$charset_collate   = $wpdb->get_charset_collate();
		$max_index_length  = 191;
		$token_index_field = min( 64, $max_index_length );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token varchar(64) NOT NULL,
			title varchar(255) NOT NULL,
			customer_name varchar(190) NOT NULL DEFAULT '',
			mobile varchar(30) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			items_json longtext NULL,
			subtotal_irr bigint(20) unsigned NOT NULL DEFAULT 0,
			shipping_irr bigint(20) unsigned NOT NULL DEFAULT 0,
			amount_irr bigint(20) unsigned NOT NULL DEFAULT 0,
			description text NULL,
			status varchar(30) NOT NULL DEFAULT 'pending',
			authority varchar(100) NOT NULL DEFAULT '',
			ref_id varchar(100) NOT NULL DEFAULT '',
			card_pan varchar(100) NOT NULL DEFAULT '',
			gateway_status varchar(30) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			paid_at datetime NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token({$token_index_field})),
			KEY status (status),
			KEY authority (authority)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Migrate options from prior versions.
	 *
	 * @return void
	 */
	private static function migrate_legacy_options() {
		self::copy_option_if_empty( 'ezi_zarinpal_merchant_id', EZINV_Settings::OPT_MERCHANT_ID );
		self::copy_option_if_empty( 'ezi_business_name', EZINV_Settings::OPT_BUSINESS_NAME );
		self::copy_option_if_empty( 'ezi_fixed_shipping_toman', EZINV_Settings::OPT_FIXED_SHIPPING );

		if ( ! get_option( EZINV_Settings::OPT_INVOICE_PAGE_ID, 0 ) ) {
			$legacy_url = (string) get_option( EZINV_Settings::OPT_LEGACY_INVOICE_PAGE, '' );
			if ( '' !== $legacy_url ) {
				$page_id = url_to_postid( $legacy_url );
				if ( $page_id ) {
					update_option( EZINV_Settings::OPT_INVOICE_PAGE_ID, $page_id, false );
				}
			}
		}
	}

	/**
	 * Copy an old option only when the new option has no value.
	 *
	 * @param string $old_key Old key.
	 * @param string $new_key New key.
	 * @return void
	 */
	private static function copy_option_if_empty( $old_key, $new_key ) {
		$current = get_option( $new_key, null );
		if ( null !== $current && '' !== $current ) {
			return;
		}
		$legacy = get_option( $old_key, null );
		if ( null !== $legacy && '' !== $legacy ) {
			update_option( $new_key, $legacy, false );
		}
	}

	/**
	 * Insert an invoice.
	 *
	 * The plugin owns a custom invoice table, so there is no WordPress core CRUD
	 * API that can replace this write operation.
	 *
	 * @param array $data Invoice data.
	 * @return int|WP_Error
	 */
	public static function insert_invoice( array $data ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin owns this custom table; values and formats are passed separately to wpdb::insert().
		$inserted = $wpdb->insert(
			self::table(),
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'ezinv_db_insert_failed', esc_html__( 'The invoice could not be saved.', 'easy-invoice-for-zarinpal' ) );
		}

		self::bump_cache_generation();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get recent invoices.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public static function get_recent_invoices( $limit = 200 ) {
		global $wpdb;

		$limit     = max( 1, min( 500, absint( $limit ) ) );
		$cache_key = self::cache_key( 'recent_' . $limit );
		$found     = false;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );
		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table query is prepared and its result is cached immediately below.
		$results = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), $limit ) );
		wp_cache_set( $cache_key, $results, self::CACHE_GROUP, 300 );
		return $results;
	}

	/**
	 * Find invoice by ID.
	 *
	 * @param int $id Invoice ID.
	 * @return object|null
	 */
	public static function get_by_id( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}

		$cache_key = self::cache_key( 'id_' . $id );
		$found     = false;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );
		if ( $found ) {
			return is_object( $cached ) ? $cached : null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table query is prepared and its result is cached immediately below.
		$invoice = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', self::table(), $id ) );
		wp_cache_set( $cache_key, $invoice ? $invoice : 0, self::CACHE_GROUP, 300 );
		return $invoice ? $invoice : null;
	}

	/**
	 * Find invoice by public token.
	 *
	 * @param string $token Public token.
	 * @return object|null
	 */
	public static function get_by_token( $token ) {
		global $wpdb;

		$token = sanitize_text_field( (string) $token );
		if ( '' === $token ) {
			return null;
		}

		$cache_key = self::cache_key( 'token_' . hash( 'sha256', $token ) );
		$found     = false;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );
		if ( $found ) {
			return is_object( $cached ) ? $cached : null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table query is prepared and its result is cached immediately below.
		$invoice = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token = %s LIMIT 1', self::table(), $token ) );
		wp_cache_set( $cache_key, $invoice ? $invoice : 0, self::CACHE_GROUP, 300 );
		return $invoice ? $invoice : null;
	}

	/**
	 * Update an invoice.
	 *
	 * @param int   $id      Invoice ID.
	 * @param array $data    Data.
	 * @param array $formats Formats.
	 * @return bool
	 */
	public static function update( $id, array $data, array $formats ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin owns this custom table and invalidates its cache generation immediately after a successful write.
		$result = $wpdb->update( self::table(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false !== $result ) {
			self::bump_cache_generation();
			return true;
		}
		return false;
	}

	/**
	 * Delete an invoice.
	 *
	 * @param int $id Invoice ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin owns this custom table and invalidates its cache generation immediately after a successful write.
		$result = $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
		if ( false !== $result ) {
			self::bump_cache_generation();
			return true;
		}
		return false;
	}

	/**
	 * Get invoice records matching a customer email for privacy export.
	 *
	 * @param string $email    Customer email.
	 * @param int    $page     Page number.
	 * @param int    $per_page Rows per page.
	 * @return array
	 */
	public static function get_by_customer_email( $email, $page = 1, $per_page = 50 ) {
		global $wpdb;

		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return array();
		}

		$page       = max( 1, absint( $page ) );
		$per_page   = max( 1, min( 100, absint( $per_page ) ) );
		$offset     = ( $page - 1 ) * $per_page;
		$cache_key  = self::cache_key( 'email_' . hash( 'sha256', strtolower( $email ) ) . '_' . $page . '_' . $per_page );
		$found      = false;
		$cached     = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );
		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table privacy query is prepared and its result is cached immediately below.
		$results = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d',
				self::table(),
				$email,
				$per_page,
				$offset
			)
		);
		wp_cache_set( $cache_key, $results, self::CACHE_GROUP, 300 );
		return $results;
	}

	/**
	 * Remove customer contact fields matching an email address.
	 *
	 * @param string $email Customer email.
	 * @return int Number of rows changed, or zero on failure/no matches.
	 */
	public static function anonymize_customer_by_email( $email ) {
		global $wpdb;

		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin owns this custom table and invalidates its cache generation immediately after a successful write.
		$result = $wpdb->update(
			self::table(),
			array(
				'customer_name' => '',
				'mobile'        => '',
				'email'         => '',
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'email' => $email ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
		if ( false === $result ) {
			return 0;
		}
		self::bump_cache_generation();
		return (int) $result;
	}

	/**
	 * Build a cache key that automatically becomes stale after any write.
	 *
	 * @param string $suffix Key suffix.
	 * @return string
	 */
	private static function cache_key( $suffix ) {
		return 'v' . self::cache_generation() . '_' . sanitize_key( $suffix );
	}

	/**
	 * Get the current cache generation.
	 *
	 * @return int
	 */
	private static function cache_generation() {
		return max( 1, absint( get_option( self::OPT_CACHE_GENERATION, 1 ) ) );
	}

	/**
	 * Invalidate all plugin invoice cache keys by changing their generation.
	 *
	 * @return void
	 */
	private static function bump_cache_generation() {
		$current = self::cache_generation();
		$next    = $current >= PHP_INT_MAX - 1 ? 1 : $current + 1;
		update_option( self::OPT_CACHE_GENERATION, $next, false );
	}
}
