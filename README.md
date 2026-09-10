# Easy Invoice for ZarinPal

![WordPress](https://img.shields.io/badge/WordPress-Plugin-blue)
![Version](https://img.shields.io/badge/version-1.5.7-green)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-orange)

A secure WordPress invoice and online payment management plugin with ZarinPal gateway integration and WooCommerce support.

---

## 📌 Overview

**Easy Invoice for ZarinPal** is a secure WordPress invoice and payment management plugin that allows website owners to create custom payment invoices and receive online payments through the ZarinPal payment gateway.

The plugin provides an easy and secure way to:

- Create payment invoices
- Add WooCommerce products
- Generate payment links
- Manage invoice status
- Track successful transactions

---

# ✨ Features

## 🧾 Invoice Management

- Create custom invoices
- Generate unique payment links
- Add customer information
- Track invoice payment status
- Support manual amount invoices
- Manage invoice details from WordPress dashboard


## 🛒 WooCommerce Integration

- Search WooCommerce products
- Add products to invoices
- Manage product quantities
- Automatic invoice total calculation
- Fixed shipping cost support
- Compatible with WooCommerce products


## 💳 ZarinPal Payment Gateway

- ZarinPal API v4 integration
- Secure payment request
- Server-side payment verification
- Transaction reference tracking
- Secure callback validation
- Payment status management


## 🔐 Security

Security is a core part of this plugin:

- WordPress nonce verification
- Capability checks
- Input sanitization
- Output escaping
- Server-side amount validation
- Protection against invoice manipulation
- Secure payment verification flow


---

# 🚀 Installation

## WordPress Dashboard

1. Download the plugin ZIP file

2. Go to:

```
WordPress Dashboard → Plugins → Add New → Upload Plugin
```

3. Upload the plugin ZIP file

4. Activate the plugin


## Manual Installation

Clone repository:

```bash
git clone https://github.com/Tahanoa/easy-zarinpal-invoice.git
```

Move the plugin folder to:

```
wp-content/plugins/
```

Activate the plugin from your WordPress dashboard.

---

# ⚙️ Configuration

After activation, go to:

```
Dashboard → Easy Invoice → Settings
```

Configure:

- ZarinPal Merchant ID
- Invoice page
- Shipping settings
- Logging options


---

# 🧾 Creating an Invoice

Go to:

```
Dashboard → Easy Invoice → Add New Invoice
```

You can:

- Add customer information
- Select WooCommerce products
- Set product quantities
- Add shipping cost
- Generate payment links


---

# 🔌 Shortcode

Display invoice payment page:

```text
[ezinv_invoice]
```


---

# 🔒 Payment Security Flow

```
Customer
   |
   ↓
Invoice Link
   |
   ↓
ZarinPal Payment
   |
   ↓
Callback Validation
   |
   ↓
Server Verification
   |
   ↓
Payment Completed
```

The plugin does not trust browser responses alone.

Successful payments are confirmed only after server-side verification with ZarinPal.


---

# 📦 Requirements

- WordPress 6.x+
- PHP 7.4+
- WooCommerce (optional)
- ZarinPal Merchant Account


---

# 🧪 Tested With

- WordPress latest versions
- WooCommerce integration
- ZarinPal API v4


---

# 📝 Changelog

## 1.5.7

- Improved WordPress.org compatibility
- Improved security hardening
- Improved request handling
- Improved input validation
- Improved code quality
- Better Plugin Check compatibility


## 1.5.6

- Added secure request handling layer
- Improved validation workflow
- Improved security checks


## 1.5.5

- Improved WordPress security standards
- Improved code structure


## 1.5.0

- Added WooCommerce product support
- Added invoice management improvements
- Prepared plugin structure for WordPress.org


---

# 🤝 Contributing

Contributions, bug reports, and feature requests are welcome.

Please open an issue or submit a pull request.

Development workflow:

```bash
git checkout -b feature/new-feature
git commit -m "Add new feature"
git push origin feature/new-feature
```


---

# 🛡 Security

If you discover a security issue, please report it responsibly.

Please contact the maintainer before publicly disclosing security vulnerabilities.


---

# 📄 License

GPL-2.0-or-later


---

# 👤 Author

**Taha Farzaneh**

GitHub:

https://github.com/Tahanoa
