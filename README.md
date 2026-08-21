# FlowSMTP

A modern SMTP plugin for WordPress with **email logging**, **failed-email tracking & resend**, and a **built-in test email system** — wrapped in a sleek, clean admin UI.

![Version](https://img.shields.io/badge/version-0.1.0-4f46e5) ![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b) ![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb3) ![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green)

## ✨ Features

- **SMTP delivery** — routes all `wp_mail()` traffic through your SMTP server via `phpmailer_init` (host, port, TLS/SSL/none, authentication).
- **Email logs** — every outgoing email is recorded in a custom database table with recipient, subject, body, headers, attachments and timestamps.
- **Failed emails** — a dedicated tab listing failed sends with the exact SMTP error message, plus one-click **Resend** with retry counting.
- **Test email system** — send an HTML or plain-text test email from the admin and see the real server response instantly.
- **Force From address/name** — override the default `wordpress@` sender across all plugins.
- **Log retention** — automatic daily cleanup after a configurable number of days (0 = keep forever).
- **Dashboard stats** — sent / failed / pending counters in the header.
- **Modern UI** — gradient header, pill badges, toggle switches, searchable log table, detail modal, pagination.
- **Security minded** — capability checks, nonces on every AJAX action, sanitized inputs, prepared SQL statements, obfuscated stored password.

## 📦 Installation

1. Download this repository as a ZIP (or clone it into `wp-content/plugins/flow-smtp`).
2. Activate **FlowSMTP** from *Plugins* in wp-admin.
3. Go to **FlowSMTP → Settings**, enter your SMTP credentials and save.
4. Open **FlowSMTP → Send Test** and send yourself a test email.

## 🗂 Structure

```
flow-smtp/
├── flow-smtp.php                   # Bootstrap, constants, activation (log table)
├── uninstall.php                   # Removes options + log table on uninstall
├── includes/
│   ├── class-flowsmtp.php          # Core loader / settings accessor
│   ├── class-flowsmtp-mailer.php   # phpmailer_init SMTP config, From filters, test email
│   ├── class-flowsmtp-logger.php   # Log table CRUD, failure capture, resend, retention
│   └── class-flowsmtp-admin.php    # Admin page (Settings / Logs / Failed / Test) + AJAX
└── assets/
    ├── css/admin.css               # Modern admin styling
    └── js/admin.js                 # Test email, resend, view modal, bulk delete
```

## 🔒 Security notes

- The SMTP password is stored obfuscated (salt-XOR + base64), not in plain text. For maximum security, define credentials in `wp-config.php` and filter the option instead.
- All admin actions require `manage_options` and a valid nonce.
- All queries use `$wpdb->prepare()`.

## 🧭 Roadmap

- [ ] Provider presets (Gmail, Outlook, SendGrid, Mailgun, Brevo…)
- [ ] Weekly email delivery summary
- [ ] Automatic retry queue for failed emails (cron-based)
- [ ] Export logs as CSV
- [ ] Multisite support

## 📄 License

GPL-2.0-or-later. Built by [Joytirmoy Halder Joyti](https://github.com/Joytirmoy-Halder).
