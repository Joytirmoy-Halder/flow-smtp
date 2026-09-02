# Changelog

All notable changes to FlowSMTP are documented here. This project follows
[Semantic Versioning](https://semver.org/).

## [0.3.2] — 2026-09-02

### Fixed

- **False “no DKIM key found” warning.** The common-selector scan was missing
  `zmail`, the selector Zoho Mail actually publishes, so Zoho domains with a
  valid DKIM key were reported as having none. This was the worst class of
  check failure: it told users their authentication was broken when it was
  correct, inviting them to “fix” working DNS.
- The DMARC failure message passed two arguments into a string using
  unnumbered `%s` placeholders, which cannot be reordered by translators and
  is invalid under the WordPress i18n standards. The accompanying
  `translators:` comment documented one placeholder instead of two.

### Changed

- Added the DKIM selectors used by Zoho (`zmail`), HubSpot (`hs1`, `hs2`),
  Fastmail (`fm1`–`fm3`) and cPanel/Plesk hosts (`dkim`), and annotated every
  entry with the provider that uses it so future additions stay
  evidence-based. Amazon SES is deliberately excluded and documented as such,
  because it issues randomised per-identity tokens that cannot be scanned for.
- The DKIM scan now stops at the first valid key instead of continuing through
  the whole list. Each selector costs up to two DNS lookups, so this keeps the
  check faster than before despite the list growing from 14 to 21 entries;
  only a genuinely missing key pays for a full scan.
- Reworded the DKIM warning so it no longer asserts that DKIM is absent. It
  now explains that FlowSMTP could not find a key under the selectors it knows
  about, and points the user at their DNS or provider dashboard.

## [0.3.1] — 2026-09-02

### Added

- **Automatic plain-text alternative for HTML email.** Messages sent as
  `text/html` with no plain-text part are single-part HTML, which trips
  SpamAssassin's `MIME_HTML_ONLY` rule and scores worse with the large mailbox
  providers. FlowSMTP now derives a plain-text alternative from the HTML body
  at `phpmailer_init`, producing a proper `multipart/alternative` message.
  Contact-form notifications (Elementor, WPForms, Contact Form 7, …),
  WooCommerce email and anything else that calls `wp_mail()` with an HTML body
  benefit without any configuration.
  - Links are rendered as `label (url)` so destinations survive the conversion.
  - Images are dropped, so open-tracking pixels never leak into the text part.
  - List items become `* ` bullets and table cells are joined with ` | `.
  - HTML entities are decoded and runs of whitespace are collapsed.
  - An `AltBody` supplied by the caller is never overwritten.
  - Controlled by the **Add a plain-text alternative to HTML emails** toggle in
    Settings → Sender (on by default), the `FLOWSMTP_AUTO_PLAINTEXT` constant,
    and the `flowsmtp_auto_plaintext` and `flowsmtp_plaintext_body` filters.
    When the constant is defined, the settings screen says so, since the
    constant wins over the stored value at runtime.

### Fixed

- `sanitize_settings()` did not preserve `auto_plaintext`, so saving the
  settings form silently discarded the value and fell back to the default.
- Activation defaults did not seed `auto_plaintext`.

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

- Initial release: SMTP delivery, email logging, failed-email tracking & resend,
  test email system, admin UI.
