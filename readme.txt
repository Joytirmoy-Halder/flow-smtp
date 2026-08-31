=== FlowSMTP ===
Contributors: joytirmoyhalder
Tags: smtp, email, mail, email log, wp_mail
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modern SMTP mailer for WordPress with email logs, failed-email tracking & resend, and a built-in test email system.

== Description ==

FlowSMTP routes all WordPress email through your SMTP server and gives you full visibility into what was sent, what failed and why.

* SMTP delivery with TLS/SSL and authentication
* Complete email log (recipient, subject, body, headers, status)
* Failed emails tab with exact error messages and one-click resend
* Built-in HTML / plain-text test email system
* Force From email & name across all plugins
* Configurable log retention with automatic daily cleanup
* Encrypted credential storage, with optional wp-config.php constants
* Password reset emails are always redacted from the log
* Clean, modern admin interface

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via the Plugins screen.
2. Activate the plugin.
3. Configure your SMTP server under FlowSMTP → Settings.
4. Send a test email under FlowSMTP → Send Test.

== Frequently Asked Questions ==

= Does it log emails sent by other plugins? =
Yes. Anything that uses `wp_mail()` (WooCommerce, contact forms, core notifications) is logged.

= Can I resend a failed email? =
Yes — open Failed Emails and click Resend. The retry count is tracked per email.

= How is my SMTP password stored? =
Encrypted with libsodium (or AES-256-GCM). For maximum security, define `FLOWSMTP_SMTP_PASSWORD` in `wp-config.php` and it never touches the database.

== Changelog ==

= 0.2.0 =
* Security: real authenticated encryption for the SMTP password (libsodium / AES-256-GCM) with legacy migration.
* Security: support `FLOWSMTP_SMTP_PASSWORD` and `FLOWSMTP_ENCRYPTION_KEY` constants in wp-config.php.
* Security: password reset email bodies are always redacted from the log.
* Security: new “log full email bodies” toggle for metadata-only logging.
* Security: log viewer renders email bodies in a sandboxed iframe.
* Fixed: test flag no longer leaks onto other emails sent in the same request.
* Fixed: emails short-circuited by other plugins (pre_wp_mail) no longer stay “pending”.
* Fixed: nested wp_mail() calls now resolve to the correct log rows.
* Fixed: search term is preserved when paginating logs.
* Improved: HTML test email now includes a plain-text alternative (better spam scores).

= 0.1.0 =
* Initial release: SMTP delivery, email logging, failed-email tracking & resend, test email system, modern admin UI.
