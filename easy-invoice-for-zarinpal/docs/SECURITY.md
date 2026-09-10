# Security Notes

Easy Invoice for ZarinPal is designed so that browser input and callback query strings are not sufficient to create a paid invoice state.

## Administrative actions

- State-changing admin actions require `manage_options`.
- Create/delete/inquiry/re-verify/log-clear actions use POST and WordPress nonces.
- AJAX product search requires `manage_options` and an AJAX nonce.
- Text, email, mobile, IDs, quantities, amounts, callback values, and settings are sanitized and validated before use.

## Amount integrity

- Manual invoice amounts are accepted only from an authorized admin request and are stored server-side.
- WooCommerce prices are re-read from WooCommerce during invoice creation; prices supplied by browser JavaScript are never accepted as authoritative.
- The fixed shipping fee is read from the saved server-side setting.
- ZarinPal Request and Verify use the stored `amount_irr` value from the invoice record.

## Callback and payment verification

- The ZarinPal callback uses a high-entropy invoice token to locate the invoice.
- Callback `Status` and `Authority` values are strictly validated.
- Callback Authority must match the Authority previously stored from the payment Request response using `hash_equals()`.
- Query-string values never directly mark an invoice paid.
- A successful server-to-server ZarinPal Verify response is required before the local status becomes `paid`.
- Verify response codes `100` and `101` are treated as verified according to the ZarinPal API behavior documented for this integration.
- Inquiry never marks an invoice paid.
- A short-lived verification lock reduces concurrent duplicate Verify processing.
- A `payment=success` query parameter cannot manufacture a success message unless the local invoice status is already `paid`.

## Gateway communication

- ZarinPal API calls use the WordPress HTTP API over HTTPS.
- Redirects to payment use `wp_safe_redirect()` with only `payment.zarinpal.com` added to the allowed-host list for that redirect.
- Transport failures, non-2xx HTTP responses, invalid JSON, and rejected gateway responses are handled without assuming payment success.

## Logging and privacy

- Debug logging is opt-in and disabled by default.
- Stored log entries are redacted and limited to operational fields such as invoice ID, operation, HTTP status, gateway code, and error code.
- The plugin registers WordPress privacy-policy helper text and personal-data exporter/eraser callbacks.
- Invoice links request no-cache and no-index behavior.
