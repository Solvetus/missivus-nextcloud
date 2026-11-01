# Frequently asked questions

### Does the mailbox need a licence?

Not if it is a shared mailbox: shared mailboxes in Microsoft 365 are free up to 50 GB, and Missivus
only sends from it. That is much of the point of this design. A **user** mailbox needs an Exchange
Online licence, and you only need one if you want to send from an alias of the mailbox (see below) —
Microsoft does not support sending from an alias of a shared mailbox.

### Why not just configure SMTP?

Microsoft is retiring basic-authentication SMTP for Microsoft 365 — disabled by default for
existing tenants from the end of December 2026, unavailable to new tenants from 2027, with final
removal to follow — and Nextcloud's outgoing-mail settings have no way to obtain OAuth tokens ([nextcloud/server#21595](https://github.com/nextcloud/server/issues/21595),
open since 2020). The workarounds are an SMTP relay daemon between Nextcloud and Microsoft — one
more service to run — or tenant-level exceptions Microsoft is actively closing. Missivus skips SMTP
entirely and talks to the Graph API.

### Client secret or certificate?

Start with the secret: two clicks, and the guide assumes it. A certificate is stronger — it never
travels in a request body — and Missivus supports it fully; move to it later if you want, without
reinstalling anything.

### How do I rotate the secret?

Create a new secret in Entra (Part 3 of the install guide), paste the new Value into the settings
page, save, send a test email, then delete the old secret in Entra. Mail keeps flowing throughout.

### What happens to attachments over 3 MB?

Graph's JSON send call has a ~3 MB ceiling. Above it, Missivus creates a draft, uploads the file in
chunks through an upload session, and sends the draft — automatically. That path needs the
`Mail.ReadWrite` application permission; without it, only oversized attachments fail, with an error
naming the permission.

### Why does it need Mail.ReadWrite?

Only for attachments over 3 MB (the draft-then-upload path). If Nextcloud never emails big files,
don't grant it. Whichever scoping method you use (next question), it scopes this permission to the
one mailbox too: with RBAC for Applications you assign the `Application Mail.ReadWrite` role to the
same mailbox scope, with an access policy the policy covers every permission consented in Entra.

### Do I really have to scope the app to one mailbox?

Yes. Without it, `Mail.Send` lets the app send as **anyone in your tenant**. Exchange narrows it to
the one mailbox. Treat it as part of the installation, not hardening you might do later.

### RBAC for Applications or an Application Access Policy?

RBAC for Applications in Exchange Online is the recommended method: it is Microsoft's current
mechanism and replaces Application Access Policies. You grant the app the `Mail.Send` role in
Exchange, already limited to one mailbox, instead of consenting to it tenant-wide in Entra. The
Application Access Policy is the legacy alternative; it still works and suits tenants that already
use it. The install guide (Part 5) covers both. Do not combine them for the same app: Exchange adds
the grants together, so a tenant-wide `Mail.Send` consent in Entra defeats an RBAC scope.

### Why is the test-email button greyed out?

The **saved** configuration is incomplete — the note above it names the missing part. The test
sends with what is stored, not what is typed, so save first.

### Emails arrive "from" the mailbox even though an app asked for another sender. Why?

Application-only sending can only send as the mailbox Exchange has scoped the app to. Missivus
forces the From, keeps the requested address as Reply-To, and logs a warning naming both. Point
`mail_from_address`/`mail_domain` in `config.php` at the mailbox and the warning goes quiet — or at
the From address if you set one (below).

### Can I send from an alias of the mailbox instead of the mailbox itself?

Yes, with two conditions. Set **From address** to the alias (for example `kontakt@` as an alias of
the `ft@` mailbox): the Graph call and the Exchange scope still target the mailbox, only the From
header changes. Exchange preserves the alias only when the tenant has `SendFromAliasEnabled` turned
on (`Set-OrganizationConfig -SendFromAliasEnabled $true`) and the mailbox is a **user mailbox** —
Microsoft does not support sending from an alias of a shared mailbox. Leave the field empty and From
is forced to the mailbox, as before. Always check the delivered From line, not just the 202
acknowledgement.

If Exchange refuses the address, Missivus shows a message naming both addresses and the two usual
causes (the alias is not on that mailbox, or `SendFromAliasEnabled` is off) instead of a bare
`ErrorSendAsDenied`.

### What if I install it and do nothing?

Nothing changes. Missivus ships switched off; Nextcloud keeps using its own mailer until you
configure the app and turn it on.

### Which email goes through Missivus?

All of it: everything that uses Nextcloud's mailer — core system mail (password resets, share
notifications, activity digests, admin alerts) and any app that sends through `IMailer`, which is
the supported way apps send mail. The Mail *app* (the webmail client) is unrelated: it sends
through the accounts its users configure.

### Do custom headers survive?

Graph's JSON send call only carries custom headers whose names start with `x-`, so headers like
`Auto-Submitted` are dropped, with a log warning naming them. In practice this has no visible
effect on Nextcloud's own mail.

### What do the Microsoft error codes mean?

The table at the end of [INSTALL.md](INSTALL.md) maps the common ones (`AADSTS7000215`,
`AADSTS900023`, `ErrorAccessDenied`, `MailboxNotEnabledForRESTAPI`, …) to their fixes.

### Does it work with Nextcloud AIO / Docker / Snap?

Yes — it is a normal Nextcloud app with no system dependencies beyond PHP's openssl and json,
which Nextcloud already requires. Outbound HTTPS to `login.microsoftonline.com` and
`graph.microsoft.com` must be allowed.

### Where do I report a bug? A vulnerability?

Bugs: <https://github.com/Solvetus/missivus-nextcloud/issues>. Vulnerabilities:
`security@missivus.com`, not a public issue. Never paste a secret or a PEM into either.
