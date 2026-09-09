<?php
/**
 * Plugin Name: صورتحساب آنلاین زرین‌پال
 * Description: ساخت صورتحساب با مبلغ دلخواه یا محصولات ووکامرس، لینک پرداخت اختصاصی، هزینه حمل ثابت و اتصال به زرین‌پال.
 * Version: 1.4.0
 * Author: طاها فرزانه
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: easy-zarinpal-invoice
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Easy_Zarinpal_Invoice_Plugin {
    const VERSION = '1.4.0';
    const DB_VERSION = '1.4.0';
    const TABLE_SUFFIX = 'easy_zarinpal_invoices';
    const LEGACY_TABLE_SUFFIX = 'barzadeh_invoices';

    const OPT_DB_VERSION = 'ezi_db_version';
    const OPT_MERCHANT_ID = 'ezi_zarinpal_merchant_id';
    const OPT_BUSINESS_NAME = 'ezi_business_name';
    const OPT_INVOICE_PAGE = 'ezi_invoice_page';
    const OPT_FIXED_SHIPPING_TOMAN = 'ezi_fixed_shipping_toman';

    public static function init() {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);

        add_action('plugins_loaded', [__CLASS__, 'maybe_upgrade'], 20);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);

        add_action('admin_post_ezi_create_invoice', [__CLASS__, 'create_invoice']);
        add_action('admin_post_ezi_delete_invoice', [__CLASS__, 'delete_invoice']);
        add_action('admin_post_ezi_inquiry_invoice', [__CLASS__, 'inquiry_invoice']);

        add_action('admin_post_ezi_pay', [__CLASS__, 'start_payment']);
        add_action('admin_post_nopriv_ezi_pay', [__CLASS__, 'start_payment']);
        add_action('admin_post_ezi_verify', [__CLASS__, 'verify_payment']);
        add_action('admin_post_nopriv_ezi_verify', [__CLASS__, 'verify_payment']);

        add_action('wp_ajax_ezi_search_products', [__CLASS__, 'ajax_search_products']);

        // شورت‌کد نسخه 1.3 برای سازگاری حفظ شده و نام عمومی جدید هم اضافه شده است.
        add_shortcode('bzi_invoice', [__CLASS__, 'invoice_shortcode']);
        add_shortcode('ezi_invoice', [__CLASS__, 'invoice_shortcode']);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    private static function legacy_table() {
        global $wpdb;
        return $wpdb->prefix . self::LEGACY_TABLE_SUFFIX;
    }

    public static function activate() {
        self::install_schema();
        self::migrate_legacy_data();
        update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
    }

    public static function maybe_upgrade() {
        if (get_option(self::OPT_DB_VERSION) !== self::DB_VERSION) {
            self::install_schema();
            self::migrate_legacy_data();
            update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
        }
    }

    private static function install_schema() {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();

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
            UNIQUE KEY token (token),
            KEY status (status),
            KEY authority (authority)
        ) {$charset};";
        dbDelta($sql);
    }

    /**
     * مهاجرت خودکار داده‌های نسخه 1.3.0 به ساختار عمومی جدید.
     */
    private static function migrate_legacy_data() {
        global $wpdb;

        $legacy = self::legacy_table();
        $current = self::table();
        $legacy_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy));
        if ($legacy_exists === $legacy) {
            $current_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$current}");
            if ($current_count === 0) {
                $wpdb->query("INSERT IGNORE INTO {$current}
                    (id, token, title, customer_name, mobile, email, items_json, subtotal_irr, shipping_irr, amount_irr, description, status, authority, ref_id, card_pan, gateway_status, created_at, paid_at, updated_at)
                    SELECT id, token, title, customer_name, mobile, email, NULL, amount_irr, 0, amount_irr, description, status, authority, ref_id, card_pan, gateway_status, created_at, paid_at, updated_at
                    FROM {$legacy}");
            }
        }

        self::migrate_option('bzi_zarinpal_merchant_id', self::OPT_MERCHANT_ID);
        self::migrate_option('bzi_business_name', self::OPT_BUSINESS_NAME);
        self::migrate_option('bzi_invoice_page', self::OPT_INVOICE_PAGE);
    }

    private static function migrate_option($old_key, $new_key) {
        $new_value = get_option($new_key, null);
        if ($new_value !== null && $new_value !== '') {
            return;
        }
        $old_value = get_option($old_key, null);
        if ($old_value !== null && $old_value !== '') {
            update_option($new_key, $old_value, false);
        }
    }

    public static function admin_menu() {
        add_menu_page(
            'صورتحساب‌ها',
            'صورتحساب‌ها',
            'manage_options',
            'ezi-invoices',
            [__CLASS__, 'admin_page'],
            'dashicons-media-spreadsheet',
            56
        );
        add_submenu_page(
            'ezi-invoices',
            'تنظیمات',
            'تنظیمات',
            'manage_options',
            'ezi-settings',
            [__CLASS__, 'settings_page']
        );
    }

    public static function register_settings() {
        register_setting('ezi_settings', self::OPT_MERCHANT_ID, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
        register_setting('ezi_settings', self::OPT_INVOICE_PAGE, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);
        register_setting('ezi_settings', self::OPT_BUSINESS_NAME, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => get_bloginfo('name'),
        ]);
        register_setting('ezi_settings', self::OPT_FIXED_SHIPPING_TOMAN, [
            'type' => 'integer',
            'sanitize_callback' => [__CLASS__, 'sanitize_nonnegative_int'],
            'default' => 0,
        ]);
    }

    public static function sanitize_nonnegative_int($value) {
        return max(0, absint($value));
    }

    private static function admin_notice_from_query() {
        if (empty($_GET['ezi_msg'])) {
            return;
        }
        $type = isset($_GET['ezi_type']) && $_GET['ezi_type'] === 'error' ? 'notice-error' : 'notice-success';
        $msg = sanitize_text_field(wp_unslash($_GET['ezi_msg']));
        echo '<div class="notice ' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }

    public static function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $invoices = $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 200');
        $woocommerce_active = self::woocommerce_available();
        $shipping_toman = max(0, absint(get_option(self::OPT_FIXED_SHIPPING_TOMAN, 0)));
        $ajax_nonce = wp_create_nonce('ezi_product_search');

        self::admin_notice_from_query();
        ?>
        <div class="wrap ezi-admin" dir="rtl">
            <style>
                .ezi-admin{max-width:1320px}.ezi-admin *{box-sizing:border-box}.ezi-admin h1{font-weight:900;font-size:28px;margin-bottom:5px}.ezi-subtitle{color:#646970;margin-top:0}
                .ezi-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(280px,.55fr);gap:20px;align-items:start;margin-top:20px}.ezi-card{background:#fff;border:1px solid #e4e7ec;border-radius:18px;padding:24px;box-shadow:0 6px 24px rgba(16,24,40,.05)}
                .ezi-card h2{margin:0 0 18px;font-size:20px}.ezi-field{margin-bottom:18px}.ezi-field label{display:block;font-weight:700;margin-bottom:7px}.ezi-field input[type=text],.ezi-field input[type=email],.ezi-field input[type=number],.ezi-field textarea{width:100%;max-width:none;border:1px solid #d0d5dd;border-radius:10px;padding:9px 12px;min-height:42px}.ezi-help{color:#667085;font-size:12px;margin-top:6px}
                .ezi-products{border:1px solid #e4e7ec;border-radius:14px;padding:16px;background:#fafafa}.ezi-search-wrap{position:relative}.ezi-product-results{display:none;position:absolute;z-index:50;right:0;left:0;top:48px;background:#fff;border:1px solid #d0d5dd;border-radius:12px;box-shadow:0 14px 35px rgba(16,24,40,.15);max-height:300px;overflow:auto}.ezi-result{display:flex;align-items:center;justify-content:space-between;gap:15px;width:100%;border:0;border-bottom:1px solid #f0f1f2;background:#fff;padding:11px 13px;text-align:right;cursor:pointer}.ezi-result:hover{background:#f8fafc}.ezi-result:last-child{border-bottom:0}.ezi-result-name{font-weight:700}.ezi-result-meta{font-size:12px;color:#667085;margin-top:3px}.ezi-result-price{white-space:nowrap;font-weight:800;color:#087a34}
                .ezi-items-table{width:100%;border-collapse:collapse;margin-top:14px}.ezi-items-table th,.ezi-items-table td{border-bottom:1px solid #eaecf0;padding:10px 7px;text-align:right;vertical-align:middle}.ezi-items-table th{font-size:12px;color:#667085}.ezi-qty{width:72px!important;min-height:36px!important}.ezi-remove{border:0;background:#fff0f0;color:#b42318;border-radius:8px;padding:6px 9px;cursor:pointer}.ezi-empty-products{text-align:center;color:#667085;padding:18px 5px}
                .ezi-summary{background:#101828;color:#fff;border-radius:14px;padding:17px;margin-top:16px}.ezi-summary-row{display:flex;justify-content:space-between;gap:15px;margin:7px 0}.ezi-summary-total{font-size:19px;font-weight:900;border-top:1px solid rgba(255,255,255,.18);padding-top:12px;margin-top:12px}.ezi-shipping-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:12px 14px;margin-top:14px}.ezi-warning{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:12px;padding:13px 15px;margin-bottom:15px}
                .ezi-submit{background:#1570ef!important;border-color:#1570ef!important;border-radius:10px!important;padding:5px 20px!important;min-height:42px!important;font-weight:700!important}.ezi-side-stat{padding:16px;border-radius:14px;background:#f8fafc;margin-bottom:12px}.ezi-side-stat strong{display:block;font-size:22px;margin-top:5px}.ezi-code{background:#101828;color:#fff;border-radius:8px;padding:6px 9px;display:inline-block;direction:ltr}
                .ezi-list-card{margin-top:22px}.ezi-table-wrap{overflow:auto}.ezi-table{width:100%;border-collapse:separate;border-spacing:0}.ezi-table th{background:#f8fafc;color:#475467;font-size:12px}.ezi-table th,.ezi-table td{padding:12px 10px;border-bottom:1px solid #eaecf0;white-space:nowrap;text-align:right}.ezi-table tr:last-child td{border-bottom:0}.ezi-actions{display:flex;gap:5px;flex-wrap:wrap}.ezi-actions .button{border-radius:8px}.ezi-delete{color:#b42318!important;border-color:#fda29b!important}
                @media(max-width:900px){.ezi-grid{grid-template-columns:1fr}.ezi-card{padding:18px}}
            </style>

            <h1>مدیریت صورتحساب‌ها</h1>
            <p class="ezi-subtitle">صورتحساب دستی یا مبتنی بر محصولات ووکامرس بسازید و لینک پرداخت را برای خریدار بفرستید.</p>

            <div class="ezi-grid">
                <div class="ezi-card">
                    <h2>ساخت صورتحساب جدید</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ezi-create-form">
                        <input type="hidden" name="action" value="ezi_create_invoice">
                        <?php wp_nonce_field('ezi_create_invoice'); ?>

                        <div class="ezi-field">
                            <label for="ezi-title">عنوان صورتحساب *</label>
                            <input type="text" id="ezi-title" name="title" required placeholder="مثلاً سفارش قطعات / فاکتور خدمات">
                        </div>

                        <div class="ezi-products">
                            <h3 style="margin-top:0">محصولات ووکامرس</h3>
                            <?php if (!$woocommerce_active): ?>
                                <div class="ezi-warning">ووکامرس فعال نیست. همچنان می‌توانید با «مبلغ دستی» صورتحساب ایجاد کنید.</div>
                            <?php else: ?>
                                <div class="ezi-search-wrap">
                                    <input type="text" id="ezi-product-search" autocomplete="off" placeholder="نام محصول یا کد SKU را جستجو کنید...">
                                    <div id="ezi-product-results" class="ezi-product-results"></div>
                                </div>
                                <p class="ezi-help">پس از انتخاب محصول، تعداد را می‌توانید تغییر دهید. قیمت در زمان ساخت صورتحساب از ووکامرس خوانده و در همان صورتحساب ثابت می‌شود.</p>
                            <?php endif; ?>

                            <div class="ezi-table-wrap">
                                <table class="ezi-items-table" id="ezi-items-table">
                                    <thead><tr><th>محصول</th><th>قیمت واحد</th><th>تعداد</th><th>جمع</th><th></th></tr></thead>
                                    <tbody id="ezi-items-body">
                                        <tr class="ezi-empty-row"><td colspan="5" class="ezi-empty-products">هنوز محصولی انتخاب نشده است.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="ezi-field" style="margin-top:18px">
                            <label for="ezi-manual-amount">مبلغ دستی (تومان)</label>
                            <input type="number" min="0" step="1" id="ezi-manual-amount" name="amount_toman" value="0" placeholder="اگر محصول انتخاب نکرده‌اید، مبلغ را وارد کنید">
                            <div class="ezi-help">اگر حداقل یک محصول ووکامرس انتخاب شود، مبلغ دستی نادیده گرفته می‌شود و جمع محصولات مبنای صورتحساب خواهد بود.</div>
                        </div>

                        <div class="ezi-shipping-box">
                            <label style="display:flex;gap:9px;align-items:center;font-weight:700">
                                <input type="checkbox" id="ezi-include-shipping" name="include_shipping" value="1" <?php checked($shipping_toman > 0); ?>>
                                افزودن هزینه حمل‌ونقل ثابت به این صورتحساب
                            </label>
                            <div class="ezi-help">مبلغ ثابت فعلی: <strong><?php echo esc_html(number_format_i18n($shipping_toman)); ?> تومان</strong> — از بخش تنظیمات قابل تغییر است.</div>
                        </div>

                        <div class="ezi-summary">
                            <div class="ezi-summary-row"><span>جمع اقلام / مبلغ پایه</span><strong id="ezi-preview-subtotal">۰ تومان</strong></div>
                            <div class="ezi-summary-row"><span>حمل‌ونقل</span><strong id="ezi-preview-shipping">۰ تومان</strong></div>
                            <div class="ezi-summary-row ezi-summary-total"><span>مبلغ نهایی صورتحساب</span><strong id="ezi-preview-total">۰ تومان</strong></div>
                        </div>

                        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:20px" class="ezi-customer-grid">
                            <div class="ezi-field"><label for="ezi-customer">نام خریدار</label><input type="text" id="ezi-customer" name="customer_name"></div>
                            <div class="ezi-field"><label for="ezi-mobile">موبایل</label><input type="text" id="ezi-mobile" name="mobile" inputmode="tel"></div>
                            <div class="ezi-field"><label for="ezi-email">ایمیل</label><input type="email" id="ezi-email" name="email"></div>
                        </div>

                        <div class="ezi-field"><label for="ezi-description">توضیحات</label><textarea id="ezi-description" name="description" rows="4" placeholder="توضیحات تکمیلی برای خریدار"></textarea></div>
                        <?php submit_button('ساخت صورتحساب و دریافت لینک', 'primary ezi-submit', 'submit', false); ?>
                    </form>
                </div>

                <aside class="ezi-card">
                    <h2>وضعیت سیستم</h2>
                    <div class="ezi-side-stat">درگاه زرین‌پال<strong><?php echo get_option(self::OPT_MERCHANT_ID, '') ? 'تنظیم شده ✓' : 'تنظیم نشده'; ?></strong></div>
                    <div class="ezi-side-stat">ووکامرس<strong><?php echo $woocommerce_active ? 'فعال ✓' : 'غیرفعال'; ?></strong></div>
                    <div class="ezi-side-stat">حمل ثابت<strong><?php echo esc_html(number_format_i18n($shipping_toman)); ?> تومان</strong></div>
                    <p>برای نمایش صورتحساب داخل قالب سایت، شورت‌کد زیر باید در صفحه‌ای که در تنظیمات معرفی کرده‌اید قرار داشته باشد:</p>
                    <code class="ezi-code">[ezi_invoice]</code>
                    <p class="ezi-help">شورت‌کد قدیمی <code>[bzi_invoice]</code> نیز برای سازگاری نسخه 1.3 همچنان کار می‌کند.</p>
                </aside>
            </div>

            <div class="ezi-card ezi-list-card">
                <h2>صورتحساب‌های اخیر</h2>
                <div class="ezi-table-wrap">
                    <table class="ezi-table">
                        <thead><tr><th>#</th><th>عنوان</th><th>خریدار</th><th>مبلغ</th><th>حمل</th><th>وضعیت</th><th>کد رهگیری</th><th>تاریخ</th><th>عملیات</th></tr></thead>
                        <tbody>
                        <?php if (!$invoices): ?>
                            <tr><td colspan="9">هنوز صورتحسابی ساخته نشده است.</td></tr>
                        <?php else: foreach ($invoices as $invoice):
                            $url = self::invoice_url($invoice->token);
                            ?>
                            <tr>
                                <td><?php echo esc_html($invoice->id); ?></td>
                                <td><strong><?php echo esc_html($invoice->title); ?></strong><?php echo self::invoice_items_count_text($invoice); ?></td>
                                <td><?php echo esc_html($invoice->customer_name ?: '—'); ?><br><small><?php echo esc_html($invoice->mobile); ?></small></td>
                                <td><strong><?php echo esc_html(self::format_toman($invoice->amount_irr)); ?></strong></td>
                                <td><?php echo (int)$invoice->shipping_irr > 0 ? esc_html(self::format_toman($invoice->shipping_irr)) : '—'; ?></td>
                                <td><?php echo self::status_badge($invoice->status, $invoice->gateway_status); ?></td>
                                <td><?php echo esc_html($invoice->ref_id ?: '—'); ?></td>
                                <td><?php echo esc_html(mysql2date('Y/m/d H:i', $invoice->created_at)); ?></td>
                                <td><div class="ezi-actions">
                                    <a class="button button-small" href="<?php echo esc_url($url); ?>" target="_blank">مشاهده</a>
                                    <button type="button" class="button button-small ezi-copy" data-url="<?php echo esc_attr($url); ?>">کپی لینک</button>
                                    <?php if (!empty($invoice->authority) && $invoice->status !== 'paid'): ?>
                                        <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ezi_inquiry_invoice&id=' . (int)$invoice->id), 'ezi_inquiry_' . (int)$invoice->id)); ?>">استعلام</a>
                                    <?php endif; ?>
                                    <a class="button button-small ezi-delete" onclick="return confirm('این صورتحساب حذف شود؟')" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ezi_delete_invoice&id=' . (int)$invoice->id), 'ezi_delete_' . (int)$invoice->id)); ?>">حذف</a>
                                </div></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <style>@media(max-width:720px){.ezi-customer-grid{grid-template-columns:1fr!important}}</style>
        <script>
        (function(){
            const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            const searchNonce = <?php echo wp_json_encode($ajax_nonce); ?>;
            const shippingToman = <?php echo (int)$shipping_toman; ?>;
            const searchInput = document.getElementById('ezi-product-search');
            const resultsBox = document.getElementById('ezi-product-results');
            const itemsBody = document.getElementById('ezi-items-body');
            const manualInput = document.getElementById('ezi-manual-amount');
            const shippingCheck = document.getElementById('ezi-include-shipping');
            let timer = null;

            function faNumber(n){
                try { return new Intl.NumberFormat('fa-IR').format(Math.round(Number(n)||0)); }
                catch(e){ return String(Math.round(Number(n)||0)); }
            }

            function selectedRows(){ return itemsBody ? Array.from(itemsBody.querySelectorAll('tr[data-product-id]')) : []; }
            function refreshEmpty(){
                if(!itemsBody) return;
                const empty = itemsBody.querySelector('.ezi-empty-row');
                if(selectedRows().length === 0 && !empty){
                    itemsBody.insertAdjacentHTML('beforeend','<tr class="ezi-empty-row"><td colspan="5" class="ezi-empty-products">هنوز محصولی انتخاب نشده است.</td></tr>');
                } else if(selectedRows().length > 0 && empty){ empty.remove(); }
            }
            function recalc(){
                let subtotal = 0;
                const rows = selectedRows();
                if(rows.length){
                    rows.forEach(function(row){
                        const price = Number(row.dataset.priceToman || 0);
                        const qtyInput = row.querySelector('.ezi-qty');
                        const qty = Math.max(1, parseInt(qtyInput.value || 1,10));
                        qtyInput.value = qty;
                        const line = price * qty;
                        subtotal += line;
                        row.querySelector('.ezi-line-total').textContent = faNumber(line) + ' تومان';
                    });
                } else {
                    subtotal = Math.max(0, parseInt((manualInput && manualInput.value) || 0,10) || 0);
                }
                const shipping = shippingCheck && shippingCheck.checked ? shippingToman : 0;
                document.getElementById('ezi-preview-subtotal').textContent = faNumber(subtotal) + ' تومان';
                document.getElementById('ezi-preview-shipping').textContent = faNumber(shipping) + ' تومان';
                document.getElementById('ezi-preview-total').textContent = faNumber(subtotal + shipping) + ' تومان';
            }
            function addProduct(p){
                if(!itemsBody || document.querySelector('tr[data-product-id="'+p.id+'"]')) return;
                const tr = document.createElement('tr');
                tr.dataset.productId = p.id;
                tr.dataset.priceToman = p.price_toman;
                tr.innerHTML = '<td><strong></strong><div class="ezi-help"></div><input type="hidden" name="product_ids[]" value="'+p.id+'"></td>'+
                    '<td class="ezi-unit-price"></td>'+
                    '<td><input class="ezi-qty" type="number" name="quantities[]" value="1" min="1" max="999" step="1"></td>'+
                    '<td><strong class="ezi-line-total"></strong></td>'+
                    '<td><button type="button" class="ezi-remove">حذف</button></td>';
                tr.querySelector('td strong').textContent = p.name;
                tr.querySelector('.ezi-help').textContent = p.sku ? 'SKU: '+p.sku : 'شناسه: '+p.id;
                tr.querySelector('.ezi-unit-price').textContent = faNumber(p.price_toman) + ' تومان';
                itemsBody.appendChild(tr);
                refreshEmpty(); recalc();
                if(searchInput){ searchInput.value=''; }
                if(resultsBox){ resultsBox.style.display='none'; }
            }

            if(searchInput && resultsBox){
                searchInput.addEventListener('input', function(){
                    clearTimeout(timer);
                    const term = this.value.trim();
                    if(term.length < 2){ resultsBox.style.display='none'; return; }
                    timer = setTimeout(function(){
                        const body = new URLSearchParams({action:'ezi_search_products',nonce:searchNonce,term:term});
                        fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()})
                            .then(r=>r.json()).then(function(resp){
                                resultsBox.innerHTML='';
                                if(!resp.success){
                                    const d=document.createElement('div'); d.style.padding='12px'; d.textContent=(resp.data&&resp.data.message)?resp.data.message:'خطا در جستجو'; resultsBox.appendChild(d); resultsBox.style.display='block'; return;
                                }
                                if(!resp.data.length){
                                    const d=document.createElement('div'); d.style.padding='12px'; d.textContent='محصولی پیدا نشد.'; resultsBox.appendChild(d);
                                } else {
                                    resp.data.forEach(function(p){
                                        const b=document.createElement('button'); b.type='button'; b.className='ezi-result';
                                        const left=document.createElement('div');
                                        const name=document.createElement('div'); name.className='ezi-result-name'; name.textContent=p.name;
                                        const meta=document.createElement('div'); meta.className='ezi-result-meta'; meta.textContent=p.sku?'SKU: '+p.sku:'شناسه: '+p.id;
                                        left.appendChild(name); left.appendChild(meta);
                                        const price=document.createElement('div'); price.className='ezi-result-price'; price.textContent=faNumber(p.price_toman)+' تومان';
                                        b.appendChild(left); b.appendChild(price); b.addEventListener('click',()=>addProduct(p)); resultsBox.appendChild(b);
                                    });
                                }
                                resultsBox.style.display='block';
                            }).catch(function(){ resultsBox.innerHTML='<div style="padding:12px">خطا در ارتباط برای جستجوی محصول.</div>'; resultsBox.style.display='block'; });
                    },350);
                });
                document.addEventListener('click',function(e){ if(e.target!==searchInput && !resultsBox.contains(e.target)) resultsBox.style.display='none'; });
            }

            if(itemsBody){
                itemsBody.addEventListener('input',function(e){ if(e.target.classList.contains('ezi-qty')) recalc(); });
                itemsBody.addEventListener('click',function(e){ if(e.target.classList.contains('ezi-remove')){ e.target.closest('tr').remove(); refreshEmpty(); recalc(); } });
            }
            if(manualInput) manualInput.addEventListener('input',recalc);
            if(shippingCheck) shippingCheck.addEventListener('change',recalc);
            document.addEventListener('click',function(e){
                if(!e.target.classList.contains('ezi-copy')) return;
                const btn=e.target;
                if(navigator.clipboard){ navigator.clipboard.writeText(btn.dataset.url).then(function(){ const old=btn.textContent; btn.textContent='کپی شد ✓'; setTimeout(()=>btn.textContent=old,1500); }); }
                else { window.prompt('لینک را کپی کنید:',btn.dataset.url); }
            });
            recalc();
        })();
        </script>
        <?php
    }

    public static function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap" dir="rtl" style="max-width:900px">
            <h1>تنظیمات صورتحساب آنلاین</h1>
            <div style="background:#fff;border:1px solid #e4e7ec;border-radius:18px;padding:24px;margin-top:20px;box-shadow:0 6px 24px rgba(16,24,40,.05)">
                <form method="post" action="options.php">
                    <?php settings_fields('ezi_settings'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><label for="ezi-merchant">Merchant ID زرین‌پال</label></th>
                            <td><input class="regular-text" id="ezi-merchant" name="<?php echo esc_attr(self::OPT_MERCHANT_ID); ?>" value="<?php echo esc_attr(get_option(self::OPT_MERCHANT_ID, '')); ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"><p class="description">کد ۳۶ کاراکتری پذیرنده زرین‌پال.</p></td>
                        </tr>
                        <tr>
                            <th><label for="ezi-page">آدرس صفحه صورتحساب</label></th>
                            <td><input class="regular-text" type="url" id="ezi-page" name="<?php echo esc_attr(self::OPT_INVOICE_PAGE); ?>" value="<?php echo esc_attr(get_option(self::OPT_INVOICE_PAGE, '')); ?>" placeholder="https://example.com/payment"><p class="description">در این برگه شورت‌کد <code>[ezi_invoice]</code> را قرار دهید. این روش باعث می‌شود هدر، فوتر، اینماد و قالب سایت بدون دخالت افزونه نمایش داده شوند. شورت‌کد <code>[bzi_invoice]</code> نیز برای نسخه 1.3 معتبر است.</p></td>
                        </tr>
                        <tr>
                            <th><label for="ezi-business">نام کسب‌وکار</label></th>
                            <td><input class="regular-text" id="ezi-business" name="<?php echo esc_attr(self::OPT_BUSINESS_NAME); ?>" value="<?php echo esc_attr(get_option(self::OPT_BUSINESS_NAME, get_bloginfo('name'))); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="ezi-shipping">هزینه ثابت حمل‌ونقل</label></th>
                            <td><input class="regular-text" type="number" min="0" step="1" id="ezi-shipping" name="<?php echo esc_attr(self::OPT_FIXED_SHIPPING_TOMAN); ?>" value="<?php echo esc_attr(absint(get_option(self::OPT_FIXED_SHIPPING_TOMAN, 0))); ?>"> <span>تومان</span><p class="description">این مبلغ به‌عنوان هزینه حمل ثابت ذخیره می‌شود. هنگام ساخت هر صورتحساب می‌توانید اعمال آن را روشن یا خاموش کنید.</p></td>
                        </tr>
                    </table>
                    <?php submit_button('ذخیره تنظیمات'); ?>
                </form>
            </div>
        </div>
        <?php
    }

    public static function ajax_search_products() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز.'], 403);
        }
        check_ajax_referer('ezi_product_search', 'nonce');

        if (!self::woocommerce_available()) {
            wp_send_json_error(['message' => 'ووکامرس فعال نیست.'], 400);
        }

        $term = sanitize_text_field(wp_unslash($_POST['term'] ?? ''));
        if (strlen($term) < 2) {
            wp_send_json_success([]);
        }

        $multiplier = self::woocommerce_to_irr_multiplier();
        if ($multiplier <= 0) {
            wp_send_json_error(['message' => 'واحد پول ووکامرس باید ریال (IRR) یا تومان (IRT/TMN) باشد تا قیمت به‌درستی به زرین‌پال تبدیل شود.'], 400);
        }

        global $wpdb;
        $ids = [];

        $query = new WP_Query([
            'post_type' => ['product', 'product_variation'],
            'post_status' => 'publish',
            's' => $term,
            'posts_per_page' => 15,
            'fields' => 'ids',
            'orderby' => 'relevance',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);
        $ids = array_map('absint', $query->posts);

        $like = '%' . $wpdb->esc_like($term) . '%';
        $sku_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s LIMIT 15",
            $like
        ));
        $ids = array_values(array_unique(array_merge($ids, array_map('absint', $sku_ids))));
        $ids = array_slice($ids, 0, 20);

        $items = [];
        $seen = [];
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product || !$product->exists()) {
                continue;
            }

            // محصول متغیر به‌خودی‌خود قیمت دقیق یک انتخاب را مشخص نمی‌کند؛
            // بنابراین ورییشن‌های آن را به نتایج اضافه می‌کنیم.
            $candidates = [];
            if ($product->is_type('variable') && method_exists($product, 'get_children')) {
                foreach (array_slice($product->get_children(), 0, 20) as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation && $variation->exists()) {
                        $candidates[] = $variation;
                    }
                }
            } else {
                $candidates[] = $product;
            }

            foreach ($candidates as $candidate) {
                if (isset($seen[$candidate->get_id()])) {
                    continue;
                }
                $raw_price = $candidate->get_price();
                if ($raw_price === '') {
                    continue;
                }
                $irr = self::woocommerce_price_to_irr($raw_price);
                if ($irr < 0) {
                    continue;
                }
                $seen[$candidate->get_id()] = true;
                $items[] = [
                    'id' => $candidate->get_id(),
                    'name' => self::product_display_name($candidate),
                    'sku' => (string) $candidate->get_sku(),
                    'price_toman' => (int) round($irr / 10),
                ];
                if (count($items) >= 20) {
                    break 2;
                }
            }
        }

        wp_send_json_success($items);
    }

    public static function create_invoice() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز');
        }
        check_admin_referer('ezi_create_invoice');

        global $wpdb;
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $manual_toman = absint($_POST['amount_toman'] ?? 0);
        $customer_name = sanitize_text_field(wp_unslash($_POST['customer_name'] ?? ''));
        $mobile = sanitize_text_field(wp_unslash($_POST['mobile'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
        $include_shipping = !empty($_POST['include_shipping']);
        $fixed_shipping_toman = max(0, absint(get_option(self::OPT_FIXED_SHIPPING_TOMAN, 0)));

        if ($title === '') {
            self::redirect_admin('عنوان صورتحساب الزامی است.', true);
        }

        $product_ids = isset($_POST['product_ids']) ? (array) wp_unslash($_POST['product_ids']) : [];
        $quantities = isset($_POST['quantities']) ? (array) wp_unslash($_POST['quantities']) : [];
        $items = [];
        $subtotal_irr = 0;

        if (!empty($product_ids)) {
            if (!self::woocommerce_available()) {
                self::redirect_admin('محصول انتخاب شده اما ووکامرس فعال نیست.', true);
            }
            if (self::woocommerce_to_irr_multiplier() <= 0) {
                self::redirect_admin('واحد پول ووکامرس برای تبدیل امن به ریال پشتیبانی نمی‌شود. از IRR یا IRT/TMN استفاده کنید.', true);
            }

            foreach ($product_ids as $index => $raw_id) {
                $product_id = absint($raw_id);
                $qty = isset($quantities[$index]) ? absint($quantities[$index]) : 1;
                $qty = max(1, min(999, $qty));
                if (!$product_id) {
                    continue;
                }

                $product = wc_get_product($product_id);
                if (!$product || !$product->exists()) {
                    self::redirect_admin('یکی از محصولات انتخاب‌شده دیگر در ووکامرس وجود ندارد.', true);
                }
                $raw_price = $product->get_price();
                if ($raw_price === '') {
                    self::redirect_admin('برای محصول «' . $product->get_name() . '» قیمت مشخص نشده است.', true);
                }

                $unit_irr = self::woocommerce_price_to_irr($raw_price);
                if ($unit_irr < 0) {
                    self::redirect_admin('قیمت یکی از محصولات قابل تبدیل به ریال نیست.', true);
                }
                $line_irr = $unit_irr * $qty;
                $subtotal_irr += $line_irr;
                $items[] = [
                    'product_id' => $product->get_id(),
                    'name' => self::product_display_name($product),
                    'sku' => (string) $product->get_sku(),
                    'quantity' => $qty,
                    'unit_price_irr' => $unit_irr,
                    'line_total_irr' => $line_irr,
                ];
            }
        }

        if (empty($items)) {
            if ($manual_toman < 1) {
                self::redirect_admin('حداقل یک محصول انتخاب کنید یا مبلغ دستی معتبر وارد کنید.', true);
            }
            $subtotal_irr = $manual_toman * 10;
        }

        $shipping_irr = ($include_shipping && $fixed_shipping_toman > 0) ? $fixed_shipping_toman * 10 : 0;
        $amount_irr = $subtotal_irr + $shipping_irr;
        if ($amount_irr < 1) {
            self::redirect_admin('مبلغ نهایی صورتحساب باید بیشتر از صفر باشد.', true);
        }

        $token = wp_generate_password(32, false, false) . wp_rand(1000, 9999);
        $now = current_time('mysql');
        $inserted = $wpdb->insert(self::table(), [
            'token' => $token,
            'title' => $title,
            'customer_name' => $customer_name,
            'mobile' => $mobile,
            'email' => $email,
            'items_json' => $items ? wp_json_encode($items, JSON_UNESCAPED_UNICODE) : null,
            'subtotal_irr' => $subtotal_irr,
            'shipping_irr' => $shipping_irr,
            'amount_irr' => $amount_irr,
            'description' => $description,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s','%s','%s','%s','%s','%s','%d','%d','%d','%s','%s','%s','%s']);

        if (!$inserted) {
            self::redirect_admin('خطا در ذخیره صورتحساب.', true);
        }

        self::redirect_admin('صورتحساب ساخته شد. لینک: ' . self::invoice_url($token), false);
    }

    public static function delete_invoice() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز');
        }
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('ezi_delete_' . $id);
        global $wpdb;
        $wpdb->delete(self::table(), ['id' => $id], ['%d']);
        self::redirect_admin('صورتحساب حذف شد.', false);
    }

    public static function inquiry_invoice() {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی غیرمجاز');
        }
        $id = absint($_GET['id'] ?? 0);
        check_admin_referer('ezi_inquiry_' . $id);

        $invoice = self::get_invoice_by_id($id);
        if (!$invoice || empty($invoice->authority)) {
            self::redirect_admin('آتوریتی این صورتحساب موجود نیست.', true);
        }

        $merchant_id = trim((string) get_option(self::OPT_MERCHANT_ID, ''));
        if ($merchant_id === '') {
            self::redirect_admin('Merchant ID را در تنظیمات وارد کنید.', true);
        }

        $result = self::zarinpal_post('https://payment.zarinpal.com/pg/v4/payment/inquiry.json', [
            'merchant_id' => $merchant_id,
            'authority' => $invoice->authority,
        ]);
        if (is_wp_error($result)) {
            self::redirect_admin($result->get_error_message(), true);
        }

        $status = sanitize_text_field($result['data']['status'] ?? '');
        if ($status) {
            global $wpdb;
            $wpdb->update(self::table(), [
                'gateway_status' => $status,
                'updated_at' => current_time('mysql'),
            ], ['id' => $id], ['%s','%s'], ['%d']);
            self::redirect_admin('وضعیت درگاه: ' . $status . ' — استعلام فقط وضعیت را اعلام می‌کند و جای Verify نیست.', false);
        }

        self::redirect_admin(self::extract_gateway_error($result, 'پاسخ نامعتبر از استعلام زرین‌پال.'), true);
    }

    public static function start_payment() {
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? $_POST['token'] ?? ''));
        $invoice = self::get_invoice_by_token($token);
        if (!$invoice) {
            wp_die('صورتحساب یافت نشد.');
        }
        if ($invoice->status === 'paid') {
            wp_safe_redirect(self::invoice_url($invoice->token));
            exit;
        }

        $merchant_id = trim((string) get_option(self::OPT_MERCHANT_ID, ''));
        if ($merchant_id === '') {
            wp_die('Merchant ID زرین‌پال تنظیم نشده است.');
        }

        $callback = add_query_arg([
            'action' => 'ezi_verify',
            'token' => $invoice->token,
        ], admin_url('admin-post.php'));

        $payload = [
            'merchant_id' => $merchant_id,
            'amount' => (int) $invoice->amount_irr,
            'currency' => 'IRR',
            'description' => $invoice->description ?: $invoice->title,
            'callback_url' => $callback,
            'metadata' => [
                'order_id' => (string) $invoice->id,
            ],
        ];
        if ($invoice->mobile) {
            $payload['metadata']['mobile'] = $invoice->mobile;
        }
        if ($invoice->email) {
            $payload['metadata']['email'] = $invoice->email;
        }

        $result = self::zarinpal_post('https://payment.zarinpal.com/pg/v4/payment/request.json', $payload);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        $code = (int) ($result['data']['code'] ?? 0);
        $authority = sanitize_text_field($result['data']['authority'] ?? '');
        if ($code === 100 && $authority !== '') {
            global $wpdb;
            $wpdb->update(self::table(), [
                'authority' => $authority,
                'status' => 'waiting_payment',
                'gateway_status' => '',
                'updated_at' => current_time('mysql'),
            ], ['id' => $invoice->id], ['%s','%s','%s','%s'], ['%d']);

            wp_redirect('https://payment.zarinpal.com/pg/StartPay/' . rawurlencode($authority));
            exit;
        }

        wp_die(esc_html(self::extract_gateway_error($result, 'خطا در ایجاد درخواست پرداخت زرین‌پال.')));
    }

    public static function verify_payment() {
        $token = sanitize_text_field(wp_unslash($_GET['token'] ?? ''));
        $invoice = self::get_invoice_by_token($token);
        if (!$invoice) {
            wp_die('صورتحساب یافت نشد.');
        }

        $status = sanitize_text_field(wp_unslash($_GET['Status'] ?? ''));
        $authority = sanitize_text_field(wp_unslash($_GET['Authority'] ?? ''));

        if ($status !== 'OK') {
            self::update_invoice_status($invoice->id, 'cancelled', $authority, '', '', 'FAILED');
            wp_safe_redirect(add_query_arg('payment', 'cancelled', self::invoice_url($invoice->token)));
            exit;
        }

        if ($authority === '' || (!empty($invoice->authority) && !hash_equals((string) $invoice->authority, $authority))) {
            wp_die('Authority معتبر نیست.');
        }

        $merchant_id = trim((string) get_option(self::OPT_MERCHANT_ID, ''));
        if ($merchant_id === '') {
            wp_die('Merchant ID زرین‌پال تنظیم نشده است.');
        }

        $result = self::zarinpal_post('https://payment.zarinpal.com/pg/v4/payment/verify.json', [
            'merchant_id' => $merchant_id,
            'amount' => (int) $invoice->amount_irr,
            'authority' => $authority,
        ]);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()));
        }

        $code = (int) ($result['data']['code'] ?? 0);
        if ($code === 100 || $code === 101) {
            $ref_id = sanitize_text_field((string) ($result['data']['ref_id'] ?? $invoice->ref_id));
            $card_pan = sanitize_text_field((string) ($result['data']['card_pan'] ?? $invoice->card_pan));
            self::update_invoice_status($invoice->id, 'paid', $authority, $ref_id, $card_pan, 'VERIFIED', true);
            wp_safe_redirect(add_query_arg('payment', 'success', self::invoice_url($invoice->token)));
            exit;
        }

        self::update_invoice_status($invoice->id, 'failed', $authority, '', '', 'FAILED');
        wp_die(esc_html(self::extract_gateway_error($result, 'پرداخت وریفای نشد.')));
    }

    public static function invoice_shortcode() {
        $token = sanitize_text_field(wp_unslash($_GET['ezi_invoice'] ?? $_GET['bzi_invoice'] ?? ''));
        if (!$token) {
            return '<div class="ezi-public-alert ezi-public-error">لینک صورتحساب معتبر نیست.</div>';
        }
        $invoice = self::get_invoice_by_token($token);
        if (!$invoice) {
            return '<div class="ezi-public-alert ezi-public-error">صورتحساب پیدا نشد یا حذف شده است.</div>';
        }

        $business = get_option(self::OPT_BUSINESS_NAME, get_bloginfo('name'));
        $items = self::decode_items($invoice->items_json ?? '');
        $pay_url = add_query_arg(['action' => 'ezi_pay', 'token' => $invoice->token], admin_url('admin-post.php'));
        $payment_result = sanitize_key(wp_unslash($_GET['payment'] ?? ''));

        ob_start();
        ?>
        <div class="ezi-public-invoice" dir="rtl">
            <div class="ezi-public-head">
                <div class="ezi-public-business"><?php echo esc_html($business); ?></div>
                <div class="ezi-public-number">صورتحساب شماره <?php echo esc_html($invoice->id); ?></div>
            </div>
            <h2 class="ezi-public-title"><?php echo esc_html($invoice->title); ?></h2>

            <?php if ($payment_result === 'success'): ?>
                <div class="ezi-public-alert ezi-public-success">پرداخت با موفقیت تأیید شد.</div>
            <?php elseif ($payment_result === 'cancelled'): ?>
                <div class="ezi-public-alert ezi-public-error">پرداخت انجام نشد یا توسط خریدار لغو شد.</div>
            <?php endif; ?>

            <?php if ($invoice->customer_name): ?>
                <div class="ezi-public-customer"><span>خریدار</span><strong><?php echo esc_html($invoice->customer_name); ?></strong></div>
            <?php endif; ?>

            <?php if ($items): ?>
                <div class="ezi-public-table-wrap">
                    <table class="ezi-public-table">
                        <thead><tr><th>محصول</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><strong><?php echo esc_html($item['name'] ?? 'محصول'); ?></strong><?php if (!empty($item['sku'])): ?><small>SKU: <?php echo esc_html($item['sku']); ?></small><?php endif; ?></td>
                                <td><?php echo esc_html((int)($item['quantity'] ?? 1)); ?></td>
                                <td><?php echo esc_html(self::format_toman((int)($item['unit_price_irr'] ?? 0))); ?></td>
                                <td><strong><?php echo esc_html(self::format_toman((int)($item['line_total_irr'] ?? 0))); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($invoice->description): ?>
                <div class="ezi-public-desc"><?php echo nl2br(esc_html($invoice->description)); ?></div>
            <?php endif; ?>

            <div class="ezi-public-totals">
                <?php if ($items || (int)$invoice->shipping_irr > 0): ?>
                    <div><span>جمع</span><strong><?php echo esc_html(self::format_toman((int)$invoice->subtotal_irr)); ?></strong></div>
                    <?php if ((int)$invoice->shipping_irr > 0): ?><div><span>حمل‌ونقل</span><strong><?php echo esc_html(self::format_toman((int)$invoice->shipping_irr)); ?></strong></div><?php endif; ?>
                <?php endif; ?>
                <div class="ezi-public-total"><span>مبلغ قابل پرداخت</span><strong><?php echo esc_html(self::format_toman((int)$invoice->amount_irr)); ?></strong></div>
            </div>

            <?php if ($invoice->status === 'paid'): ?>
                <div class="ezi-public-paid">✓ این صورتحساب پرداخت شده است.<?php if ($invoice->ref_id): ?><br><small>کد رهگیری زرین‌پال: <?php echo esc_html($invoice->ref_id); ?></small><?php endif; ?></div>
            <?php else: ?>
                <?php if (trim((string)get_option(self::OPT_MERCHANT_ID, '')) === ''): ?>
                    <div class="ezi-public-alert ezi-public-error">درگاه پرداخت هنوز توسط مدیر سایت تنظیم نشده است.</div>
                <?php else: ?>
                    <a class="ezi-public-pay" href="<?php echo esc_url($pay_url); ?>">پرداخت آنلاین صورتحساب</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <style>
            .ezi-public-invoice{box-sizing:border-box;max-width:780px;margin:28px auto;padding:0;background:#fff;border:1px solid #e7e9ee;border-radius:22px;box-shadow:0 12px 35px rgba(16,24,40,.08);overflow:hidden;font-family:inherit;color:#1d2939}.ezi-public-invoice *{box-sizing:border-box}.ezi-public-head{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:20px 24px;background:#f8fafc;border-bottom:1px solid #eaecf0}.ezi-public-business{font-weight:900;font-size:19px}.ezi-public-number{font-size:13px;color:#667085}.ezi-public-title{font-size:25px;text-align:center;margin:28px 20px 20px;color:#101828}.ezi-public-customer{display:flex;justify-content:space-between;gap:20px;margin:0 24px 20px;padding:12px 15px;background:#f8fafc;border-radius:12px}.ezi-public-table-wrap{overflow:auto;margin:0 24px 20px}.ezi-public-table{width:100%;border-collapse:collapse;min-width:560px}.ezi-public-table th,.ezi-public-table td{text-align:right;padding:12px 10px;border-bottom:1px solid #eaecf0}.ezi-public-table th{background:#f8fafc;color:#475467;font-size:12px}.ezi-public-table small{display:block;color:#98a2b3;margin-top:4px}.ezi-public-desc{margin:0 24px 20px;padding:15px;background:#f9fafb;border-radius:12px;line-height:2}.ezi-public-totals{margin:0 24px 22px;border:1px solid #eaecf0;border-radius:14px;overflow:hidden}.ezi-public-totals>div{display:flex;justify-content:space-between;gap:20px;padding:12px 15px;border-bottom:1px solid #eaecf0}.ezi-public-totals>div:last-child{border-bottom:0}.ezi-public-total{background:#101828!important;color:#fff;font-size:18px}.ezi-public-total strong{font-size:22px}.ezi-public-pay{display:block;margin:0 24px 24px;padding:15px 20px;text-align:center;border-radius:12px;background:#1570ef;color:#fff!important;text-decoration:none!important;font-weight:800;font-size:17px;transition:.2s}.ezi-public-pay:hover{filter:brightness(.95);transform:translateY(-1px)}.ezi-public-paid{margin:0 24px 24px;padding:16px;text-align:center;border-radius:12px;background:#ecfdf3;color:#067647;font-weight:800}.ezi-public-alert{margin:0 24px 20px;padding:13px 15px;border-radius:12px}.ezi-public-success{background:#ecfdf3;color:#067647}.ezi-public-error{background:#fef3f2;color:#b42318}@media(max-width:600px){.ezi-public-invoice{margin:15px 0;border-radius:16px}.ezi-public-head{padding:16px;align-items:flex-start;flex-direction:column}.ezi-public-title{font-size:21px}.ezi-public-table-wrap,.ezi-public-desc,.ezi-public-totals,.ezi-public-customer,.ezi-public-pay,.ezi-public-paid,.ezi-public-alert{margin-right:15px;margin-left:15px}}
        </style>
        <?php
        return ob_get_clean();
    }

    private static function product_display_name($product) {
        if (!$product || !is_object($product) || !method_exists($product, 'get_name')) {
            return 'محصول';
        }
        $name = wp_strip_all_tags($product->get_name());
        if (method_exists($product, 'is_type') && $product->is_type('variation') && function_exists('wc_get_formatted_variation')) {
            $variation = trim(wp_strip_all_tags(wc_get_formatted_variation($product, true, true, true)));
            if ($variation !== '') {
                $name .= ' — ' . $variation;
            }
        }
        return $name;
    }

    private static function woocommerce_available() {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }

    private static function woocommerce_to_irr_multiplier() {
        if (!self::woocommerce_available() || !function_exists('get_woocommerce_currency')) {
            return 0;
        }
        $currency = strtoupper((string) get_woocommerce_currency());
        if ($currency === 'IRR') {
            return 1;
        }
        if (in_array($currency, ['IRT', 'TMN', 'TOMAN', 'IRTOMAN'], true)) {
            return 10;
        }
        /**
         * برای فروشگاه‌هایی با واحد پول سفارشی، توسعه‌دهنده می‌تواند ضریب تبدیل قیمت ووکامرس به ریال را تعیین کند.
         */
        return (float) apply_filters('ezi_woocommerce_price_to_irr_multiplier', 0, $currency);
    }

    private static function woocommerce_price_to_irr($price) {
        $multiplier = self::woocommerce_to_irr_multiplier();
        if ($multiplier <= 0 || !is_numeric($price)) {
            return -1;
        }
        return (int) round((float) $price * $multiplier);
    }

    private static function decode_items($json) {
        if (!$json) {
            return [];
        }
        $items = json_decode($json, true);
        return is_array($items) ? $items : [];
    }

    private static function invoice_items_count_text($invoice) {
        $items = self::decode_items($invoice->items_json ?? '');
        if (!$items) {
            return '<br><small style="color:#98a2b3">مبلغ دستی</small>';
        }
        $count = 0;
        foreach ($items as $item) {
            $count += max(1, (int)($item['quantity'] ?? 1));
        }
        return '<br><small style="color:#98a2b3">' . esc_html(number_format_i18n($count)) . ' قلم محصول</small>';
    }

    private static function zarinpal_post($url, array $payload) {
        $response = wp_remote_post($url, [
            'timeout' => 25,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('ezi_gateway_connection', 'خطا در اتصال به زرین‌پال: ' . $response->get_error_message());
        }
        $http = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return new WP_Error('ezi_gateway_response', 'پاسخ نامعتبر از زرین‌پال. HTTP ' . $http);
        }
        return $json;
    }

    private static function extract_gateway_error($result, $fallback) {
        if (!is_array($result)) {
            return $fallback;
        }
        if (!empty($result['data']['message'])) {
            return sanitize_text_field((string) $result['data']['message']);
        }
        if (!empty($result['message'])) {
            return sanitize_text_field((string) $result['message']);
        }
        if (!empty($result['errors']['message'])) {
            return sanitize_text_field((string) $result['errors']['message']);
        }
        if (!empty($result['errors']) && is_array($result['errors'])) {
            $flat = wp_json_encode($result['errors'], JSON_UNESCAPED_UNICODE);
            if ($flat) {
                return $fallback . ' ' . $flat;
            }
        }
        return $fallback;
    }

    private static function get_invoice_by_token($token) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE token = %s LIMIT 1', $token));
    }

    private static function get_invoice_by_id($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id));
    }

    private static function update_invoice_status($id, $status, $authority = '', $ref_id = '', $card_pan = '', $gateway_status = '', $paid = false) {
        global $wpdb;
        $data = [
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ];
        $formats = ['%s', '%s'];
        if ($authority !== '') { $data['authority'] = $authority; $formats[] = '%s'; }
        if ($ref_id !== '') { $data['ref_id'] = $ref_id; $formats[] = '%s'; }
        if ($card_pan !== '') { $data['card_pan'] = $card_pan; $formats[] = '%s'; }
        if ($gateway_status !== '') { $data['gateway_status'] = $gateway_status; $formats[] = '%s'; }
        if ($paid) { $data['paid_at'] = current_time('mysql'); $formats[] = '%s'; }
        $wpdb->update(self::table(), $data, ['id' => $id], $formats, ['%d']);
    }

    private static function invoice_url($token) {
        $page = trim((string) get_option(self::OPT_INVOICE_PAGE, ''));
        if ($page !== '') {
            return add_query_arg('ezi_invoice', $token, $page);
        }
        // بدون صفحه شورت‌کد، لینک به خانه می‌رود؛ مدیر باید صفحه صورتحساب را در تنظیمات تعیین کند.
        return add_query_arg('ezi_invoice', $token, home_url('/'));
    }

    private static function redirect_admin($message, $error = false) {
        $url = add_query_arg([
            'page' => 'ezi-invoices',
            'ezi_msg' => $message,
            'ezi_type' => $error ? 'error' : 'success',
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private static function status_badge($status, $gateway_status = '') {
        $map = [
            'pending' => ['در انتظار', '#667085'],
            'waiting_payment' => ['منتظر پرداخت', '#b54708'],
            'paid' => ['پرداخت شده', '#067647'],
            'cancelled' => ['لغو شده', '#b42318'],
            'failed' => ['ناموفق', '#b42318'],
        ];
        $item = $map[$status] ?? [$status, '#667085'];
        $extra = $gateway_status ? ' / ' . esc_html($gateway_status) : '';
        return '<span style="display:inline-block;padding:4px 9px;border-radius:999px;background:' . esc_attr($item[1]) . ';color:#fff;font-size:11px;font-weight:700">' . esc_html($item[0]) . $extra . '</span>';
    }

    private static function format_toman($amount_irr) {
        return number_format_i18n((int) round(((int)$amount_irr) / 10)) . ' تومان';
    }
}

Easy_Zarinpal_Invoice_Plugin::init();
