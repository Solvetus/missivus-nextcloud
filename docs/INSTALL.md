# Installing Missivus

This guide assumes you have **never used Microsoft Entra before**. Every click is spelled out.

You will need:

- An account in your Microsoft 365 tenant that can create app registrations and grant admin consent
  — in practice, a **Global Administrator** or an **Application Administrator** who is also an
  **Exchange Administrator**.
- Administrator access to your Nextcloud, including a shell that can run `occ` for the
  command-line steps (they all have web-UI equivalents).

Set aside about an hour. Nothing here is reversible-by-accident; every step can be undone.

Throughout, replace `example.com` with your own domain and `noreply@example.com` with whatever
address you want Nextcloud's email to come from.

---

## Part 1 — Create the app registration

This is the identity Nextcloud will use. It is not a user and has no password anyone types.

1. Go to <https://entra.microsoft.com> and sign in.
2. In the left-hand menu choose **Applications → App registrations**.
3. Click **+ New registration**.
4. Fill in the form:
   - **Name**: `missivus-nextcloud-<your cloud's hostname>` — for example
     `missivus-nextcloud-cloud.example.com`. Only administrators see this, and naming it after the
     tool and the host keeps it straight once your tenant holds several registrations.
   - **Supported account types**: **Accounts in this organizational directory only (Single tenant)**
   - **Redirect URI**: leave completely empty. Missivus never redirects a browser anywhere, and an
     empty value here is part of why this setup cannot be hijacked.
5. Click **Register**.

You now land on the app's **Overview** page. Two values on it go into Nextcloud. Copy them somewhere
safe — they are identifiers, not secrets, so a note is fine:

| On the Overview page | Goes into Nextcloud as |
| --- | --- |
| **Application (client) ID** | Application (client) ID |
| **Directory (tenant) ID** | Directory (tenant) ID |

---

## Part 2 — Grant the permission to send mail

> **Planning to use RBAC for Applications (Method A in Part 5, recommended)?** Then **skip this
> whole part**: do not grant `Mail.Send` or `Mail.ReadWrite` in Entra. Part 5 grants the same
> permissions from Exchange, already scoped to one mailbox. A tenant-wide grant made here would be
> added to the scoped one and cancel it out. Carry on with Part 3.
>
> Using the legacy Application Access Policy (Method B)? Follow this part as written.

1. Still inside your app registration, choose **API permissions** in the left-hand menu.
2. Click **+ Add a permission**.
3. Choose **Microsoft Graph**.
4. Choose **Application permissions**. This is the important choice — *not* "Delegated permissions".
   Application permissions belong to the app itself, which is why no human ever has to sign in.
5. In the search box type `Mail.Send`. Tick **Mail.Send**.
6. **If Nextcloud will email attachments larger than 3 MB** also search for `Mail.ReadWrite` and
   tick it. Microsoft requires it for the large-attachment upload path: sending a big file means
   creating a draft first, and `Mail.Send` alone does not allow that. If you skip it, ordinary
   email still works and only oversized attachments fail, with an error naming this permission.
7. Click **Add permissions**.
8. Back on the API permissions page you will see your permissions with a warning triangle and the
   status **Not granted for &lt;your organisation&gt;**. Click
   **✓ Grant admin consent for &lt;your organisation&gt;**, then **Yes**.
9. Confirm the Status column now reads **Granted for &lt;your organisation&gt;** with a green tick.

> If the "Grant admin consent" button is greyed out, your account cannot consent. Ask a Global
> Administrator to click it. Nothing else in this guide requires their involvement.

You may also see **User.Read** listed as a delegated permission. Azure adds it automatically to new
registrations. Missivus does not use it and you can safely remove it.

---

## Part 3 — Give the app a credential

Pick **one** of these. If you are not sure, pick the client secret: it is two clicks, it is what the
rest of this guide assumes, and you can move to a certificate later without reinstalling anything.

### Option A — Client secret (start here)

1. In your app registration choose **Certificates & secrets**.
2. On the **Client secrets** tab click **+ New client secret**.
3. Give it a description following the pattern `missivus-nextcloud-<your cloud's hostname>` —
   for example `missivus-nextcloud-cloud.example.com` — then choose an expiry and click
   **Add**. A tenant accumulates app registrations and secrets quickly, and a name that says
   which tool and which host it belongs to is the difference between confidently rotating a
   secret and being afraid to touch it.
4. Copy the **Value** column immediately. **It is shown once and never again.** The *Secret ID*
   column is not the secret and is not what you need — this trips almost everyone up the first
   time.
5. Put the expiry date in a calendar. Mail stops on that day if the secret is not replaced.

That is the credential. Skip to Part 4.

### Option B — Certificate (optional hardening)

A certificate is more secure than a secret: it never travels in a request body, and it can be given
a longer life without the same exposure. It is worth doing if you are comfortable with `openssl` and
managing a key file on the server — but nothing in Missivus needs it, and a secret is not a second-
class option.

Run this on your server, or anywhere with `openssl` installed:

```bash
openssl req -x509 -newkey rsa:2048 -keyout missivus.key -out missivus.crt \
  -days 730 -nodes -subj "/CN=missivus-nextcloud"

# Missivus wants one file containing both parts:
cat missivus.key missivus.crt > missivus.pem
```

- `missivus.pem` is the file Nextcloud reads. **It is a credential — treat it like a password.**
- `missivus.crt` is the public half, the only part you upload to Microsoft.

Upload the public half:

1. In your app registration choose **Certificates & secrets**.
2. Select the **Certificates** tab, click **Upload certificate**.
3. Choose `missivus.crt`, add a description, click **Add**.
4. A thumbprint and an expiry date appear. **Put the expiry date in a calendar** — mail stops on
   that day if the certificate is not replaced.

Put the PEM somewhere outside your web root and outside the apps directory, so an app update
cannot overwrite it and a web server misconfiguration cannot serve it:

```bash
sudo mkdir -p /etc/nextcloud/secrets
sudo mv missivus.pem /etc/nextcloud/secrets/missivus.pem
sudo chown root:www-data /etc/nextcloud/secrets/missivus.pem
sudo chmod 640 /etc/nextcloud/secrets/missivus.pem
rm missivus.key missivus.crt      # no longer needed on this machine
```

Use `www-data` or whichever user your web server runs as.

If you choose this option, set **Authentication method** to **Certificate** in Part 7 and give the
path to the PEM instead of a secret.

---

## Part 4 — Create the mailbox

Missivus sends through **one mailbox**. Which kind to create depends on one question: should the
From address be the mailbox itself, or an **alias** of it?

| You want | Create | Licence |
| --- | --- | --- |
| Mail to arrive from the mailbox itself (`noreply@example.com`) | A **shared mailbox** (below) | None — that is the point: Nextcloud gets a real, monitorable mailbox for free |
| Mail to arrive from an alias of the mailbox (for example `kontakt@example.com`, via the optional **From address** in Part 7) | A **user mailbox** (below) | One Exchange Online licence |

> **Sending from an alias needs a user mailbox.** Microsoft does not support sending from an alias
> of a *shared* mailbox, so if you set a From address (Part 7) on a shared mailbox it will not work.
> If you are not sure whether you will want an alias, choose the shared mailbox: you can move to a
> user mailbox later by changing the **Sender mailbox**.

Make it a **company-wide no-reply address rather than a Nextcloud-specific one** —
`noreply@example.com` with the display name set to your company name, not `nextcloud@` or the name
of your server. Recipients see the display name, and "Example Ltd" reads better in an inbox than
the name of your file server. It also means the next tool that needs to send email can reuse this
same mailbox: each tool gets **its own Entra app registration**, scoped to this mailbox in Exchange,
so their credentials stay completely separate while the address your users see stays consistent.

**Shared mailbox:**

1. Go to <https://admin.microsoft.com>.
2. Choose **Teams & groups → Shared mailboxes**.
3. Click **+ Add a shared mailbox**.
4. Set the name to your company name — for example `Example Ltd` — with the address
   `noreply@example.com`.
5. Click **Save changes**. Give it a few minutes to appear everywhere.

**User mailbox (only if you need an alias):**

1. Go to <https://admin.microsoft.com> and choose **Users → Active users → Add a user**.
2. Set the display name to your company name and the username to `noreply`, assign an Exchange
   Online licence, and finish the wizard.
3. Open the new user, choose **Manage username and email**, and add the alias (for example
   `kontakt@example.com`). It can take a while to appear everywhere.
4. Block sign-in for the account: nobody needs to sign into it.
5. In Exchange, turn on `SendFromAliasEnabled` once for the tenant, in the PowerShell window from
   Part 5: `Set-OrganizationConfig -SendFromAliasEnabled $true`. Without it Exchange does not
   honour the alias.

For a shared mailbox, do not add any members. Nobody needs to sign into it.

Two optional bits of hardening while you are here. Both are worth doing and **neither changes
anything for Missivus**, which talks to Graph and needs none of these protocols:

- **Mailbox → Email apps**: switch off Outlook desktop (MAPI), Exchange web services, ActiveSync,
  IMAP, POP3 and Outlook on the web. That closes every sign-in route into the mailbox.
- **Mailbox → General → Hide from address list**: turn it on, so a no-reply address does not clutter
  colleagues' address pickers or invite replies.

---

## Part 5 — Lock the app to that one mailbox

**Do not skip this.** The `Mail.Send` application permission lets an app send as *anyone* in your
tenant until Exchange is told to narrow it. Both methods below narrow it to the one mailbox from
Part 4, and that is what makes this whole model safe. Pick **one**:

- **Method A — RBAC for Applications in Exchange Online (recommended).** Microsoft's current
  mechanism, and the one that replaces Application Access Policies. Instead of consenting to
  `Mail.Send` for the whole tenant in Entra, you give the app the `Mail.Send` role *in Exchange*,
  already scoped to the mailbox.
- **Method B — Application Access Policy (legacy).** The older mechanism. It still works and is what
  Part 2 as written sets up. Choose it only if you already run it for your other apps.

**Never use both for the same app.** Exchange adds the two grants together, so a tenant-wide
`Mail.Send` consent in Entra makes an RBAC scope meaningless: the app could still send as anyone.
With Method A, do **not** grant `Mail.Send` or `Mail.ReadWrite` in Part 2 (and remove them if you
already did).

You need PowerShell with the Exchange Online module. Windows has PowerShell built in; on macOS run
`brew install --cask powershell` and then `pwsh`; on Linux see Microsoft's install page. Everything
below is typed into that PowerShell window, one command at a time.

**Before you start, have these values ready:**

- the value shown in the **Application (client) ID** field on your app's Overview page (Part 1) —
  a long identifier like `1a2b3c4d-5e6f-7a8b-9c0d-1e2f3a4b5c6d`. Below it is written as
  `PASTE-APPLICATION-CLIENT-ID`.
- the address you sign in to <https://admin.microsoft.com> with. This is often **not** your normal
  email — many tenants use a separate admin account such as `admin@yourcompany.onmicrosoft.com`.
  Use that one; below it is written as `admin@yourcompany.onmicrosoft.com`.
- **Method A only:** the **Object ID of the enterprise application**, found in Entra under
  **Enterprise applications** (not App registrations) by searching for your app's name. Below it is
  written as `PASTE-ENTERPRISE-APP-OBJECT-ID`. The App registrations page shows a *different*
  Object ID; using that one fails.

**How to fill in the commands.** Wherever you see a `PASTE-…` placeholder, replace *only those
words* and **keep the quotation marks around the value** — so `-AppId "PASTE-APPLICATION-CLIENT-ID"`
becomes `-AppId "1a2b3c4d-5e6f-7a8b-9c0d-1e2f3a4b5c6d"`. Same for `example.com` and the admin
address. Change nothing else on the line.

**Step 1 — install the module and sign in** (a browser window opens; sign in with the admin
account):

```powershell
Install-Module -Name ExchangeOnlineManagement -Scope CurrentUser
Connect-ExchangeOnline -UserPrincipalName admin@yourcompany.onmicrosoft.com
```

**Step 2 — scope the app to the mailbox.** Follow Method A *or* Method B.

### Method A — RBAC for Applications (recommended)

Three commands. The first tells Exchange about your app, the second describes "this one mailbox",
the third gives the app the send role over exactly that mailbox:

```powershell
New-ServicePrincipal -AppId "PASTE-APPLICATION-CLIENT-ID" -ObjectId "PASTE-ENTERPRISE-APP-OBJECT-ID" -DisplayName "Missivus (Nextcloud)"
New-ManagementScope -Name "Missivus mailbox" -RecipientRestrictionFilter "PrimarySmtpAddress -eq 'noreply@example.com'"
New-ManagementRoleAssignment -App "PASTE-APPLICATION-CLIENT-ID" -Role "Application Mail.Send" -CustomResourceScope "Missivus mailbox"
```

Use the mailbox's **primary** address in the filter — the same address you will enter as **Sender
mailbox** in Part 7.

**If Nextcloud will email attachments larger than 3 MB**, add the matching role for the draft-and-upload
path (see Part 2, step 6 — in Method A you grant it here instead of in Entra):

```powershell
New-ManagementRoleAssignment -App "PASTE-APPLICATION-CLIENT-ID" -Role "Application Mail.ReadWrite" -CustomResourceScope "Missivus mailbox"
```

Now check it. Two commands; in the second, use any *other* mailbox in your tenant — your own email
address is fine:

```powershell
Test-ServicePrincipalAuthorization -Identity "PASTE-APPLICATION-CLIENT-ID" -Resource "noreply@example.com"
Test-ServicePrincipalAuthorization -Identity "PASTE-APPLICATION-CLIENT-ID" -Resource "you@example.com"
```

In the first result, the `Application Mail.Send` row must show `InScope : True`. In the second it
must show `InScope : False`. That test bypasses Exchange's cache and ignores anything granted in
Entra, so also open your app's **API permissions** page in Entra and confirm `Mail.Send` and
`Mail.ReadWrite` are **not** listed as granted.

Exchange caches permissions for a running app: a new or changed assignment can take **30 minutes to
2 hours** to reach an app that has been sending recently. If the real send in Step 3 fails with
`ErrorAccessDenied` straight after the test says `True`, wait and retry.

### Method B — Application Access Policy (legacy)

**How it works, in one paragraph.** Exchange cannot point a policy at a single mailbox — it can
only point one at a *group*. So you create a security group whose only member is the mailbox from
Part 4, then tell Exchange "this app may only touch mailboxes in that group". The group gets an
email address of its own (`noreply-apps@yourcompany.onmicrosoft.com` below), but nobody ever sends
to it or from it; it exists purely so the policy has something to point at. Your mailbox stays
exactly as you created it. (This method needs the Part 2 consent in Entra, exactly as written.)

Create the group. One line. Its only member is your mailbox:

```powershell
New-DistributionGroup -Name "NoReply Apps" -Alias noreply-apps -Type Security -Members "noreply@example.com"
```

PowerShell prints the group it created. Note the **PrimarySmtpAddress** it shows. It is usually
**not** on your normal domain but on your tenant's built-in one —
`noreply-apps@yourcompany.onmicrosoft.com`. If you are unsure, run
`Get-DistributionGroup -Identity noreply-apps | Format-List PrimarySmtpAddress` and copy the value.
That exact address is what goes in the next command; using the wrong domain gives
`The identity of the policy scope could not be resolved`.

Lock the app to the group. One line. Paste the Application (client) ID and the group address from
above inside the quotes:

```powershell
New-ApplicationAccessPolicy -AppId "PASTE-APPLICATION-CLIENT-ID" -PolicyScopeGroupId "noreply-apps@yourcompany.onmicrosoft.com" -AccessRight RestrictAccess -Description "Missivus (Nextcloud) may only send as noreply@example.com"
```

**Wait 5–10 minutes**, then check it took effect. Two commands; paste the same Application (client)
ID in both. In the second, use any *other* mailbox in your tenant — your own email address is fine:

```powershell
Test-ApplicationAccessPolicy -Identity "noreply@example.com" -AppId "PASTE-APPLICATION-CLIENT-ID"
Test-ApplicationAccessPolicy -Identity "you@example.com" -AppId "PASTE-APPLICATION-CLIENT-ID"
```

The first must show `AccessCheckResult : Granted`. The second must show
`AccessCheckResult : Denied`. If the second still says **Granted**, the policy has not propagated
yet: wait and run it again. Do not go further until it says Denied.

### Step 3 — pre-flight: send one real email from PowerShell

Optional but strongly recommended: it proves tenant, app, secret and scope end to end before
Nextcloud is involved, so any later failure is the app's, not Microsoft's. (It uses a client
secret; if you chose a certificate in Part 3, skip to Step 4 and use **Send test email** in Part 8
instead.) Fill in the three values (keep the quotes): your **Directory (tenant) ID** and
**Application (client) ID** from Part 1, and the secret **Value** from Part 3. Replace
`you@example.com` with your own inbox.

```powershell
$TenantId = "PASTE-DIRECTORY-TENANT-ID"
$ClientId = "PASTE-APPLICATION-CLIENT-ID"
$Secret   = "PASTE-SECRET-VALUE"

$tok = Invoke-RestMethod -Method Post -Uri "https://login.microsoftonline.com/$TenantId/oauth2/v2.0/token" -Body @{ client_id=$ClientId; client_secret=$Secret; scope="https://graph.microsoft.com/.default"; grant_type="client_credentials" }
$tok.access_token.Substring(0,20)
```

If that prints 20 characters, authentication works. If it errors, the tenant ID or the secret is
wrong — most often the *Secret ID* was copied instead of the *Value*. Then send:

```powershell
$body = '{"message":{"subject":"Missivus pre-flight","body":{"contentType":"Text","content":"Graph app-only send OK"},"toRecipients":[{"emailAddress":{"address":"you@example.com"}}]},"saveToSentItems":false}'
Invoke-WebRequest -Method Post -Uri "https://graph.microsoft.com/v1.0/users/noreply@example.com/sendMail" -Headers @{ Authorization = "Bearer $($tok.access_token)" } -ContentType "application/json" -Body $body | Select-Object StatusCode
```

`StatusCode 202` and an email in your inbox from your company name = the Microsoft side is
done. `403` = the scope is still propagating (Method A: up to 2 hours; Method B: 5–10 minutes) or,
with Method B, admin consent is missing; `404` = the mailbox has not finished provisioning — wait
and retry.

### Step 4 — clean up and sign out

The secret was typed into this window, so clear it:

```powershell
Remove-Variable Secret,tok,body
Disconnect-ExchangeOnline
```

Then close the PowerShell window.

**Adding another tool later** (Uptime Kuma, a CRM, anything that sends as `noreply@`): give it its
own app registration (Parts 1–3), then repeat only the app-specific part of Step 2 with that app's
Application (client) ID. Method A: `New-ServicePrincipal` and `New-ManagementRoleAssignment` again,
reusing the `Missivus mailbox` scope you already created. Method B: only the
`New-ApplicationAccessPolicy` line, reusing the group.

---

## Part 6 — Install the app

Once Missivus is published in the Nextcloud App Store: go to **Apps**, search for *Missivus*, click
**Download and enable**. Until then, or on a server that installs apps by hand:

```bash
cd /var/www/nextcloud            # your Nextcloud directory
tar -xzf /path/to/missivus-<version>.tar.gz -C apps/     # or custom_apps/ if you use one
chown -R www-data:www-data apps/missivus
sudo -u www-data php occ app:enable missivus
```

Enabling the app on its own changes nothing: Missivus starts switched off, and Nextcloud keeps
sending email exactly as it did before.

---

## Part 7 — Configure Nextcloud

Go to **Administration settings → Missivus** and fill in:

| Field | Value |
| --- | --- |
| Directory (tenant) ID | from Part 1 |
| Application (client) ID | from Part 1 |
| Authentication method | **Client secret** (or **Certificate** if you chose Option B) |
| Client secret | the **Value** you copied in Part 3 |
| Sender mailbox | `noreply@example.com` from Part 4 |
| From address (optional) | leave empty, or an alias of the sender mailbox — needs a **user** mailbox (see below) |

Tick **Send Nextcloud email through Microsoft Graph** and click **Save**. That is all the
configuration Missivus needs.

### Optional: keep credentials in config.php instead

If you would rather your credentials lived with your configuration than in Nextcloud's database —
which suits a server you deploy declaratively — add a `missivus` array to `config/config.php`:

```php
'missivus' => [
  'enabled' => true,
  'tenant_id' => '00000000-0000-0000-0000-000000000000',
  'client_id' => '00000000-0000-0000-0000-000000000000',
  'auth_method' => 'secret',
  'client_secret' => 'the Value you copied in Part 3',
  'sender_mailbox' => 'noreply@example.com',
  // 'from_address' => 'kontakt@example.com', // optional alias of the sender mailbox
],
```

For a certificate instead, replace the credential lines with:

```php
  'auth_method' => 'certificate',
  'certificate_path' => '/etc/nextcloud/secrets/missivus.pem',
```

Anything set here appears in the settings page marked *Set in config.php*, cannot be edited from
the UI, and is never written to Nextcloud's database
(`certificate_passphrase` exists too, for an encrypted key; `save_to_sent` and
`fallback_to_system` for the two toggles).

### The From address

Application-only sending can only send as the mailbox Exchange has scoped the app to, so Missivus
forces the From address to be the one you configured — the **Sender mailbox**, or the optional
**From address** if you set one. Whatever an app asked for instead is preserved as Reply-To, and a
warning naming both addresses goes to the log. To keep the log quiet, make Nextcloud's own From
address match, in `config/config.php`:

```php
'mail_from_address' => 'noreply',
'mail_domain' => 'example.com',
```

#### Sending from an alias

If Nextcloud's mail should arrive from a different address than the mailbox — for example an alias
such as `kontakt@example.com` on the `noreply@example.com` mailbox — set **From address** to the
alias. The Graph call and the Exchange scope (RBAC role assignment or access policy) still target
the **Sender mailbox**; only the From header changes. This works only when:

- the tenant has `SendFromAliasEnabled` turned on
  (`Set-OrganizationConfig -SendFromAliasEnabled $true`), and
- the mailbox is a **user mailbox** (Part 4). Microsoft does not support sending from an alias of a
  shared mailbox.

Set it on the settings page, or from the command line (`--lazy` stores it the way the settings page
does), then check that Missivus sees it:

```bash
sudo -u www-data php occ config:app:set missivus from_address --lazy --value="kontakt@example.com"
sudo -u www-data php occ missivus:status
```

To go back to sending as the mailbox itself, clear it:
`sudo -u www-data php occ config:app:delete missivus from_address`.

A value that is not an email address is rejected on the settings page. If one gets in another way
(`occ`, `config.php`), Missivus refuses to send and names the problem, without contacting Microsoft.

If a message asks for the Sender mailbox itself as its From address while a From address is
configured, Missivus treats the two as the same identity: no warning, no Reply-To.

Point `mail_from_address`/`mail_domain` at the alias to keep the log quiet, then send a test email
and check the delivered From line — a `202` only means Microsoft accepted the message, not that it
kept the alias. If Exchange refuses the alias, the error says so and names both causes (the alias is
not on that mailbox, or `SendFromAliasEnabled` is off); see the table below.

---

## Part 8 — Test it

On the same settings page, enter your address next to **Send test email** and click the button.

The button is disabled until the **saved** settings are complete, and the page tells you what is
missing. The test sends with what is stored, not with what is on screen, so if you have just typed
something into a field, click **Save** first.

- **Success** — you will have an email within a minute. Microsoft accepting the message is not
  quite the same as delivering it, so do check it actually arrives.
- **Failure** — the exact error Microsoft returned is shown on the page. See the table below.

The same test runs from the command line, along with a configuration overview:

```bash
sudo -u www-data php occ missivus:test you@example.com
sudo -u www-data php occ missivus:status
```

As a second, independent check, log out and use **Forgot password?** on the login page. That
exercises the real system-mail path — a password reset built and sent by Nextcloud core — rather
than the test button.

---

## When it does not work

| What you see | What it means | Fix |
| --- | --- | --- |
| `AADSTS7000215: Invalid client secret provided` | The secret is wrong, or you copied the Secret ID instead of the Value | Create a new secret and copy the **Value** column |
| `AADSTS700027` or `invalid_client` with a certificate | The certificate Nextcloud is using is not the one uploaded to Entra | Re-upload `missivus.crt`; check the certificate path points at the PEM holding **both** the key and the certificate |
| `AADSTS900023: Specified tenant identifier is not valid` | Wrong tenant ID | Recopy **Directory (tenant) ID** from the Overview page |
| `ErrorAccessDenied` / `Access is denied` on send | The app's scope does not cover this mailbox, the permission was never granted, or a new assignment has not propagated yet | Method A (RBAC): re-run `Test-ServicePrincipalAuthorization` from Part 5 and allow up to 2 hours for Exchange's cache. Method B (policy): re-run `Test-ApplicationAccessPolicy` from Part 5 and re-check the green tick in Part 2 |
| "Microsoft refused to send as the From address … (`ErrorSendAsDenied`)" | The **From address** is not an alias of the **Sender mailbox**, or `SendFromAliasEnabled` is off in Exchange, or the mailbox is a shared mailbox | Check the alias is on that mailbox (Part 4); run `Get-OrganizationConfig \| Format-List SendFromAliasEnabled` and, if it says False, `Set-OrganizationConfig -SendFromAliasEnabled $true`; use a **user** mailbox, not a shared one; or clear **From address** to send as the mailbox itself |
| `ErrorAccessDenied` only on large attachments | `Mail.ReadWrite` is missing | Method A: add the `Application Mail.ReadWrite` role assignment from Part 5. Method B: add it in Part 2 and grant admin consent again |
| `MailboxNotEnabledForRESTAPI` | The sender address is not a real Exchange Online mailbox | Check the mailbox exists and the address is spelled right |
| `The mailbox is either inactive, soft-deleted, or is hosted on-premise` | The mailbox has not finished provisioning | Wait fifteen minutes and try again |
| `certificate file is not readable at …` | File permissions | `chown root:www-data` and `chmod 640` the PEM, and check the path |
| `the private key … could not be loaded` | Wrong passphrase, or the PEM has no `PRIVATE KEY` block | Rebuild the PEM: `cat missivus.key missivus.crt > missivus.pem` |
| Emails still arrive from the old route, no error | Missivus is switched off, so Nextcloud used its own mailer | Tick **Send Nextcloud email through Microsoft Graph** and save |
| The **Send test email** button is greyed out | The saved settings are incomplete | Read the note above the button — it names what is missing. Fill it in and click **Save** |

Missivus writes every failure to Nextcloud's log at error level with `"app":"missivus"`
(**Administration settings → Logging**, or `data/nextcloud.log`), and the most recent failure is
shown at the top of the settings page. Secrets are redacted before anything is logged.

If Entra rejects the certificate assertion with a signature error and you have checked everything
above, add `'certificate_algorithm' => 'RS256'` to the `missivus` array in `config.php`. Missivus
signs with PS256 by default, which is what Microsoft's current documentation specifies; RS256 is
the older algorithm that Entra also accepts. This should not be necessary — please open an issue if
it is.

---

## Upgrading from 0.1.1 to 0.2.0

0.2.0 adds one optional setting, **From address** (Part 7). Nothing else about your setup changes:

- **Your settings are kept.** They live in Nextcloud's configuration, not in the app folder, and
  there is no database migration.
- **Empty From address = unchanged behaviour.** Until you set it, mail is sent exactly as it was
  with 0.1.1.
- You do not need to touch Entra or Exchange to upgrade. You only need to if you want to start
  sending from an alias (Part 4 and Part 7), or to move from an Application Access Policy to RBAC
  for Applications (Part 5, Method A), which is optional and independent of this upgrade.

**From the Nextcloud App Store** (once 0.2.0 is published there):

```bash
cd /var/www/nextcloud            # your Nextcloud directory
sudo -u www-data php occ app:update missivus
```

**From a tarball** (a server that installs apps by hand):

```bash
cd /var/www/nextcloud            # your Nextcloud directory
# 1. Keep the old version OUTSIDE the apps folder; Nextcloud loads everything inside it.
sudo mv apps/missivus /root/missivus-0.1.1-backup       # or custom_apps/ if you use one
# 2. Unpack the new version in its place.
sudo tar -xzf /path/to/missivus-0.2.0.tar.gz -C apps/    # or custom_apps/
sudo chown -R www-data:www-data apps/missivus
# 3. Let Nextcloud notice the new version.
sudo -u www-data php occ upgrade
```

Then, for either route:

```bash
sudo -u www-data php occ app:list | grep missivus      # should show 0.2.0
sudo -u www-data php occ missivus:status               # your settings, unchanged; from_address shows (not set)
sudo -u www-data php occ missivus:test you@example.com # a real email through Graph
```

If you want an alias as the From address, set it now (Part 7) and run the test again, then check
the From line of the email that arrives.

**Rolling back.** Put the backed-up folder back in `apps/`. Version 0.1.1 does not know the
`from_address` setting and ignores it, so there is nothing to clear first.

---

## Keeping it working

- **Diary the credential expiry.** A certificate or secret that expires stops mail dead. Replace it
  a week early: upload the new certificate, change the path, test, then delete the old one from
  Entra.
- **After a Nextcloud upgrade**, press **Send test email** once. `PLAN.md` lists exactly which
  Nextcloud internals this app depends on if something does break.
- **To turn it off**, untick the setting, or disable the app. Nextcloud reverts to its own mailer
  immediately, with nothing to clean up.

---

## Getting help

Open an issue at
<https://github.com/Solvetus/missivus-nextcloud/issues>. Never paste a client secret,
a certificate, or a PEM file into an issue.

If you would rather have this set up for you, [Solvetus](https://solvetus.com) does paid
installation and support.
