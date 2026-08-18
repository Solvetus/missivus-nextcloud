# Missivus for Nextcloud — Implementation Plan

The spec is `docs/BRIEF.md`. Everything marked **(verified)** below was checked empirically against
the cited source — the two supported Nextcloud stable branches, the App Store schema, or the sibling
repositories — not recalled from memory. Sibling repos: `missivus-matomo` (upstream of the vendored
transport, v0.1.4) and `missivus-wordpress` (the reference port, v0.1.1).

## 0. Due diligence (done before any design, 2026-08-18)

Searched for an existing Nextcloud app that sends Nextcloud **system** mail (password resets, share
notifications, admin alerts) through Microsoft Graph with **application** permissions:

- **App Store**: full dump of `apps.nextcloud.com/api/v1/apps.json` (743 apps) keyword-scanned for
  graph/msgraph/mailer/smtp/oauth/office/outlook/exchange/365/sendmail/Mail.Send/entra/azure, plus a
  category cross-check. Nothing replaces the system mailer. The official **Mail** app and
  x2mail/SnappyMail/Roundcube are webmail clients; **integration_ews** syncs data; Sendent is an
  Outlook add-in ecosystem; mailnotifier/gpgmailer/twofactor_email *consume* `IMailer`.
- **GitHub**: repo and code searches ("nextcloud msgraph", "nextcloud IMailer graph",
  `"Mail.Send" nextcloud "client credentials"`, `topic:nextcloud-app mail`, …). The only hits are
  **external SMTP→Graph relay daemons** (SMTP2Graph, JustinIven/smtp-oauth-relay,
  ggpwnkthx/Microsoft-Graph-SMTP-Relay) — separate services a Nextcloud points SMTP at. Those are the
  competitors, not duplicates.
- **Core**: outgoing system mail supports `smtp`/`sendmail`/`qmail` only; no OAuth token
  acquisition exists. nextcloud/server#21595 ("Use oauth2 for basic email server settings") has been
  open since June 2020 with no assignee and unanswered "any news?" comments as late as 2026-03.
  The admin manual explicitly warns that Microsoft has discontinued Basic authentication.

**Verdict: no duplicate exists. Build.** Delegated-OAuth relays and webmail apps are listed as
competitors in the README comparison table, as with WordPress.

## 1. What this app does

One seam: replace the object behind `OCP\Mail\IMailer` in the server container with a decorator that
sends through Microsoft Graph (`POST /users/{sender}/sendMail`, client credentials, one shared
mailbox). Everything else — message construction, templates, address validation, every app and core
caller — stays Nextcloud's own. Install must never break mail: the master switch ships **off**, and
while off the decorator delegates every call to core's mailer untouched.

## 2. Supported platforms **(verified)**

| | Nextcloud 33 | Nextcloud 34 |
|---|---|---|
| Latest release | 33.0.8 | 34.0.3 (current stable) |
| Branch checked | `stable33` | `stable34` |
| PHP floor | 8.2 (`lib/versioncheck.php`: `PHP_VERSION_ID < 80200` dies) | same |
| PHP ceiling | < 8.6 | same |

`appinfo/info.xml`: `<nextcloud min-version="33" max-version="34"/>`, `<php min-version="8.2"
max-version="8.5"/>`. **PHP floor for all shipped code: 8.2** (the vendored transport is 7.2-clean,
which satisfies it). `php -l` gate runs in a `php:8.2-cli` container.

Licence **(verified)**: the App Store schema (`https://apps.nextcloud.com/schema/apps/info.xsd`)
enumerates `GPL-3.0-or-later` among allowed `<licence>` values (SPDX values require min-version ≥ 31;
ours is 33). **GPLv3 stands — no AGPL switch required.** `<licence>GPL-3.0-or-later</licence>`.

## 3. The seam — decision and evidence

**Decision: brief option 2 — decorate core's mailer, registered as a replacement `IMailer` service
in the *server* container from the app's `boot()`.** Option 1 (registering a from-scratch
replacement implementation) was rejected because core's `Mailer` also owns `createMessage`,
`createEMailTemplate`, attachment factories and validation — re-implementing those buys nothing and
loses theming/template behaviour. The decorator delegates all of that to the real mailer and
overrides only `send()`.

Evidence, quoted from both branches:

1. **`IMailer` is a lazy service in the server container** — `lib/private/Server.php`
   (stable33 L901, stable34 L936, identical):
   ```php
   $this->registerService(IMailer::class, function (Server $c) {
       return new Mailer(...
   ```
2. **Re-registration replaces, by design** — `OC\AppFramework\Utility\SimpleContainer::registerService`
   (stable33 L184, stable34 functionally identical; `ServerContainer extends SimpleContainer` and
   does not override it):
   ```php
   public function registerService($name, Closure $closure, $shared = true) {
       ...
       if (isset($this->container[$name])) {
           unset($this->container[$name]);   // drops a cached instance too
       }
       ...
   ```
3. **The API to reach the server container from an app is public** —
   `OCP\AppFramework\Bootstrap\IBootContext::getServerContainer(): IServerContainer` (since 20.0.0,
   present on both branches), and `OCP\IContainer::registerService($name, Closure, $shared = true)`
   (since 6.0.0, still present on both; deprecated in favour of the *app*-container registration
   context, which is precisely what it is not — see 5).
4. **Core resolves `IMailer` from the server container at use time**, after all apps have booted:
   password reset (`core/Controller/LostController.php` L69 injects `IMailer`, L286
   `$this->mailer->send($message)`), share notifications
   (`apps/sharebymail/lib/ShareByMailProvider.php` L66/L403/L499), and every other consumer found.
   Constructor injection into controllers happens at request dispatch, after `boot()`.
5. **App containers fall through to the server container** —
   `OC\AppFramework\DependencyInjection\DIContainer::query` (stable33 L314):
   ```php
   $isServerClass = str_starts_with($name, 'OCP\\') || str_starts_with($name, 'OC\\');
   if ($isServerClass && !$this->has($name)) {
       return $server->query($name, $autoload, $chain);
   ```
   So **app-originated mail resolves the same overridden service** unless an app registered its own
   private `IMailer` (none found; that would be that app's deliberate choice). By contrast,
   `IRegistrationContext::registerService()` registers into the app's own container only — which is
   why the override must go through `getServerContainer()`.
6. **Prior art** — the shipped app LibreCodeCoop/custom_domain replaces server-container services
   from app code the same way:
   ```php
   \OC::$server->registerService(\OCP\IConfig::class, function () {
       return new ConfigOverwriteNC28(\OC::$configDir);
   });
   ```
   (`custom_domain/lib/AppInfo/Application.php`); ChristophWurst/nextcloud_sentry wires decorators
   over its reporter service the same shape.
7. **`BeforeMessageSent` cannot carry this** — `OCP\Mail\Events\BeforeMessageSent` is a plain
   `Event` wrapper with `getMessage()`; no cancel, no transport swap **(verified, both branches)**.
   The **Mail Provider API** (`OCP\Mail\Provider\*`, NC 30+) is for apps consuming *user* mailbox
   services (e.g. calendar invitations through the Mail app); no core system-mail path touches it
   **(verified: docs + no consumer in `LostController`/`sharebymail`)**.
8. **Disable/enable is clean**: the override lives only in memory, re-applied (or not) on each
   request's boot phase. Disabling the app removes the boot hook; the next request runs core's own
   factory registration untouched. Nothing is persisted.

Boot mechanics:

```php
public function boot(IBootContext $context): void {
    $server = $context->getServerContainer();
    $inner = $server->get(IMailer::class);          // core's Mailer, built by core's own factory
    if ($inner instanceof GraphMailer) { return; }  // idempotent
    $appContainer = $context->getAppContainer();
    $server->registerService(IMailer::class,
        fn () => $appContainer->get(MailerFactory::class)->decorate($inner));
}
```

Resolving core's mailer first and capturing it means we never duplicate core's constructor wiring
(`Mailer`'s constructor takes an `IL10N` built with `getL10N('lib')`, which autowiring cannot
produce) and never drift when its signature changes between majors. The whole `boot()` body is
wrapped in try/catch-log — a Missivus bug must degrade to "mail works as before", never to "no mail".

Timing: apps boot in `OC_App::loadApps()` before request dispatch (web) and before job execution
(cron), and `IMailer` is lazy — nothing constructs it during the boot phase in core. Even if
something did, `registerService` unsets the cached instance, so every later `get()` returns ours;
only an object that had the core mailer constructor-injected *during another app's boot and sends
mail with it later* could bypass the seam. No such consumer exists in core or in any app surveyed.

**Failure demanded by the brief if no global seam existed:** not the case — the evidence above shows
one seam covering core and app mail on both majors. Recorded here per brief; no STOP.

## 4. Architecture

Two layers, split on the portability boundary, mirroring WordPress:

### 4.1 Vendored transport (`lib/Vendor/Solvetus/Missivus/`, namespace `Solvetus\Missivus\`)

Copied **byte-identical** from `missivus-matomo/libs/Solvetus/Missivus/` at v0.1.4 (includes the
issue-#1 endpoint-redaction fix). 14 files: `GraphMailer`, `Message`, `Attachment`, `Endpoint`,
`Redactor`, `Auth/{Credentials,TokenProvider,ClientAssertion}`,
`Contract/{HttpClientInterface,HttpResponse,TokenCacheInterface,LoggerInterface,NullLogger}`,
`Exception/GraphException`. The release gate runs
`diff -r missivus-matomo/libs/Solvetus/Missivus lib/Vendor/Solvetus/Missivus` and requires empty
output. There is no Nextcloud fork of the transport, ever; fixes go upstream first. Loaded by a
12-line `spl_autoload_register` in `Application` (Nextcloud's app autoloader only maps
`OCA\Missivus\` → `lib/`); no Composer autoloader ships.

### 4.2 Nextcloud layer (`lib/`, namespace `OCA\Missivus\`)

| Path | Class | Job |
|---|---|---|
| `AppInfo/Application.php` | `Application` | `IBootstrap`; vendored autoloader; `register()` (nothing container-side needed); `boot()` = the seam |
| `Mail/GraphMailer.php` | `GraphMailer implements IMailer` | the decorator: delegates `createMessage`/`createAttachment*`/`createEMailTemplate`/`validateMailAddress` to core; `send()` goes Graph |
| `Mail/MessageConverter.php` | `MessageConverter` | `\OC\Mail\Message` → `Solvetus\Missivus\Message`; forced From + warning; header policy |
| `Mail/MailerFactory.php` | `MailerFactory` | autowired; builds the decorator + transport wiring from config |
| `Service/ConfigService.php` | `ConfigService` | settings model, config.php override tier, write-only secrets, `getConfigurationProblem()`, last-error surface |
| `Adapter/HttpClient.php` | wraps `IClientService` | contract: 4xx/5xx are normal returns; only unreachable throws |
| `Adapter/TokenCache.php` | wraps `ICacheFactory::createDistributed('missivus')` | refuses TTL ≤ 0 |
| `Adapter/Logger.php` | wraps PSR `LoggerInterface` | `['app' => 'missivus']`; error/warning always, info at debug |
| `Settings/AdminSection.php` | `IIconSection` | "Missivus" section, mark icon |
| `Settings/AdminSettings.php` | `ISettings` | renders `templates/admin.php` |
| `Controller/SettingsController.php` | `Controller` | POST save (admin + CSRF via default attribute posture) |
| `Controller/TestEmailController.php` | `OCSController` | `#[ApiRoute]` POST test email (admin + CSRF) |
| `Command/TestCommand.php` | `missivus:test <address>` | sends without fallback, loud exit 1 |
| `Command/StatusCommand.php` | `missivus:status` | resolved config + sources, never secrets |

`#[ApiRoute]`, `#[AuthorizedAdminSetting]`, `#[NoCSRFRequired]`, `#[FrontpageRoute]` are identical
files on both branches **(verified by diff)**. Admin-required and CSRF-required are the *defaults*
in AppFramework — the test endpoint simply carries neither `NoAdminRequired` nor `NoCSRFRequired`.

## 5. Message conversion (`\OC\Mail\Message` → Graph)

Core's contract, quoted **(verified both branches; `\OC\Mail\Message` differs only in
`#[\Override]` attributes between 33 and 34)**: `IMailer::send(IMessage): array` returns failed
recipients and documents `@throws \Exception`. Core's own `send()` throws `InvalidArgumentException`
unless the message is `\OC\Mail\Message`, defaults an empty From to
`Util::getDefaultEmailAddress('no-reply')` + theming name, dispatches `BeforeMessageSent`, then
converts via `$message->getSymfonyEmail()`.

The decorator mirrors that exactly, then converts:

| Nextcloud side | Graph side |
|---|---|
| `getFrom()` (after core-identical empty-From defaulting) | compared against sender mailbox; **From is always forced** to the configured shared mailbox |
| original From (when it differed) | warning log naming both addresses; kept as Reply-To **only if** the message has no explicit Reply-To |
| `getReplyTo()` | `replyTo` |
| `getTo()`/`getCc()`/`getBcc()` (`[email => name]` or `[email]` shapes) | `toRecipients`/`ccRecipients`/`bccRecipients` |
| `getSubject()` | `subject` |
| `getPlainBody()` / `getSymfonyEmail()->getHtmlBody()` | `body`: HTML wins when present (`contentType: HTML`), else text — Graph's JSON `sendMail` carries one body; same family decision as Matomo/WordPress |
| `getSymfonyEmail()->getAttachments()` → Symfony `DataPart` (`getBody()`, `getFilename()`, `getContentType()`, `hasContentId()`/`getContentId()`, disposition) | `Solvetus\Missivus\Attachment($name, $bytes, $mime, $contentId)`; contentId set for inline parts |
| `getAutoSubmitted()`, `List-Unsubscribe` header if present | **dropped with a warning naming the header** — Graph `sendMail` restricts `internetMessageHeaders` to names starting with `x-`, and the vendored transport (unchanged, by rule) carries no custom headers. Same behaviour, same wording family as the WordPress parser. Documented in FAQ. |

`BeforeMessageSent` is dispatched by the decorator on the Graph path (listeners keep working); on
the delegate paths core dispatches it itself. The rare double-dispatch on the fallback path
(Graph failed → core sends) is accepted and documented here.

## 6. Failure policy

- Transport failure, **fallback OFF (default)**: redact (`GraphException::redactedWith`), log at
  error via the app logger, record `last_error`/`last_error_at` in appconfig (lazy), **throw the
  redacted exception** — `@throws \Exception` is part of the `IMailer::send` contract and core
  callers handle it (`LostController` catches and shows the user an error instead of a false
  success). Never a silent return.
- **Fallback ON**: same redact + error log + last-error record, then "falling back to the Nextcloud
  system mailer" at warning and `return $this->inner->send($message)`.
- Disabled (`enabled = 0`): pure delegation, zero Graph involvement, zero log noise.
- Test paths (`occ missivus:test`, OCS endpoint) call `sendWithoutFallback()` semantics — the
  fallback is deliberately ignored (a test that quietly succeeded over SMTP proves nothing) and the
  redacted Microsoft error body is surfaced to the admin.
- The settings page shows the last recorded failure (redacted, with timestamp) — the brief's
  "failure surfaces in the admin settings page".

## 7. Settings model

Storage: `IAppConfig` (app `missivus`). **(verified)** `setValueString(string $app, string $key,
string $value, bool $lazy = false, bool $sensitive = false)` and `FLAG_SENSITIVE` are identical on
both branches. Secrets (`client_secret`, `certificate_passphrase`) are stored
`sensitive: true, lazy: true` (encrypted at rest, excluded from `occ config:list`); other keys lazy.

| Key | UI | Default | config.php override (`'missivus' => [...]`) |
|---|---|---|---|
| `enabled` | toggle | off | `enabled` (bool) |
| `tenant_id` | text (GUID or DNS domain) | '' | `tenant_id` |
| `client_id` | text (GUID) | '' | `client_id` |
| `auth_method` | select secret/certificate | `secret` | `auth_method` |
| `client_secret` | password, **write-only** | '' | `client_secret` |
| `certificate_path` | text (absolute path) | '' | `certificate_path` |
| `certificate_passphrase` | password, **write-only** | '' | `certificate_passphrase` |
| `sender_mailbox` | email | '' | `sender_mailbox` |
| `save_to_sent` | toggle | off | `save_to_sent` (bool) |
| `fallback_to_system` | toggle | **off** | `fallback_to_system` (bool) |
| — no UI — | | `https://graph.microsoft.com` | `graph_base_url` |
| — no UI — | | `https://login.microsoftonline.com` | `login_base_url` |
| — no UI — | | PS256 | `certificate_algorithm` |

Precedence: **config.php wins over appconfig wins over default** (`IConfig::getSystemValue('missivus', [])`).
An overridden field renders disabled with "Set in config.php" and the key name; the save handler
*discards* posted values for overridden keys, so a file-managed secret is never copied into
appconfig. Write-only semantics: password inputs always render empty; a stored value shows only
"Saved — enter a new value to replace it"; empty submission keeps the stored value; values over
1024 bytes or containing whitespace/control characters are rejected as paste accidents. Validation
mirrors WordPress (GUID/domain, GUID, email, absolute path without NUL); emptiness is never an error
while the switch is off. Docs instruct `mail_from_address`/`mail_domain` = the shared mailbox so the
forced-From warning stays quiet.

## 8. Auth + adapters

Auth is entirely the vendored transport's: client credentials, secret (UI default) or certificate
(PS256 client assertion, RS256 escape hatch), token cached with `expires_in − 300 s`.

- **HTTP**: `IClientService::newClient()` with `'timeout'`, `'headers'`, `'body'` and Guzzle
  passthrough. The adapter both passes `'http_errors' => false` and catches
  `GuzzleHttp\Exception\BadResponseException` → returns `HttpResponse(status, body, headers)` —
  either way an HTTP error status is a **normal return** per the contract; only
  could-not-reach becomes `RuntimeException`. Inherits Nextcloud's proxy and CA configuration —
  the reason to use `IClientService` at all.
- **Cache**: `ICacheFactory::createDistributed('missivus')` → `ICache::get/set($k,$v,$ttl)/remove`.
  Falls back to per-request cache when no distributed cache is configured (token refetch per
  request; correct, just slower). TTL ≤ 0 refused.
- **Logger**: PSR `LoggerInterface` with `['app' => 'missivus']`; messages arrive pre-redacted;
  error/warning always written, info only at debug level.

## 9. Attachment strategy **(same as siblings — decided)**

Inline `fileAttachment` (base64 in the `sendMail` JSON) while total stays ≤ 3 MB
(`LARGE_ATTACHMENT_THRESHOLD`/`TOTAL_INLINE_BUDGET` = 3 145 728, verified against Graph's ~3 MB
request ceiling upstream); above that the transport takes draft → `createUploadSession` → chunked
PUTs (3 276 800-byte chunks, no Authorization header on PUTs — the URL is itself the credential and
is redacted as such) → send. `Mail.ReadWrite` is required only for that path; documented. All of
this is inside the vendored class; the Nextcloud layer only feeds bytes via Symfony `DataPart`.

## 10. i18n

`l10n/{pt_PT,fr,es,it}.{js,json}` (Nextcloud translation format, source strings EN). README carries
the family line: translations are AI-produced and human-reviewed, corrections welcome.

## 11. Security

Inherited three-layer redaction (Endpoint describe-never-echo; Redactor literal+shape scrub;
`redactedWith` final gate) with the issue-#1 lesson applied from day one: **every string that
reaches a log, an OCS response, the settings page or an occ exit message passes one final
`redact()`**, including strings built from operator-supplied base URLs (poisoned-URL tests
included). Admin-only + CSRF on every endpoint (AppFramework defaults, nothing opts out). Secrets:
sensitive+lazy appconfig, never redisplayed, never in `occ missivus:status` output, never in HTML,
`Credentials::__toString/__debugInfo` neutered upstream. Privacy disclosure (talks to
`login.microsoftonline.com` and `graph.microsoft.com`) in info.xml description, README and the
settings page. `docs/SECURITY.md` mirrors the siblings' numbered-findings review plus a Nextcloud
section (appconfig sensitive storage, distributed cache token residency, unthrottled admin test
mail).

## 12. Test strategy

- **PHPUnit** (dev harness via Composer: `phpunit`, `nextcloud/ocp` (33-compatible), `symfony/mime`
  for real `Email`/`DataPart` in converter tests, `psalm`). Hand-rolled fakes for `\OC\Mail\Message`
  cannot be avoided entirely (it is private API) — a thin test double wraps a real Symfony `Email`
  exactly as core does. Doubles mirror the family: `FakeHttpClient` (scripted `HttpResponse` queue),
  `FakeCache`, `RecordingLogger`.
- Cases required by the brief, all mocked-Graph: send happy path; conversion (from/reply-to/cc/bcc/
  html+plain/attachments inline + large-draft path); forced-From warning + Reply-To keep + no-warning
  on match; fallback OFF (logged + thrown) / ON (inner called, still logged); config.php overrides
  (file wins, secret never written when file-defined); secret redaction everywhere incl. **poisoned
  `graph_base_url`/`login_base_url` values whose credentials must reach neither log nor response**;
  OCS endpoint admin + CSRF posture; disabled = pure delegation.
- **Static analysis**: Psalm at the app-template level (`psalm.xml` against `lib/`, vendored tree
  included as project files, `nextcloud/ocp` stubs) — clean.
- **Lint**: `php -l` over every shipped `.php` in a `php:8.2-cli` container (the floor), clean.
- **Smoke (real)**: official `nextcloud:latest` Docker image (34.x): install app into
  `custom_apps`, `occ app:enable missivus`, admin settings page renders authenticated, enable with
  placeholder credentials, `occ missivus:test` fails loudly with the Microsoft AADSTS error
  (redacted), then trigger a **password-reset email** from the login flow and show the missivus log
  line proving core mail went through the seam. Never touches any live server.

## 13. Nextcloud internals depended on **(the coupling table)**

| # | Internal | Used for | Breaks if |
|---|---|---|---|
| 1 | `Server.php` registers `IMailer` as replaceable lazy service | the seam | core freezes/aliases the service differently |
| 2 | `SimpleContainer::registerService` unset-and-replace semantics | the seam | container becomes append-only |
| 3 | `IContainer::registerService` stays on `IServerContainer` (deprecated since 20, still shipped in 34) | the seam | method removed — would need `\OC::$server` direct (as custom_domain does) |
| 4 | `DIContainer::query` OCP-fallthrough to server container | app-originated mail coverage | app containers stop delegating |
| 5 | `\OC\Mail\Message` (private) + `getSymfonyEmail()` | conversion | Message stops wrapping Symfony `Email` |
| 6 | Symfony `DataPart::getBody/getFilename/getContentType/getContentId` | attachments | core swaps mime library |
| 7 | `Util::getDefaultEmailAddress('no-reply')` | core-identical From defaulting | helper changes |
| 8 | `BeforeMessageSent` dispatch shape | listener fidelity | event removed |
| 9 | `IAppConfig::setValueString(..., lazy, sensitive)` | secret storage | API redesign (again) |
| 10 | `#[ApiRoute]` attribute routing for OCS | test endpoint | attribute removed (both branches have it) |
| 11 | info.xml `<commands>`, `<settings>` elements | occ + settings registration | schema change |
| 12 | `IClientService` Guzzle option passthrough (`http_errors`) | HTTP adapter | client filters options (adapter also catches `BadResponseException`, so this is belt-and-braces) |

Upgrade checklist per new major: rerun the seam evidence greps (items 1–4), diff `\OC\Mail\Message`,
re-run the suite, bump `max-version`.

## 14. Docs / build / release

Docs set mirrors WordPress: README (mark from `.github/mark.svg`, pitch, delegated-vs-application
table, install, config.php block, security pointer, Solvetus support line verbatim from the family),
`docs/INSTALL.md` (same Entra + Exchange guide, PowerShell blocks byte-identical to siblings),
`docs/faq.md`, `docs/SECURITY.md`, root `SECURITY.md` (security@missivus.com), CONTRIBUTING,
CHANGELOG (Keep a Changelog), LICENSE (GPLv3), `.github/social-preview.png` (paper `#faf8f3`, mark +
lowercase wordmark, "for Nextcloud"). App Store metadata in info.xml validated against the XSD;
screenshots are listed for the owner to supply. Build: `tools/build-tarball.sh` — **allowlist** →
`dist/missivus-0.1.0.tar.gz` with `missivus/` as the single top-level directory (App Store layout).
App Store publication needs a signing certificate the owner requests via
`nextcloud/app-certificate-requests` (CSR + repo PR) — documented for the owner; not done here.
Release: small logical commits on `main`, tag `v0.1.0`, GitHub Release carrying the tarball.

## 15. Self-review against the brief (line by line)

| Brief line | Where satisfied |
|---|---|
| GPLv3, AGPL only if required — verify | §2: XSD allows `GPL-3.0-or-later`; GPLv3 kept, evidence recorded |
| all outbound system mail via Graph, app permissions, shared mailbox | §1, §3 (seam covers core + apps), §8 (auth) |
| due diligence before design; STOP if duplicate | §0 — no duplicate; competitors recorded for docs |
| repo Solvetus/missivus-nextcloud, app id `missivus`, `OCA\Missivus\`, includeIf identity | repo setup (see git history) |
| read Mailer/IMailer/IMessage/BeforeMessageSent/bootstrap/settings/IAppConfig/IClientService/ICacheFactory/OCS attrs/l10n/info.xml/occ | §§2–13, all (verified) items |
| two most recent stable majors; PHP floor = older major's minimum | §2: NC 33+34, PHP 8.2 |
| seam decided empirically, order 1→2→3, reasons + internals in PLAN | §3 (option 2 chosen over 1, reasons; STOP clause addressed), §13 |
| conversion preserves HTML+plain, From name, Reply-To, To/Cc/Bcc, attachments incl. >3 MB, headers that matter | §5, §9; single-body and x-header Graph limits documented with evidence |
| auth: secret AND certificate, UI default secret, token cached distributed w/ margin | §7, §8 |
| forced From + warn; docs point `mail_from_address` at the mailbox | §5, §7 |
| fallback default OFF, failures error-logged, never swallowed, surfaced in settings page | §6 |
| admin settings section with all listed fields, write-only secret, test email OCS admin+CSRF inline result | §4.2, §7, §6 |
| config.php overrides, file wins, "set in config.php", secret never written | §7 |
| `occ missivus:test` / `occ missivus:status` | §4.2 |
| vendored transport unchanged in `lib/Vendor/`; change needed → STOP | §4.1; no change needed (adapters satisfy all three contracts) |
| zero third-party runtime deps; no Composer autoload in tarball | §4.1 (spl autoloader), §14 (allowlist) |
| security: admin+CSRF, escape, secrets never in logs/HTML/JS/OCS, issue-#1 redaction | §11 |
| privacy disclosure in info.xml + README + settings | §11 |
| i18n EN + PT-PT/FR/ES/IT, AI-produced human-reviewed line | §10 |
| tests incl. poisoned base-URL; psalm/phpstan; php -l at floor; Docker smoke with loud failure | §12 |
| docs set + mark + social preview + App Store metadata + dependencies | §14 |
| build tarball allowlist; signing cert documented for owner; do not publish | §14 |
| never touch a live server; version 0.1.0 | §12 (smoke is local Docker only), release plan |
| secrets never invented/printed/committed; small commits; Solvetus line; vault log | house rules, throughout |

Contradictions found and resolved: (a) "preserve HTML + plain parts" vs Graph's single-body JSON —
resolved by the family precedent (HTML precedence), documented; (b) "headers that matter" vs
Graph's `x-`-only custom headers with an unchangeable vendored transport — resolved as
warn-and-drop, matching WordPress, documented in FAQ; (c) `IContainer::registerService` deprecation
vs need for a server-level override — the deprecation points at the app-container API, which cannot
do this job; prior art uses it; risk carried in §13.
