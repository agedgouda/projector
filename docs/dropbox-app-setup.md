# Dropbox Integration Setup

This guide covers how to configure a Dropbox app so an organization can connect its Dropbox account to Projector — importing files dropped into a bound folder, the same way [Slack file import](slack-app-setup.md#step-10-import-tasks-events-or-other-documents-by-uploading-a-file) already works. Like Slack, this is a **per-organization connection**: one org-admin connects the org's Dropbox account from that organization's own settings page, and every bound folder then acts on behalf of that organization.

Unlike Slack, there's no per-user identity link and no in-channel reply — see `app/Models/DropboxWorkspace.php`'s own docblock for why Dropbox file imports can't reliably be attributed to a specific uploader the way a Slack message can, and `App\Services\Import\ImportNotifier` for how results are delivered instead (a Slack DM if the org's connecting admin has a linked Slack identity, otherwise email).

---

## Step 1: Create the Dropbox App

1. Go to [dropbox.com/developers/apps](https://www.dropbox.com/developers/apps) and click **Create app**.
2. Choose **Scoped access**.
3. Choose **Full Dropbox** access (not **App folder**) — a project can bind any existing folder in the connected account, not just one Dropbox creates specifically for this app.
4. Name the app (e.g. "Projector") and click **Create app**.

### Permissions

On the app's **Permissions** tab, enable:

- `account_info.read` — to read the connected account's name for display.
- `files.metadata.read` — to list what changed in a bound folder.
- `files.content.read` — to download an imported file's actual content.

Click **Submit** at the bottom of the Permissions tab to save.

**If you change scopes after users have already connected:** like Slack, a previously-issued access/refresh token only has whatever scopes existed at authorization time. Reconnecting from the organization's settings page again re-authorizes and overwrites the stored tokens with ones that have the full current scope set.

### OAuth redirect URI

On the **Settings** tab, under **OAuth 2**, add this **Redirect URI**:

```
https://projecthq.app/organizations/dropbox/callback
```

For local development, see **Testing locally** below — Dropbox (like Slack and Google) requires a public HTTPS URL it can reach, which `projecthq.app` only is in production.

**About the redirect URI having no organization in it:** same reason as Slack's — Dropbox requires `redirect_uri` to exactly match one of the app's own pre-registered URIs, with no wildcard support, so a per-organization path can't be pre-registered for every organization that will ever connect. One fixed callback URL is shared by every organization, and Projector tracks which organization started the flow via session state (the `state` parameter, which also guards against CSRF) rather than the URL itself.

### Webhook URI

Still on the **Settings** tab, under **Webhooks**, add this **URI**:

```
https://projecthq.app/dropbox/events
```

**About the verification handshake:** the moment you add this URI, Dropbox sends a one-time `GET` request with a `?challenge=` query parameter and expects it echoed back verbatim as plain text — `/dropbox/events` already implements this (see `App\Http\Controllers\Dropbox\EventsController::handle()`), so it should verify successfully as soon as the URI is reachable. This handshake carries no signature (unlike the actual change notifications that follow), so it works even before `DROPBOX_CLIENT_SECRET` (Step 3) is set.

---

## Step 2: Note the App Credentials

From the app's **Settings** tab, under **OAuth 2**:

- **App key**
- **App secret**

You'll need both for Step 3.

---

## Step 3: Configure Projector

Add the following to `.env`:

```
DROPBOX_CLIENT_ID=your-app-key
DROPBOX_CLIENT_SECRET=your-app-secret
```

`DROPBOX_CLIENT_SECRET` does double duty: it's the OAuth client secret used to exchange an authorization code for tokens, *and* the signing key Dropbox uses for the `X-Dropbox-Signature` header on every webhook notification — Dropbox uses the one app secret for both roles, so there's no separate signing-secret variable the way Slack has `SLACK_SIGNING_SECRET`.

### Testing locally

As with Slack and Google, **`http://projector.test` (or `https://projector.test`) cannot be used as Dropbox's redirect URI or webhook URI** — both must be a real, publicly reachable HTTPS address. Use Herd's **Share** feature the same way the [Slack setup guide](slack-app-setup.md#testing-locally) describes:

1. Herd menu bar app → **projector** site → **Share**, to get a temporary public HTTPS URL.
2. Update the app's **OAuth 2 > Redirect URIs** and **Webhooks > URI** (dropbox.com/developers/apps) to that URL's `/organizations/dropbox/callback` and `/dropbox/events` paths.
3. Restart the site's process from the Herd app (Octane keeps config in memory, so an `.env` edit alone doesn't take effect until restart).
4. Test via the Herd Share URL, not `projector.test`.

Share URLs change each new session, so step 2 needs repeating for further local testing — the signature verification, OAuth token exchange, and storage logic are covered by the automated test suite, so a manual click-through is only needed to confirm the actual Dropbox screens.

---

## Step 4: Connect an Account

1. In Projector, go to that organization's settings/edit page (as an org-admin), Configuration tab.
2. Click **Connect Dropbox** and approve on Dropbox's consent screen.
3. The panel will show the connected account's name, its bound folders, and a form to bind more.

**Refresh tokens:** the connect flow requests offline access (`token_access_type=offline`), so Projector receives a long-lived refresh token alongside the short-lived access token. `App\Services\Dropbox\DropboxApiClient::ensureFreshToken()` checks the stored expiry before every API call and silently mints a fresh access token when needed — nothing to do manually, and nothing expires the way it would with access-token-only offline access.

---

## Step 5: Bind a Folder to a Project

The binding form's folder dropdown lists every top-level folder in the connected account (`DropboxApiClient::listTopLevelFolders()`, a `files/list_folder` call against the account root) — pick one and a project, same as Slack's channel picker. Only top-level folders are listed, not the full nested tree, so a subfolder can't be bound directly.

Once bound, dropping a file anywhere inside that folder (or a subfolder of it) triggers an import the same way dropping a file in a bound Slack channel does.

---

## Step 6: Import Files by Dropping Them in a Bound Folder

Behaves exactly like [Slack's file import](slack-app-setup.md#step-10-import-tasks-events-or-other-documents-by-uploading-a-file) — the same `App\Services\Import\FileImportProcessor` handles both, so read that section for the full behavior (spreadsheet classification/auto-import, document classification, the Needs Review queue). Two differences specific to Dropbox:

- **No message text.** Slack's `#tag` override can come from either the filename or an accompanying message; Dropbox files have no equivalent message, so only the filename is checked there. To make up for it, Dropbox has its own second override Slack doesn't: **drop the file into a subfolder named after a document type's short code** (e.g. `/Client Intake/meeting-notes/standup.docx`) and it's filed directly as that type — no `#` needed, since choosing a folder to drop a file into is already a deliberate act. Only the immediate subfolder counts; a file nested two levels deep isn't tagged this way.
- **No in-channel reply.** A Dropbox folder isn't a place to post a message back into, so results are delivered directly to whoever connected the account (`installed_by_user_id`) instead — a Slack DM if they have a linked Slack identity for this organization (Settings > Integrations), otherwise an email.

Same requirement as Slack: any other file type (images, PDFs, etc.) dropped in a bound folder is silently ignored — nothing about this changes how Dropbox itself behaves for anything else in that folder.
