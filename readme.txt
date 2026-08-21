=== FlowSMTP ===
Contributors: joytirmoyhalder
Tags: smtp, email, mail, email log, wp_mail
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
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

== Changelog ==

= 0.1.0 =
* Initial release: SMTP delivery, email logging, failed-email tracking & resend, test email system, modern admin UI.
