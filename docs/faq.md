# Frequently asked questions

### Does the shared mailbox need a licence?

No. Shared mailboxes in Microsoft 365 are free up to 50 GB, and Missivus only sends from it. That
is much of the point of this design.

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
don't grant it. The application access policy scopes it to the one shared mailbox either way.

### Is the application access policy really necessary?

Yes. Without it, `Mail.Send` lets the app send as **anyone in your tenant**. The policy narrows it
to the one shared mailbox. Treat it as part of the installation, not hardening you might do later.

### Why is the test-email button greyed out?

The **saved** configuration is incomplete — the note above it names the missing part. The test
sends with what is stored, not what is typed, so save first.

### Emails arrive "from" the shared mailbox even though an app asked for another sender. Why?

Application-only sending can only send as the mailbox the policy allows. Missivus forces the From,
keeps the requested address as Reply-To, and logs a warning naming both. Point
`mail_from_address`/`mail_domain` in `config.php` at the shared mailbox and the warning goes quiet.

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
