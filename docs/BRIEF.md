# Missivus for Nextcloud — Build Brief

The spec. `/goal` conditions reference this file. Read it fully, then read the two sibling repos
end to end — `/Users/rds/Projects/Solvetus/missivus-matomo` (origin of the transport class, its
tests, docs voice) and `/Users/rds/Projects/Solvetus/missivus-wordpress` (the reference for
"same product on a second platform": vendoring, adapters, docs set, build script, release
shape). Missivus for Nextcloud is the third platform: **same Graph transport class vendored
unchanged, same decisions, same documentation voice.**

## What it is

A free GPLv3 (AGPLv3 if the Nextcloud App Store requires it — verify; if AGPL is required, use
AGPLv3 and record why) Nextcloud app (Solvetus Labs, family site https://missivus.com) that
sends all Nextcloud outbound email — share notifications, password resets, activity digests,
admin alerts, Talk/Calendar/Mail-app-independent system mail — through Microsoft Graph using
**application permissions and a shared mailbox**: client credentials, `Mail.Send` application
permission, Exchange application access policy scoped to ONE shared mailbox; no user login, no
delegated OAuth, nothing to click. Nextcloud today offers SMTP/sendmail only; Microsoft is
retiring basic-auth SMTP AUTH on a schedule (disabled by default for existing tenants from the end
of December 2026, unavailable to new tenants from 2027, final removal to follow in H2 2027), not
already gone. Business model: app free, Solvetus sells installation and support.

## Due diligence first (before any design)

Search the Nextcloud App Store and GitHub for an existing app that sends Nextcloud system mail
via Microsoft Graph with application permissions. If one exists and works that way, STOP and
report; do not build a duplicate. Note apps that use delegated OAuth or SMTP-OAuth as competitors
in the docs (that is the differentiator, as with WordPress).

## Repo

`Solvetus/missivus-nextcloud` (public GitHub — create it with `gh repo create Solvetus/missivus-nextcloud --public`
if absent). Local folder is this one. App id `missivus`, namespace `OCA\Missivus\`. Initialise
git here, remote `git@github.com:Solvetus/missivus-nextcloud.git`, branch `main`. Commit identity
comes from the `~/.gitconfig` includeIf for `/Users/rds/Projects/Solvetus/`.

## Read first (Nextcloud side)

The current stable major's `lib/private/Mail/Mailer.php` and `OCP\Mail\IMailer`, `IMessage`,
`IEMailTemplate`, `OCP\Mail\Events\BeforeMessageSent`; how `IMailer` is registered in the server
container; the app bootstrap API (`IBootstrap`, `IRegistrationContext`), admin settings
(`ISettings`, `IIconSection`), `IAppConfig` incl. the sensitive/lazy flags in recent majors,
`IClientService`, `ICacheFactory`, `LoggerInterface`, OCS controllers with `#[AuthorizedAdminSetting]`
/ `#[NoCSRFRequired]` semantics, l10n, `appinfo/info.xml` schema, App Store publishing and code
signing requirements, `occ` commands. Support the two most recent stable majors; PHP floor = the
older of those two majors' minimum.

## The seam — decide empirically, do not guess

Nextcloud has no official "replace the mail transport" extension point. Evaluate, in this order,
and pick the first that works cleanly on both supported majors, recording the reasoning and the
exact internals depended on in PLAN.md:
1. Registering a replacement `IMailer` implementation so that core and all apps resolve it
   (server-container-level registration from the app bootstrap, if it takes effect globally and
   survives the app being disabled/enabled cleanly).
2. Decorating the existing mailer: wrap core's `IMailer` and delegate `createMessage`,
   `createEMailTemplate`, `validateMailAddress` to it, override `send()` to go through Graph.
3. If neither can be made global, STOP and report with evidence — do not ship an app that only
   catches some mail.
Whatever the seam: converting `IMessage`/Symfony `Email` to the Graph payload must preserve
HTML + plain parts, From name, Reply-To, To/Cc/Bcc, attachments (inline path up to Graph's
ceiling ~3 MB — verify — and the draft→uploadSession→send path above it), and headers that
matter (Auto-Submitted, List-Unsubscribe if present).

## Design goals (decided — same as Matomo/WordPress, do not re-open)

- Auth: client credentials; secret AND certificate; UI default secret; token cached (distributed
  cache via `ICacheFactory`, safety margin).
- Forced From = configured sender mailbox; warn when the original From differed. Docs tell admins
  to set `mail_from_address`/`mail_domain` to the shared mailbox.
- Fallback to Nextcloud's own mailer: supported, default OFF; failures logged at error level via
  the app logger, never swallowed; the failure surfaces in the admin settings page.
- Admin settings section "Missivus" (admin only): enable, Directory (tenant) ID, Application
  (client) ID, auth method, client secret (write-only, sensitive appconfig, never redisplayed),
  certificate path + passphrase, sender mailbox, save-to-Sent, fallback, "Send test email"
  (OCS endpoint, admin + CSRF, result inline with the Microsoft error body). Every value
  overridable via `config.php` keys under a `missivus` array (a config.php value wins, UI shows
  "set in config.php", secret never written to appconfig when defined there). Also `occ
  missivus:test <address>` and `occ missivus:status`.
- Portability boundary: vendor the transport class from `missivus-matomo` **unchanged** into
  `lib/Vendor/`; Nextcloud adapters wrap `IClientService` and `ICacheFactory`. If the class needs
  any change, STOP and report — the fix goes upstream first.
- Zero third-party runtime dependencies. No Composer autoload in the shipped tarball beyond
  Nextcloud's own app autoloader (`composer/autoload` per app conventions if that is the standard;
  follow the app template exactly).
- Security: admin checks, CSRF, sanitise/escape, secrets never in logs/HTML/JS/OCS responses,
  the issue-#1 lesson applied from day one (error text built from operator URLs is described,
  never echoed; final redaction pass over every logged/returned string). `docs/SECURITY.md`
  mirrors the siblings plus a Nextcloud section.
- Privacy disclosure: the app talks to `login.microsoftonline.com` and `graph.microsoft.com` —
  state it in `appinfo/info.xml` description, README and settings page.
- i18n: `l10n/` with EN + PT-PT/FR/ES/IT (AI-produced, human-reviewed line in README).
- Tests: PHPUnit with mocked Graph covering send happy path, message conversion (from/reply-to/
  cc/bcc/html+plain/attachments inline+large), forced-From warning, fallback OFF/ON, config.php
  overrides, secret redaction incl. poisoned base-URL values, admin/CSRF on the OCS endpoint;
  psalm or phpstan at the app-template level; `php -l` at the PHP floor. Plus a real smoke run:
  the official `nextcloud` Docker image (latest stable), `occ app:enable missivus`, admin settings
  page renders, `occ missivus:test` with placeholder credentials fails loudly, never silently.
- Docs: `README.md` (opens with the mark from missivus-www `public/brand/missivus-mark.svg` copied
  into `.github/`), `docs/INSTALL.md` (same Entra + Exchange Online guide as the siblings;
  PowerShell blocks identical), `docs/faq.md`, `docs/SECURITY.md`, `CONTRIBUTING.md`, `LICENSE`,
  `CHANGELOG.md`, `.github/social-preview.png` (sibling style, "for Nextcloud"), App Store
  metadata in `appinfo/info.xml` (name, summary, description, categories, screenshots list the
  owner will supply, repository/bugs/website links, `<dependencies>` with nextcloud min/max and
  php).
- Build: `tools/build-tarball.sh` (allowlist) → `dist/missivus-<version>.tar.gz` in the App
  Store layout; note that App Store publication requires an app signing certificate the owner
  requests from Nextcloud — document the exact steps for the owner, do NOT publish.
- Do NOT touch any live server. Version 0.1.0.

## House rules

Secrets: never invent, print or commit. Latest versions. Small logical commits. One tasteful
line pointing to Solvetus for installation and support. Vault log line at the end to
`/Users/rds/Library/Mobile Documents/iCloud~md~obsidian/Documents/Omni/30_Projects/Missivus/Missivus.md`.
