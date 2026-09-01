# Changelog

All notable changes to FlowSMTP are documented here. This project follows
[Semantic Versioning](https://semver.org/).

## [0.3.0] — 2026-09-02

Fifteen features shipped one per pull request into `develop`, plus a UI polish pass.

### Added

| # | Feature | PR |
| --- | --- | --- |
| 1 | Provider presets (Gmail, Outlook, SendGrid, Mailgun, Brevo, Postmark, SES, Zoho…) with autofill and setup links | #3 |
| 2 | Automatic retry queue with exponential backoff | #4 |
| 3 | Deliverability tab: SPF, DKIM, DMARC, MX checks and From-alignment warnings | #5 |
| 4 | Failure alerts by email and Slack/Discord webhook, throttled to one per 5 minutes | #6 |
| 5 | CSV export of the email log, safe against spreadsheet formula injection | #7 |
| 6 | Overview dashboard: 14-day stacked delivery chart, success rate, top errors | #8 |
| 7 | HTTP API sending for SendGrid, Mailgun and Brevo | #9 |
| 8 | Fallback SMTP connection, tried immediately when the primary route fails | #10 |
| 9 | Attachment details in the log table and log viewer | #11 |
| 10 | Email preview with rendered/source toggle and “send a copy to” | #12 |
| 11 | Test Mode: intercept outgoing email on staging sites, with allowlist | #13 |
| 12 | Open & click tracking with signed redirects and no personal data stored | #14 |
| 13 | Multisite support with optional network-enforced connection settings | #15 |
| 14 | WP-CLI commands: `test`, `status`, `logs`, `resend`, `cleanup`, `delete`, `check` | #16 |
| 15 | Uninstall data-retention choice (keep by default) | #17 |

### Changed

- Database schema is now versioned and upgraded automatically on `plugins_loaded`
  (`flowsmtp_db_version`), adding tracking columns to the email log table.
- Settings are filterable via `flowsmtp_settings`, which the multisite component
  uses to enforce network-wide connection settings.
- Form textareas match the WordPress-native field styling (#18).
- The log viewer surfaces open/click counts and the last clicked URL (#18).

### Security

- API keys are stored encrypted, and can be supplied via the `FLOWSMTP_API_KEY`
  constant so they never touch the database.
- Click-tracking redirects are HMAC-signed and verified with `hash_equals()`;
  invalid signatures redirect to the home page instead of the supplied URL.

## [0.2.0]

### Security

- Authenticated encryption for stored credentials (libsodium, AES-256-GCM
  fallback) with transparent migration from the 0.1.0 scheme.
- `FLOWSMTP_SMTP_PASSWORD` and `FLOWSMTP_ENCRYPTION_KEY` wp-config constants.
- Password reset email bodies are always redacted from the log.
- Optional metadata-only logging via the “log full email bodies” toggle.
- Email bodies are previewed inside a sandboxed iframe.

### Fixed

- Test flag leaking onto other emails sent in the same request.
- Emails short-circuited via `pre_wp_mail` staying stuck in “pending”.
- Nested `wp_mail()` calls resolving to the wrong log row.
- Search term lost when paginating the log.

### Changed

- Flat, WordPress-native admin UI.
- HTML test emails include a plain-text alternative for better spam scores.

## [0.1.0]

- Initial release: SMTP delivery, email logging, failed-email tracking and
  resend, test email system, admin UI.
