=== FlowSMTP ===
Contributors: joytirmoyhalder
Tags: smtp, email, mail, email log, wp_mail
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SMTP and API email delivery for WordPress with email logs, failed-email tracking, retries, deliverability checks and a built-in test email system.

== Description ==

FlowSMTP routes all WordPress email through your SMTP server or your provider's HTTP API, and gives you full visibility into what was sent, what failed and why.

**Delivery**

* SMTP delivery with TLS/SSL and authentication
* HTTP API sending for SendGrid, Mailgun and Brevo — works even when your host blocks SMTP ports
* One-click provider presets (Gmail, Outlook, SendGrid, Mailgun, Brevo, Postmark, Amazon SES, Zoho…)
* Fallback SMTP connection: if the primary route fails, the email is immediately retried through a backup provider
* Automatic background retries with exponential backoff (5, 10, 20 minutes…)
* Force From email & name across all plugins

**Logging & auditing**

* Complete email log: recipient, subject, body, headers, attachments, status, error
* Failed Emails tab with exact error messages and one-click resend
* Log viewer with rendered / source preview, attachment details and “send a copy to”
* CSV export of any filtered view (formula-injection safe)
* Configurable retention with automatic daily cleanup

**Insight**

* Overview dashboard: 14-day stacked delivery chart, success rate and most frequent errors
* Deliverability tab: SPF, DKIM, DMARC and MX checks with From-alignment warnings
* Optional open & click tracking (signed redirects, no IP or user-agent stored)
* Failure alerts by email and/or Slack/Discord webhook

**Operations**

* Test Mode: intercept all outgoing email on staging sites, with an allowlist
* Multisite / network support with optional network-enforced connection settings
* WP-CLI commands: `wp flowsmtp test|status|logs|resend|cleanup|delete|check`
* Choose whether to keep or delete your data when the plugin is uninstalled

**Security**

* Credentials encrypted with libsodium (or AES-256-GCM)
* Optional `FLOWSMTP_SMTP_PASSWORD`, `FLOWSMTP_API_KEY` and `FLOWSMTP_ENCRYPTION_KEY` constants
* Password reset emails are always redacted from the log
* Email bodies are previewed inside a sandboxed iframe

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via the Plugins screen.
2. Activate the plugin.
3. Configure your SMTP server or API key under FlowSMTP → Settings.
4. Send a test email under FlowSMTP → Send Test.
5. Run the domain health check under FlowSMTP → Deliverability.

== Frequently Asked Questions ==

= Does it log emails sent by other plugins? =
Yes. Anything that uses `wp_mail()` (WooCommerce, contact forms, core notifications) is logged.

= Can I resend a failed email? =
Yes — open Failed Emails and click Resend. The retry count is tracked per email. Failed emails are also retried automatically in the background.

= My host blocks SMTP ports. Can I still use this? =
Yes. Switch the sending method to HTTP API and use a SendGrid, Mailgun or Brevo API key.

= Will my emails land in spam? =
That depends mostly on DNS, not on the plugin. Use the Deliverability tab to verify SPF, DKIM and DMARC, and make sure your From domain matches the domain your provider signs.

= How is my SMTP password stored? =
Encrypted with libsodium (or AES-256-GCM). For maximum security, define `FLOWSMTP_SMTP_PASSWORD` in `wp-config.php` and it never touches the database.

= What happens to my logs if I delete the plugin? =
Nothing, by default. Data removal is opt-in via Settings → Uninstall.

== Changelog ==

= 0.3.0 =
* New: provider presets with one-click SMTP autofill and setup links.
* New: HTTP API sending for SendGrid, Mailgun and Brevo.
* New: fallback SMTP connection used automatically when the primary route fails.
* New: automatic retry queue with exponential backoff.
* New: overview dashboard with a 14-day delivery chart, success rate and top errors.
* New: deliverability tab with SPF, DKIM, DMARC and MX checks plus From-alignment warnings.
* New: failure alerts by email and Slack/Discord webhook (throttled).
* New: CSV export of the email log, safe against spreadsheet formula injection.
* New: attachment details in the log table and log viewer.
* New: email preview with rendered/source toggle and “send a copy to”.
* New: Test Mode to intercept outgoing email on staging sites, with allowlist support.
* New: optional open & click tracking with signed redirects and no personal data stored.
* New: multisite support, including network-enforced connection settings.
* New: WP-CLI commands (`test`, `status`, `logs`, `resend`, `cleanup`, `delete`, `check`).
* New: choose whether to keep or delete plugin data on uninstall (keep is the default).
* Improved: flat, WordPress-native admin UI.

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

== Upgrade Notice ==

= 0.3.0 =
Adds API sending, fallback delivery, retries, tracking, multisite, WP-CLI and more. The email log table is upgraded automatically on first load.
