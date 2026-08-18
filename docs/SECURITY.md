# Security review — Missivus for Nextcloud

Standing review, last updated **2026-08-18** (v0.1.0). Scope: this app's code and configuration
surface. The vendored transport (`lib/Vendor/Solvetus/Missivus/`) inherits the review of its
upstream, [missivus-matomo `docs/SECURITY.md`](https://github.com/Solvetus/missivus-matomo/blob/main/docs/SECURITY.md);
its findings 1–3a and 12 apply here unchanged and are not repeated in full.

Report vulnerabilities to `security@missivus.com`. Please do not open a public issue until a fix
is out.

| # | Finding | Status |
| --- | --- | --- |
| 1 | Credentials in requests to Microsoft only, always over verified HTTPS | Inherited, verified |
| 2 | Token cached with expiry margin; never logged | Inherited, verified |
| 3 | Three-layer redaction incl. operator-URL error text (issue-#1 lesson) | Inherited, verified + covered by this app's own tests |
| 4 | Admin-only settings and endpoints | Enforced |
| 5 | CSRF on every endpoint | Enforced |
| 6 | Secrets write-only, sensitive-flagged, never redisplayed | Enforced |
| 7 | config.php-managed values never written to the database | Enforced |
| 8 | Output escaping in templates and results | Enforced |
| 9 | Token residency in the distributed cache | Accepted, documented |
| 10 | Secret at rest in appconfig | Mitigated, not eliminated |
| 11 | Unthrottled admin test emails | Accepted, documented |

## Enforced and verified

**4 — Admin only.** Both OCS endpoints (`saveSettings`, `testEmail`) carry neither
`#[NoAdminRequired]` nor `#[PublicPage]`, so Nextcloud's AppFramework default — full admin — is in
force; the settings page is registered through `<settings><admin>`, which Nextcloud only renders
for admins. A unit test pins by reflection that no opt-out attribute ever appears on those methods.

**5 — CSRF.** Neither endpoint carries `#[NoCSRFRequired]`; Nextcloud's request-token check applies
to every call. The settings JavaScript sends the token; the same reflection test pins the absence
of the opt-out.

**6 — Write-only secrets.** The client secret and certificate passphrase are stored through
`IAppConfig` with `sensitive: true` (encrypted at rest, excluded from `occ config:list` dumps).
They are never sent to the page — the template renders empty password inputs and only a
"Saved — enter a new value to replace it" hint; `occ missivus:status` prints `set`/`(not set)`,
never a value. An empty submission keeps the stored value; a value with whitespace/control
characters or over 1024 bytes is rejected with a message that does not echo it.

**7 — config.php tier.** A value in the `missivus` array of `config.php` wins over the database,
renders disabled ("Set in config.php"), and its posted value is *discarded* on save — a
file-managed secret can never be copied into appconfig by a UI action. Covered by tests.

**8 — Escaping.** Templates print through Nextcloud's `p()` (HTML-escaped); the one deliberate
`print_unescaped` carries only a fixed translated string with a fixed anchor. The settings
JavaScript writes results with `textContent` only — a Microsoft error body is never interpreted as
markup.

**3 (this app's share) — Redaction.** Every string that can leave the process — log lines, the OCS
test response, `occ` output, the settings-page failure notice — passes the transport's `Redactor`
as a final pass, with the current credential literals loaded. The suite drives poisoned
`graph_base_url`/`login_base_url` values through the real failure path and asserts the embedded
credentials reach neither the log, the exception, nor the recorded failure; an Entra body echoing
the configured secret is redacted everywhere.

## Accepted risks, with reasons

**9 — Token in the distributed cache.** Access tokens live in Nextcloud's distributed cache
(Redis/APCu/whatever the admin configured) for their lifetime minus a margin. Anyone who can read
that cache can send mail as the shared mailbox for up to an hour — but such an attacker can
already read Nextcloud's config and secrets, so the cache adds no new trust boundary. Fetching a
token per message would double Microsoft round-trips for no real gain.

**10 — Secret at rest.** The secret is stored via `IAppConfig` `sensitive`, which Nextcloud
encrypts with the instance secret in `config.php`. An attacker with both the database and
`config.php` can recover it — the same class of attacker who owns the instance outright. Operators
who want the secret out of the database entirely use the `config.php` tier (finding 7) or the
certificate method with a root-owned PEM.

**11 — Unthrottled test emails.** The test endpoint sends a real email per click and is not rate
limited. It is admin-only and CSRF-protected; an admin who wants to send email quickly has `occ`
anyway. Not worth a throttle's complexity.

## The Nextcloud seam, from a security angle

The app replaces the `IMailer` service in the server container from `boot()`. That is a
capability every enabled app's code path has (apps run with server-side privileges in-process);
Missivus does not widen it. The replacement delegates message construction and validation to
core's own mailer, so no parsing surface is re-implemented. If the boot wiring fails for any
reason, the error is logged and core's mailer remains untouched — failure degrades to "mail works
as before", never to "no mail" and never to "mail through an unexpected path".

## Not in scope

- Compromise of the Microsoft tenant or the Entra app registration itself.
- A hostile Nextcloud administrator: an admin can already read `config.php` and send mail.
- The delivery fate of a message after Graph returns 202.
