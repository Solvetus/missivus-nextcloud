<p align="center">
  <a href="https://missivus.com"><img src=".github/mark.svg" alt="Missivus" width="40" height="41"></a>
</p>

# Missivus for Nextcloud

**Missivus for Nextcloud — send Nextcloud email through Microsoft Graph with application permissions
and one mailbox. No SMTP, no user login. Free, GPLv3.**

Microsoft is retiring basic-authentication SMTP for Microsoft 365 — disabled by default for
existing tenants from the end of December 2026, unavailable to new tenants from 2027, with final
removal to follow — and Nextcloud's outgoing mail settings only speak SMTP or sendmail. The result
is a Nextcloud that quietly sends nothing: no
password resets, no share notifications, no activity digests, no admin alerts — or one that leans
on a separate relay daemon you now also have to run.

Missivus replaces Nextcloud's system mailer with one that talks to the Microsoft Graph API
directly, using **application permissions and one mailbox**: OAuth2 client credentials, the
`Mail.Send` application permission, and an Exchange scope (RBAC for Applications, or an application
access policy) that locks the app to one mailbox. No user signs in, nothing is clicked, and nothing breaks when someone leaves the
company.

## Application permissions, not a delegated login

| | Delegated mailers | Missivus |
| --- | --- | --- |
| Who sends | A person's account, connected by clicking "Sign in" | The application itself |
| Setup | A human logs in and stays logged in | Admin creates an app registration once |
| Someone leaves the company | Mail breaks | Nothing happens |
| Mailbox licence | Usually a licensed user | None for a shared mailbox (a user mailbox only if you want an alias) |
| Blast radius | Whatever that person can do | One mailbox, enforced by Exchange |
| Extra services | Sometimes an SMTP relay in between | None |

## What it does

- Sends **all** Nextcloud outgoing email through Graph — core system mail (password resets, share
  notifications, activity digests, admin alerts) and every app that uses Nextcloud's mailer
- Client secret or certificate credentials; tokens cached in Nextcloud's distributed cache with a
  safety margin
- Forces From to the configured mailbox — or, with an optional **From address**, an alias of it
  (requires `SendFromAliasEnabled` in Exchange and a user mailbox, not a shared one); a differing
  requested sender is kept as Reply-To and logged
- Attachments inline up to Graph's ~3 MB ceiling and via upload sessions above it
- Optional fallback to Nextcloud's own mailer, **off by default**; every failure is logged at error
  level and shown on the settings page — never swallowed
- Admin settings with a test-email button, `config.php` overrides for every value (a file-managed
  secret never touches the database), and `occ missivus:test` / `occ missivus:status`
- Ships switched **off**: installing it changes nothing until you turn it on

## What you need

- Nextcloud 33 or 34, PHP 8.2+
- A Microsoft 365 tenant and an account that can create app registrations and grant admin consent
- About an hour for the Entra and Exchange setup — [docs/INSTALL.md](docs/INSTALL.md) spells out
  every click for someone who has never opened Entra

## Getting started

1. Follow [docs/INSTALL.md](docs/INSTALL.md): app registration → credential → mailbox →
   `Mail.Send` scoped to that one mailbox (RBAC for Applications, or the legacy application access
   policy) → install → configure → test. Upgrading from 0.1.1? The guide has a section for it.
2. Questions the guide does not answer are probably in [docs/faq.md](docs/faq.md).

> Missivus is free and open source (GPLv3). If you would rather not do the Entra and Exchange setup
> yourself, [Solvetus](https://solvetus.com) offers paid installation and support.

## External services

When enabled, this app connects to `login.microsoftonline.com` (to obtain access tokens) and
`graph.microsoft.com` (to send mail). No other external service is contacted, and nothing is sent
anywhere until you configure and enable it.

## Security

See [docs/SECURITY.md](docs/SECURITY.md) for the standing security review — what is enforced, what
is verified, and which risks are accepted with reasons. To report a vulnerability, email
`security@missivus.com`; please do not open a public issue.

## Development

```bash
composer install        # dev-only harness; nothing from it ships
composer test           # PHPUnit, mocked Graph
composer psalm          # static analysis
tools/build-tarball.sh  # dist/missivus-<version>.tar.gz in App Store layout
```

The Graph transport under `lib/Vendor/Solvetus/Missivus/` is vendored **unchanged** from
[missivus-matomo](https://github.com/Solvetus/missivus-matomo) — the same class every Missivus
port ships. Fixes to it go upstream first, never here; each release proves the tree is
byte-identical with `diff -r`. Architecture and the exact Nextcloud internals this app depends on
are recorded in [PLAN.md](PLAN.md).

Translations (PT-PT, FR, ES, IT) are AI-produced and human-reviewed — corrections welcome.

## Licence

[GPLv3](LICENSE).

## Support

Missivus is free and open source. If you would rather not do the Entra and Exchange setup yourself,
[Solvetus](https://solvetus.com) offers paid installation and support.
