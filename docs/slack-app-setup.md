# Slack Integration Setup

This guide covers how to configure a Slack app so an organization can connect its Slack workspace to Projector — creating tasks and events, and importing files, from Slack. Unlike [Google Drive export](google-drive-export-setup.md) (a per-user connection), this is a **per-organization connection**: one org-admin installs the app into the org's Slack workspace from that organization's own settings page, and every bound channel then acts on behalf of that organization.

Outbound messages (the daily digest, Step 9) use only the `chat:write` scope already listed below — no manifest change is needed for that feature specifically.

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
  slash_commands:
    - command: /task
      url: https://projecthq.app/slack/commands
      description: Create a task from text
      usage_hint: "[description] — e.g. /task follow up with the client by Friday"
      should_escape: false
    - command: /events
      url: https://projecthq.app/slack/commands
      description: Create an event from text
      usage_hint: "[description] — e.g. /events team offsite next Thursday"
      should_escape: false
  shortcuts:
    - name: Create Task
      type: message
      callback_id: create_task
      description: Create a Projector task from this message
    - name: Create Event
      type: message
      callback_id: create_event
      description: Create a Projector event from this message
oauth_config:
  redirect_urls:
    - https://projecthq.app/organizations/slack/callback
    - https://projecthq.app/settings/integrations/slack/callback
  scopes:
    bot:
      - chat:write
      - commands
      - files:read
      - channels:history
      - channels:read
      - groups:read
      - users:read
    user:
      - identity.basic
      - identity.team
settings:
  event_subscriptions:
    request_url: https://projecthq.app/slack/events
    bot_events:
      - message.channels
  interactivity:
    is_enabled: true
    request_url: https://projecthq.app/slack/interactivity
  org_deploy_enabled: false
  socket_mode_enabled: false
  token_rotation_enabled: false
```

For local development, see **Testing locally** below — Slack (like Google) requires a public HTTPS URL it can reach, which `projecthq.app` only is in production.

**About the redirect URL having no organization in it:** Slack requires `redirect_uri` to exactly match one of the app's own pre-registered URLs — there's no wildcard support, so a per-organization path (e.g. `/organizations/{id}/slack/callback`) can't be registered ahead of time for every organization that will ever connect. Instead, one fixed callback URL is shared by every organization, and Projector tracks which organization started the flow via session state (the same `state` parameter that also guards against CSRF) rather than the URL itself. This is also why there's no `SLACK_REDIRECT_URI` env var to configure — the app derives this fixed URL from its own route rather than a per-environment setting, so it's automatically correct on whatever domain you're testing through.

**If you already installed the app before this scope list changed:** a previously-issued bot token only has whatever scopes existed at install time — Slack doesn't retroactively grant new ones. Update the manifest (or add scopes individually under **OAuth & Permissions > Scopes**) first, then reconnect from the organization's settings page again; the OAuth install flow re-authorizes and overwrites the stored token with one that has the full current scope set.

**About `features.bot_user`:** Slack requires an app to have a bot user defined before it will grant any bot token scopes (`chat:write`, `commands`, etc.) — without it, OAuth fails with "requires a bot_user for the bot scope". `display_name` is just what shows up in Slack's UI (e.g. in the app directory and DMs); it doesn't have to match anything in this codebase.

**About the Event Subscriptions Request URL:** Slack sends a one-time verification handshake to this URL the moment you enter it, and refuses to save it unless Projector answers correctly. `/slack/events` already implements this handshake, so the URL should verify successfully as soon as `SLACK_SIGNING_SECRET` (Step 3) is set.

**About the `message.channels` bot event:** this is the coarsest subscription Slack offers for detecting a file dropped into a channel — every message posted in every channel the bot is in arrives at `/slack/events`, not just file uploads. `EventsController` only acts on the one shape it cares about (a `message` event with `subtype: file_share`) and quietly logs anything else; nothing about this changes what Projector actually does with a normal text message.

**About the Interactivity Request URL:** unlike Event Subscriptions, Slack doesn't verify this one with a handshake when you save it — it's only hit later, when someone actually invokes a shortcut or submits a modal. `/slack/interactivity` handles both the "Create Task"/"Create Event" message shortcuts and the modal they open.

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
2. Update the app's **OAuth & Permissions > Redirect URLs**, **Event Subscriptions > Request URL**, **Slash Commands** (each command's Request URL), and **Interactivity & Shortcuts > Request URL** (api.slack.com/apps) to that URL's `/organizations/slack/callback`, `/settings/integrations/slack/callback`, `/slack/events`, `/slack/commands`, and `/slack/interactivity` paths.
3. Restart the site's process from the Herd app (Octane keeps config in memory, so an `.env` edit alone doesn't take effect until restart).
4. Test via the Herd Share URL, not `projector.test`.

Share URLs change each new session, so step 2 needs repeating for further local testing (there's no `.env` value to update alongside it, per the note above) — the signature verification, OAuth token exchange, and storage logic are covered by the automated test suite, so a manual click-through is only needed to confirm the actual Slack screens.

---

## Step 4: Connect a Workspace

1. In Projector, go to that organization's settings/edit page (as an org-admin).
2. Click **Connect Slack Workspace** and approve the install on Slack's consent screen.
3. The page will show the connected workspace's name, its bound channels, and the form to bind more.

**More than one Projector organization can connect the same real Slack workspace** — e.g. an agency running several client organizations, all in the agency's own Slack team. Each organization gets its own row, its own bot token, and its own channel bindings; which organization a Slack event belongs to is resolved from the specific channel it happened in (via that channel's binding), never assumed from the Slack team alone. A per-user Slack identity link (Step 5) works the same way across every organization connected to that team — link once, and it's recognized in all of them you belong to.

---

## Step 5: Link Your Own Slack Identity

Separate from the org-level bot install above: each Projector user who wants Slack-triggered actions (slash commands, message shortcuts) attributed to their own account needs to link their own Slack identity once. This uses `user_scope` only (`identity.basic`, `identity.team`) — no bot `scope` — so it works for any team member, not just org-admins, and never touches the bot install.

1. In Projector, go to **Settings > Integrations**.
2. Click **Connect Slack Account** and approve on Slack's consent screen — this shows a lighter "Sign in with Slack"-style prompt, not the full bot-permission screen from Step 4.
3. Projector checks that the Slack workspace you signed in with matches one already connected to an organization you belong to (Step 4 must happen first, for at least one organization); if it doesn't recognize the workspace, the link is rejected rather than silently accepted.
4. The page will show your connected identity (Slack username + workspace name). You can link identities for more than one workspace if you belong to multiple organizations, each with its own connected Slack workspace.

Disconnecting (from the same page) deletes the stored bot token from Projector; it does not uninstall the app from the Slack workspace's side — do that from Slack's own **Apps** settings if you want to fully remove it.

---

## Step 6: Create a Task with `/task`

Once a channel is bound to a project (Step 4) and you've linked your Slack identity (Step 5), run `/task <description>` in that channel — e.g. `/task follow up with the client about the contract by Friday, high priority`.

What happens:

1. You immediately see an ephemeral "⏳ Creating task…" (only you see this).
2. Projector sends the text to the same AI extraction used for document-based task imports, pulling out a title, description, assignee (matched by name against the project's org members), due date, priority, and tag.
3. Once done, a message is posted **in the channel** (visible to everyone) linking to the created task.

If the AI extraction fails for any reason, a task is still created — titled with your raw command text — rather than the command silently doing nothing.

Two error cases reply immediately, ephemerally, without creating anything:
- The channel isn't bound to a project yet (an org-admin needs to bind it first).
- You haven't linked your Slack identity yet (Step 5).

---

## Step 7: Create an Event with `/events`

Same setup and flow as `/task` — run `/events <description>` in a bound channel, e.g. `/events team offsite next Thursday`.

The one real difference: events aren't assigned to a person, so there's no name-matching step — Projector instead pulls out a title, description, start/due dates (including relative ones like "next Thursday"), and a tag. A Slack identity link is still required, since the event's *creator* (not an assignee) is still attributed to you.

---

## Step 8: Create a Task or Event from a Message with Shortcuts

Instead of retyping a message into `/task` or `/events`, use the message itself: hover a message in a bound channel, click **More actions** (the `⋯` icon), and choose **Create Task** or **Create Event**.

What happens:

1. A modal opens immediately, pre-filled with the message's text in an editable field — edit it before submitting if you want to trim it down or add detail the AI should pick up on.
2. Click **Create**. The modal closes right away; extraction runs the same way it does for the slash commands.
3. Once done, the bot posts the result **in the channel** (not just to you), since there's no `response_url` for a modal submission to reply through — it uses `chat.postMessage` as itself instead.

The same two requirements apply as the slash commands: the channel must be bound to a project, and you must have linked your Slack identity (Step 5) — either one missing shows an explanatory modal instead of the edit form.

---

## Step 9: The Daily Digest

Every org-admin who's linked their Slack identity (Step 5) automatically gets a Slack DM once a day, at 8am in *their own* local time: tasks due today across every project in that organization, or — if nothing's due today — the next 5 upcoming deliverables (overdue ones first). This is a background feature, not something a user has to turn on; the only setup involved is each admin setting their own timezone.

1. In Projector, go to **Settings > Profile** and set the **Timezone** field (defaults to UTC).
2. That's it — no Slack-side configuration, no new scopes, no manifest change. The digest reuses the same bot token and `chat.postMessage` delivery already set up for message shortcuts (Step 8).

An admin who administers more than one organization gets one separate DM per organization, each sent through that organization's own connected workspace. An admin with no linked Slack identity is silently skipped — they simply don't receive anything until they complete Step 5.

**Mechanics, for anyone debugging this:** `routes/console.php` schedules `app:send-slack-daily-digest` to run hourly (there's no single UTC time that's 8am for every admin, so the command itself checks each admin's local hour on every run). A `slack_digest_sends` row records each (organization, admin, local calendar day) it actually dispatches for, so an admin never gets two digests in the same local day even if the scheduler's hourly run ever overlaps itself.

---

## Step 10: Import Tasks or Events by Uploading a File

Drop a CSV, TXT, XLSX, or XLS file straight into a bound channel and Projector imports it — no slash command, no shortcut, nothing to click first, and no need to say whether it's a task list or an event list.

What happens:

1. As soon as the file finishes uploading, Projector downloads it and runs it through the same AI classification the web Import Wizard's "Import Data" (smart) option already uses — it reads the actual headers and sample rows to decide whether the file is tasks, events, or a genuine mix of both, proposing its own column mapping for each.
2. **The first time a project sees a given column mapping**, it isn't auto-imported — see "Needs Review" below instead. Once that same mapping has been confirmed for the project once (by anyone completing that review, whether it started from Slack or a plain manual upload), a future file with that same layout imports immediately with no confirmation step, matching how `/task` and `/events` also skip a review step in favor of just showing the result.
3. Once an auto-import completes, the bot replies **in the channel**: how many tasks and/or events were created, and a link to the project.
4. A file with no rows, or over 5,000 rows, gets a clear explanation instead of a half-finished import.

**Needs Review**: two different situations park a file here instead of importing it, each with its own reply in the channel:
- The AI couldn't confidently tell what the file even is (no usable name/title column found for any record type, or the classification call itself failed) — the bot explains it couldn't figure out how to import the file.
- The AI *could* classify it, but this project has never had a human confirm this particular column mapping before — the bot replies "Document Placed In Validation Queue — Click Here to Review".

Either way, the file shows up on the Import Wizard landing page (`/import`) under **Needs Review**, visible to anyone who can manage imports for that project. Opening a queued file re-parses it and opens the same AI-assisted mapping modal a manually-picked "smart" import uses, so a human finishes (or confirms) the mapping by hand — the file itself doesn't need to be re-uploaded, since it was already downloaded and stored when it was queued. Completing that review both imports the file and teaches the project that mapping, so the same layout auto-imports next time without a trip through the queue.

Same two requirements as everything else: the channel must be bound to a project, and you (the uploader) must have linked your Slack identity (Step 5) — the bot will tell you if the latter's missing. Any other file type (images, PDFs, etc.) is silently ignored — nothing about this changes how a normal file share in the channel behaves.

A future version will let someone upload a file and manually name the column mapping directly from Slack, without needing to visit the Import Wizard at all — for now, review always happens there.
