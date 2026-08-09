# Google Sheets / Docs Export Setup

This guide covers how to configure Google Cloud OAuth so users can export task reports directly to their own Google Sheets and Google Docs. Unlike [Google Meet Transcription](google-meet-transcription-setup.md) (a single service account configured per organization), this is a **per-user connection** — each user connects their own Google account under **Settings > Integrations**, and exported files land in that user's own Drive.

---

## Prerequisites

- A **Google Cloud Project** (a free one is fine — there is no per-request cost for the Sheets/Docs/Drive APIs used here)
- Access to configure the project's OAuth consent screen and credentials

---

## Step 1: Enable the APIs

1. Go to the [Google Cloud Console](https://console.cloud.google.com/).
2. Select (or create) the project you want to use.
3. Navigate to **APIs & Services > Library**.
4. Search for and enable each of:
   - **Google Sheets API**
   - **Google Docs API**
   - **Google Drive API**

---

## Step 2: Configure the OAuth Consent Screen

Google redesigned this part of the console in 2026 — it's no longer a single "OAuth consent screen" page. It now lives under **Google Auth Platform** in the left sidebar (visit [console.cloud.google.com/auth/overview](https://console.cloud.google.com/auth/overview) and make sure the correct project is selected at the top), split across a few tabs:

1. **Branding** — app name, support email, logo. Fill in whatever's required here; none of it affects functionality.
2. **Audience** — this is where **User Type** (External vs. Internal) now lives, along with the **Publish app** button.
   - Choose **External** unless this is a Google Workspace org and you want to restrict the app to only your own domain's users (**Internal**).
   - While the app's status here is **Testing**, only the test users you explicitly list on this tab can connect — add your own Google account (and anyone else who needs to connect) under **Test users**.
   - Once you're ready for real use, click **Publish app** to move to **In production**. While still in **Testing**, Google silently expires refresh tokens after 7 days, which would make every user's connection quietly stop working on a weekly basis. Because `drive.file` (see below) is a non-sensitive scope, publishing to production does not trigger Google's manual security review.
3. **Data Access** — this is where you add scopes. Click **Add or remove scopes** and add:

   ```
   https://www.googleapis.com/auth/drive.file
   ```

   This is Google's "non-sensitive" scope tier — it only grants access to files this app itself creates, never a user's existing Drive contents. It covers creating and writing to both Sheets and Docs, so it's the only scope Projector ever requests.

---

## Step 3: Create an OAuth Client ID

1. Still under **Google Auth Platform**, click **Clients** in the left sidebar.
2. Click **Create client** (if you haven't already — if you already created one, click into it instead to edit it).
3. Choose **Web application** as the application type.
4. Under **Authorized redirect URIs**, add:
   - `https://your-production-domain.com/settings/integrations/google/callback` (production — your real domain)

   **Do not use Herd's local `.test` domain here** — Google rejects it outright ("must end with a public top-level domain") regardless of scheme, because `.test` is a reserved, non-public TLD. Google only accepts `localhost`/`127.0.0.1` or a real public domain. See **Testing the connect flow locally** below for how to work around this during local development.
5. Save. The **Client ID** and **Client Secret** are shown on the client's detail page (click into the client from the **Clients** list if you closed the creation dialog already).

### Testing the connect flow locally

**`http://projector.test` (or `https://projector.test`) can never be used as a redirect URI.** This isn't a config mistake to fix — Google's console refuses to let you *register* any `.test` URL at all ("must end with a public top-level domain"), and separately refuses plain HTTP for anything other than `localhost`/`127.0.0.1`. No `.env` change or server restart works around this; it's enforced by Google before the URL can even be saved.

The one procedure that reliably works, because it never tries to register a `.test` domain:

1. Open the **Herd** menu bar app, click the **projector** site, click **Share**. Herd gives you a temporary public HTTPS URL (something like `https://random-name.example.com` — not `.test`). Copy it.
2. Append the callback path to get your redirect URI: `<that URL>/settings/integrations/google/callback`.
3. In **Google Auth Platform > Clients** > your Web client, click **+ Add URI** under **Authorized redirect URIs**, paste that exact URL, and **Save**.
4. Set `GOOGLE_REDIRECT_URI` in `.env` to that same exact URL.
5. Reload config by restarting the site's process **from the Herd app** (right-click the site → Restart) — not by running `artisan serve`/`octane:start` yourself. Octane keeps config loaded in memory across requests, so editing `.env` alone doesn't take effect until the process restarts.
6. Test by visiting the Herd Share URL (not `projector.test`) in your browser and going through **Settings > Integrations > Connect Google Account** there. It's the same local app and database either way — you're just reaching it through a public front door instead of `.test`.

Share URLs are temporary and change each time you start a new share session, so steps 1-5 need repeating next time. If that's too much friction for routine local dev, the simpler alternative is to skip testing the live redirect locally altogether — everything except that one manual click-through (route wiring, token storage, refresh logic, the export buttons) is already covered by the automated test suite (`tests/Feature/GoogleIntegrationTest.php`), so it's enough to verify the actual Google screen once against production.

---

## Step 4: Configure Projector

Add the following to `.env`:

```
GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=https://your-domain.com/settings/integrations/google/callback
```

`GOOGLE_REDIRECT_URI` must exactly match one of the redirect URIs authorized in Step 3.

---

## Step 5: Connect an Account

1. In Projector, go to **Settings > Integrations**.
2. Click **Connect Google Account** and complete Google's consent screen.
3. From a project's **Reports** tab, search for tasks, then use the **Google Sheets** or **Google Docs** export buttons — each opens the newly created file in a new tab, landing in the connected account's own Drive.

Disconnecting (also under **Settings > Integrations**) simply deletes the stored token; it does not revoke access on Google's side or delete any previously exported files.

---

## Troubleshooting

| Problem | Resolution |
|---------|-----------|
| `Error 400: invalid_request` — "Missing required parameter: client_id" | `GOOGLE_CLIENT_ID` (and/or `GOOGLE_CLIENT_SECRET`) is blank in `.env`, or the app hasn't picked up a recent `.env` change — run `php artisan config:clear` after editing `.env`. |
| "Invalid Redirect: must end with a public top-level domain" (in the Cloud Console, while adding a redirect URI) | You tried to add a `.test` (or otherwise non-public) hostname as a redirect URI — Google rejects these entirely, and always will, regardless of scheme. See **Testing the connect flow locally** in Step 3 above — never point `GOOGLE_REDIRECT_URI` at `projector.test`. |
| `Error 400: invalid_request` — "Access blocked... doesn't comply with Google's OAuth 2.0 policy for keeping apps secure" | `GOOGLE_REDIRECT_URI` is pointed at a plain-HTTP, non-localhost host (e.g. `http://projector.test`) — Google requires HTTPS for anything other than `localhost`/`127.0.0.1`. Same root cause as the row above: `.test` can't be used at all. See **Testing the connect flow locally**. |
| `Access blocked: [app] has not completed the Google verification process` / your account isn't listed | The app is still in **Testing** publishing status (Step 2, Audience tab) and the Google account you're signing in with isn't added under **Test users** on that same tab. Add it, or publish the app. |
| Export button redirects to Google every time instead of exporting | The stored refresh token was likely revoked or expired (see the "Testing" mode note in Step 2) — reconnect via **Settings > Integrations**. |
| `invalid_grant` errors in logs | The user revoked the app's access from their [Google Account permissions](https://myaccount.google.com/permissions), or the consent screen was left in Testing mode and the token expired after 7 days. Projector automatically clears the stored connection when this happens; the user just needs to reconnect. |
| `403` on create | Confirm the Sheets/Docs/Drive APIs are all enabled on the Cloud project (Step 1). |
| Redirect URI mismatch error from Google | `GOOGLE_REDIRECT_URI` in `.env` must exactly match an authorized redirect URI on the OAuth client (Step 3), including scheme and trailing path. |
