# Easy Invoice for ZarinPal — Installation & Usage

## Requirements

- WordPress 6.3 or newer.
- PHP 7.4 or newer.
- A valid 36-character ZarinPal Merchant ID.
- WooCommerce is optional and is only required for product search/selection.

## Installation

1. In WordPress, go to **Plugins → Add New → Upload Plugin**.
2. Upload `easy-invoice-for-zarinpal-1.5.0.zip` and activate it.
3. Create a normal published WordPress page, for example **Payment**.
4. Put `[ezinv_invoice]` in that page's content.
5. Go to **Invoices → Settings**.
6. Enter the ZarinPal Merchant ID and choose the page created above.
7. Optionally enter a business name, fixed shipping fee, and enable redacted debug logging.

## Create an invoice

1. Open **Invoices** in the WordPress dashboard.
2. Enter an invoice title and optional customer details.
3. Either enter a manual amount in Toman, or search WooCommerce products by name/SKU and choose quantities.
4. Optionally enable the configured fixed shipping fee for that invoice.
5. Create the invoice and copy its public link.
6. Send the link to the customer.

## Payment flow

The public invoice is rendered inside the selected WordPress page. The plugin does not replace the theme template, so the theme's header, footer, menus, trust badges, and other normal page elements remain available.

When the customer starts payment, the plugin creates a ZarinPal payment request using the amount stored in the invoice record. After the customer returns, the callback Authority must match the stored Authority and the plugin performs a server-to-server Verify request before marking the invoice as paid.

## WooCommerce behavior

WooCommerce is optional. Product prices are re-read from WooCommerce on the server when the invoice is submitted and then stored as an invoice snapshot. Browser-submitted prices are not trusted. The plugin does not create WooCommerce orders, reserve stock, apply coupons, or use WooCommerce checkout.

## Debug log

Debug logging is disabled by default. When enabled, a rolling maximum of 100 redacted operational events is stored in a non-autoloaded WordPress option. Merchant IDs, invoice tokens, Authority values, card numbers, customer names, emails, and phone numbers are excluded. The log can be cleared from **Invoices → Settings**.

## Upgrade from the private 1.4.x build

Deactivate the old 1.4.x plugin before activating this build. The invoice table is retained and known `ezi_*` settings are migrated. Existing pages containing `[ezi_invoice]` continue to work, although `[ezinv_invoice]` is the preferred shortcode for new installations.
