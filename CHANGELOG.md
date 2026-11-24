# Changelog

All notable changes to Missivus for Nextcloud. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- ci: the release workflow now creates the Release with GitHub's own gh CLI instead of the third-party softprops/action-gh-release (supply-chain hardening). No change to the plugin.

## [0.1.1] — 2026-08-19

### Fixed

- docs: correct SMTP retirement timeline; copyright headers; contact addresses; stale version
  strings.

## [0.1.0] — 2026-08-18

First release. The third Missivus platform, after
[Matomo](https://github.com/Solvetus/missivus-matomo) and
[WordPress](https://github.com/Solvetus/missivus-wordpress): the same Graph transport class,
vendored byte-identical from missivus-matomo v0.1.4 (which includes the endpoint-URL redaction
fix from its issue #1), behind a Nextcloud-native seam.

### Added

- The seam: Missivus registers a replacement for Nextcloud's `IMailer` service in the server
  container at app boot — a decorator around core's own mailer, so message construction,
  templates and address validation stay exactly Nextcloud's while `send()` goes through
  Microsoft Graph (`sendMail` with application permissions, client credentials, one shared
  mailbox). Core system mail (password resets, share notifications, activity digests, admin
  alerts) and every app that sends through the mailer are covered; a boot failure degrades to
  Nextcloud's own mailer, never to no mail.
- Message conversion with full fidelity: To/Cc/Bcc with display names, Reply-To, HTML and plain
  bodies, regular and inline attachments, and the draft → upload-session path for attachments
  over Graph's ~3 MB inline ceiling. From is always forced to the configured shared mailbox —
  a differing requested sender is kept as Reply-To and logged with both addresses. Headers Graph
  cannot carry (`Auto-Submitted`, `List-Unsubscribe`) are dropped with a warning naming them.
- Client secret and certificate (PS256 client assertion; RS256 escape hatch) authentication;
  tokens cached in Nextcloud's distributed cache with a five-minute safety margin.
- Admin settings section with every setting, a write-only sensitive-flagged secret, an inline
  test-email button (OCS endpoint, admin + CSRF), the most recent delivery failure surfaced on
  the page, and the external-services disclosure.
- `config.php` override tier: a `missivus` array wins over the database, shows as
  "Set in config.php" in the UI, and a file-managed secret is never written to appconfig.
- `occ missivus:test <address>` (sends without the fallback, loud exit 1 with the redacted
  Microsoft error) and `occ missivus:status` (resolved configuration and sources — never
  secrets — plus the last failure).
- Fallback to Nextcloud's own mailer, **off by default**; every Graph failure is logged at error
  level and recorded for the settings page whichever way the switch points.
- Translations: PT-PT, FR, ES, IT (AI-produced, human-reviewed).

### Security

- The issue-#1 lesson applied from day one: every string that reaches a log, an OCS response,
  `occ` output or the settings page passes one final redaction sweep, including error text built
  from operator-supplied base URLs. The test suite drives poisoned `graph_base_url` /
  `login_base_url` values through the real failure path and asserts the credentials embedded in
  them leak nowhere.
- The master switch is stored apart from Nextcloud's own per-app `enabled` flag (which core sets
  to `yes` on app enablement) — caught in the release smoke test on the official Docker image,
  where the app would otherwise have considered itself switched on at install.

### Tests

- 62 PHPUnit tests / 221 assertions against mocked Graph; Psalm clean at the app-template level;
  `php -l` clean on PHP 8.2 (the floor) over every shipped file; smoke-run on the official
  `nextcloud:34.0.3` Docker image — app enabled via `occ`, settings page rendered authenticated,
  `occ missivus:test` failing loudly with the real `AADSTS90002` body, and a core password-reset
  email provably routed through the seam.

[0.1.1]: https://github.com/Solvetus/missivus-nextcloud/releases/tag/v0.1.1
[0.1.0]: https://github.com/Solvetus/missivus-nextcloud/releases/tag/v0.1.0
