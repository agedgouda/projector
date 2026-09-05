# Slack Integration Setup

This guide covers how to configure a Slack app so an organization can connect its Slack workspace to Projector — creating tasks and events, and importing files, from Slack. Unlike [Google Drive export](google-drive-export-setup.md) (a per-user connection), this is a **per-organization connection**: one org-admin installs the app into the org's Slack workspace from that organization's own settings page, and every bound channel then acts on behalf of that organization.

This first phase only covers connecting a workspace. Slash commands, message shortcuts, and file import land in later phases and will extend the same app rather than requiring a new one.

---

## Step 1: Create the Slack App

1. Go to [api.slack.com/apps](https://api.slack.com/apps) and click **Create New App**.
2. Choose **From an app manifest**, pick the workspace you'll use for development, and paste in the manifest below (YAML tab).
3. Review and click **Create**.

```yaml
display_information:
  name: Projector
  description: Create tasks and events, and import files, from Slack.
features:
  bot_user:
    display_name: Projector
    always_online: true
oauth_config:
  redirect_urls:
    - https://your-domain.com/organizations/slack/callback
  scopes:
    bot:
      - chat:write
      - commands
      - files:read
      - channels:history
      - channels:read
      - groups:read
      - users:read
settings:
  event_subscriptions:
    request_url: https://your-domain.com/slack/events
  org_deploy_enabled: false
  socket_mode_enabled: false
  token_rotation_enabled: false
```

Replace `your-domain.com` with your real domain — see **Testing locally** below for local development, since Slack (like Google) requires a public HTTPS URL it can reach.

**About the redirect URL having no organization in it:** Slack requires `redirect_uri` to exactly match one of the app's own pre-registered URLs — there's no wildcard support, so a per-organization path (e.g. `/organizations/{id}/slack/callback`) can't be registered ahead of time for every organization that will ever connect. Instead, one fixed callback URL is shared by every organization, and Projector tracks which organization started the flow via session state (the same `state` parameter that also guards against CSRF) rather than the URL itself. This is also why there's no `SLACK_REDIRECT_URI` env var to configure — the app derives this fixed URL from its own route rather than a per-environment setting, so it's automatically correct on whatever domain you're testing through.

**If you already installed the app before this scope list changed:** a previously-issued bot token only has whatever scopes existed at install time — Slack doesn't retroactively grant new ones. Update the manifest (or add scopes individually under **OAuth & Permissions > Scopes**) first, then reconnect from the organization's settings page again; the OAuth install flow re-authorizes and overwrites the stored token with one that has the full current scope set.

**About `features.bot_user`:** Slack requires an app to have a bot user defined before it will grant any bot token scopes (`chat:write`, `commands`, etc.) — without it, OAuth fails with "requires a bot_user for the bot scope". `display_name` is just what shows up in Slack's UI (e.g. in the app directory and DMs); it doesn't have to match anything in this codebase.

**About the Event Subscriptions Request URL:** Slack sends a one-time verification handshake to this URL the moment you enter it, and refuses to save it unless Projector answers correctly. `/slack/events` already implements this handshake, so the URL should verify successfully as soon as `SLACK_SIGNING_SECRET` (Step 3) is set — even though no real event types are subscribed to yet.

---

## Step 2: Note the App Credentials

From the app's **Basic Information** page, under **App Credentials**:

- **Client ID**
- **Client Secret**
- **Signing Secret**

You'll need all three for Step 3.

---

## Step 3: Configure Projector

Add the following to `.env`:

```
SLACK_CLIENT_ID=your-client-id
SLACK_CLIENT_SECRET=your-client-secret
SLACK_SIGNING_SECRET=your-signing-secret
```

### Testing locally

As with Google, **`http://projector.test` (or `https://projector.test`) cannot be used as Slack's redirect URL or Events Request URL** — both must be a real, publicly reachable HTTPS address. Use Herd's **Share** feature the same way the [Google setup guide](google-drive-export-setup.md#testing-the-connect-flow-locally) describes:

1. Herd menu bar app → **projector** site → **Share**, to get a temporary public HTTPS URL.
2. Update the app's **OAuth & Permissions > Redirect URLs** and **Event Subscriptions > Request URL** (api.slack.com/apps) to that URL's `/organizations/slack/callback` and `/slack/events` paths.
3. Restart the site's process from the Herd app (Octane keeps config in memory, so an `.env` edit alone doesn't take effect until restart).
4. Test via the Herd Share URL, not `projector.test`.

Share URLs change each new session, so step 2 needs repeating for further local testing (there's no `.env` value to update alongside it, per the note above) — the signature verification, OAuth token exchange, and storage logic are covered by the automated test suite, so a manual click-through is only needed to confirm the actual Slack screens.

---

## Step 4: Connect a Workspace

1. In Projector, go to that organization's settings/edit page (as an org-admin).
2. Click **Connect Slack Workspace** and approve the install on Slack's consent screen.
3. The page will show the connected workspace's name, and a link to manage channel bindings.

Disconnecting (from the same page) deletes the stored bot token from Projector; it does not uninstall the app from the Slack workspace's side — do that from Slack's own **Apps** settings if you want to fully remove it.
