=== Easy Invoice for ZarinPal ===
Contributors: tahanoa
Tags: invoice, payment, zarinpal, payment-link, ecommerce
Requires at least: 6.3
Tested up to: 7.1
Stable tag: 1.5.7
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create secure payment-link invoices with custom amounts or WooCommerce products and accept payments through ZarinPal.

== Description ==

Easy Invoice for ZarinPal lets a WordPress administrator create a payment invoice, copy its public link, and send that link to a customer. The customer sees the invoice inside a normal WordPress page, so the active theme remains responsible for the header, footer, trust badges, navigation, and other site elements.

The plugin works without WooCommerce for manual-amount invoices. If WooCommerce is active, administrators can search products by name or SKU, choose quantities, and add those products to an invoice. Product prices are read again on the server when the invoice is created and saved as a snapshot, so browser-submitted prices are not trusted and later catalog price changes do not alter an existing invoice.

Features include:

* Manual-amount invoices.
* Optional WooCommerce product search by name or SKU.
* Server-side product-price snapshot at invoice creation time.
* Configurable fixed shipping amount, enabled per invoice.
* Public invoice page rendered through a shortcode inside the active theme.
* ZarinPal payment Request, Verify, and Inquiry integration.
* Server-side amount verification and stored-Authority matching.
* Admin-only manual re-verification for recovery after temporary API/callback errors.
* Optional, redacted rolling debug logging stored in WordPress (disabled by default).
* WordPress Privacy Tools support for exporting customer invoice data and erasing contact fields by email.
* Public invoice URLs request no-cache and no-index behavior.
* Optional data removal on uninstall (disabled by default).
* Backward-compatible migration from the previous 1.4.x private build.
* No PDF generation.
* No telemetry sent to the plugin author.

This plugin is an independent integration and is not an official ZarinPal plugin.

= External service disclosure =

This plugin connects to **ZarinPal**, an external payment service, only when payment-related actions require it:

* When a customer starts a payment, the plugin sends the configured merchant ID, invoice amount, currency, invoice description, callback URL, invoice/order ID, and optional customer mobile/email fields to ZarinPal.
* When a payment callback is received, the plugin sends the stored merchant ID, stored invoice amount, and stored Authority to ZarinPal's Verify API.
* When an administrator explicitly clicks Inquiry, the plugin sends the merchant ID and stored Authority to ZarinPal's Inquiry API.

A ZarinPal merchant account and Merchant ID are required to accept payments.

Service website: https://www.zarinpal.com/

Terms of service: https://www.zarinpal.com/terms

Privacy / information security policy: https://www.zarinpal.com/policy

The plugin author does not receive payment data, customer data, telemetry, or analytics from sites using this plugin.

= WooCommerce notes =

WooCommerce is optional. When enabled, this plugin reads product/variation names, SKU values, and current prices to build invoice line items. It does **not** create a WooCommerce order, reserve stock, apply coupons, calculate shipping methods, or run WooCommerce checkout. The invoice stores its own product-price snapshot.

WooCommerce currency is supported directly when it is IRR (Rial) or a Toman-style currency code such as IRT/TMN. Developers can provide another conversion multiplier with the `ezinv_woocommerce_price_to_irr_multiplier` filter.

== Installation ==

1. Upload the `easy-invoice-for-zarinpal` folder to `/wp-content/plugins/`, or upload the plugin ZIP from **Plugins > Add New > Upload Plugin**.
2. Activate **Easy Invoice for ZarinPal**.
3. Create a normal WordPress page, for example `/payment/`.
4. Put the shortcode `[ezinv_invoice]` in that page. The previous `[ezi_invoice]` shortcode remains supported for migration compatibility.
5. Go to **Invoices > Settings**.
6. Enter the 36-character ZarinPal Merchant ID.
7. Select the WordPress page that contains the invoice shortcode.
8. Optionally set the business name, fixed shipping amount, debug logging, and uninstall-data preference.
9. Go to **Invoices**, create an invoice, then copy its public link and send it to the customer.

= Upgrading from private version 1.4.x =

Deactivate the previous 1.4.x private plugin before activating this public-directory build. Existing invoice data stays in the same `easy_zarinpal_invoices` table, and known `ezi_*` settings are migrated to the new `ezinv_*` option names where possible. Existing pages using `[ezi_invoice]` continue to render.

Because the public-directory build uses a new plugin folder/slug, do not keep the old private build active at the same time.

== Frequently Asked Questions ==

= Will the invoice hide my site's header or footer? =

No. The plugin does not replace the WordPress template. The invoice is rendered only where the shortcode is placed inside a normal published page, so a properly coded theme continues to control the header and footer.

= Can a visitor change the amount in the browser before paying? =

Changing HTML or JavaScript values cannot change the amount used by the gateway. Manual amounts are saved by an authorized administrator, WooCommerce product prices are re-read server-side when the invoice is created, and Request/Verify use the amount stored in the database.

= Can a fake callback URL mark an invoice as paid? =

No. Callback query parameters alone never mark an invoice as paid. The callback Authority must match the Authority previously stored for that invoice, and a successful server-to-server ZarinPal Verify response is required before the local status becomes Paid.

= Why is there a Re-verify button in the admin? =

A bank payment may complete while the callback site request or Verify API call encounters a temporary network problem. Re-verify lets an authorized administrator retry ZarinPal Verify using the invoice's stored amount and Authority. It is protected by capability checks and an invoice-specific nonce.

= Does Inquiry confirm a payment? =

No. Inquiry only records the state reported by the Inquiry endpoint. It never marks an invoice paid. Payment confirmation is performed only by Verify.

= What does debug logging store? =

Logging is disabled by default. When enabled, the plugin stores a rolling list of up to 100 redacted gateway events in a non-autoloaded WordPress option. Its logger intentionally excludes Merchant IDs, invoice tokens, Authorities, card numbers, customer names, email addresses, and phone numbers. Administrators can clear the stored log from the settings screen.

= What personal data can be stored? =

Depending on invoice fields used, the WordPress database can store customer name, mobile number, email address, invoice line items, amounts, masked card number, payment state, and gateway reference ID. WordPress privacy-policy helper text is registered by the plugin. The WordPress personal-data exporter can export matching invoice data by customer email, and the eraser removes customer name/mobile/email while retaining financial transaction records. See the External service disclosure section for data sent to ZarinPal.

= Does deleting the plugin delete invoices? =

Not by default. In **Invoices > Settings**, administrators can explicitly enable deletion of plugin settings and invoice tables when the plugin is deleted.

== Changelog ==

= 1.5.7 =

* Hardened and centralized request sanitization while keeping nonce verification in state-changing handlers.
* Replaced the direct WooCommerce SKU SQL lookup with WP_Query.
* Added explicit object caching and cache invalidation for custom invoice-table reads.
* Documented and narrowly suppressed unavoidable custom-table database sniffs.
* Cleaned uninstall globals and documented uninstall-only database operations.
* Reduced Plugin Check warning sources without weakening payment verification or exposing customer data.

= 1.5.2 =

* Improved WordPress.org compatibility checks.
* Hardened input handling, output escaping, and admin security flows.
* Improved plugin review compatibility.

= 1.5.1 =
* Improved responsive admin invoice creation screen for mobile devices.

= 1.5.0 =

* Changed to a WordPress.org-oriented file structure with separated admin, database, gateway, settings, logging, public, CSS, and JavaScript components.
* Added longer `EZINV_` / `ezinv_` prefixes for public PHP identifiers and options while migrating known 1.4.x `ezi_*` option names.
* Added capability checks and sanitized invoice-specific nonces for all state-changing admin actions.
* Changed public payment start to POST with an invoice-bound WordPress nonce.
* Hardened callback validation: stored Authority is validated before any success/cancel state change.
* Payment success now requires ZarinPal Verify; callback query values alone cannot mark an invoice paid.
* Request and Verify use the amount stored server-side in the invoice database record.
* Added a short verification lock to reduce duplicate concurrent Verify processing.
* Added admin re-verification for recoverable callback/API failures.
* Hardened ZarinPal API handling for transport errors, HTTP failures, and invalid JSON.
* Added opt-in, privacy-conscious rolling debug logging stored in WordPress with sensitive fields excluded.
* Added WordPress privacy-policy helper text, personal-data exporter/eraser integration, no-cache/no-index invoice behavior, and documented the external ZarinPal service.
* Added optional data cleanup on uninstall.
* Moved CSS/JavaScript to locally enqueued files.
* Preserved WooCommerce product search, server-side product price snapshots, and fixed shipping.
* PDF functionality remains removed.

== Upgrade Notice ==

= 1.5.0 =

Security and WordPress.org-readiness release. Deactivate the older private build first, then activate this build. Existing 1.4.x invoice table data and the `[ezi_invoice]` compatibility shortcode are preserved; review the selected invoice page after upgrading.
