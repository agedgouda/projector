# Google Meet Transcription Setup (Google Workspace)

This guide covers how to configure Google Workspace and the Google Meet API to enable automatic transcription of meetings. Once set up, credentials are entered directly in the **Organization edit page** in Projector.

---

## Prerequisites

- A **Google Workspace** account with super admin access
- A **Google Cloud Project** linked to your Workspace organization
- Billing enabled on the Cloud project
- Google Workspace **Business Standard** or higher (transcription is not available on Starter or the free tier)

---

## Step 1: Enable the Google Meet API

1. Go to the [Google Cloud Console](https://console.cloud.google.com/).
2. Select (or create) the project you want to use.
3. Navigate to **APIs & Services > Library**.
4. Search for **Google Meet API** and click **Enable**.

---

## Step 2: Enable Transcription in Google Workspace Admin

Transcription must be turned on at the Workspace admin level before it is available to users.

1. Sign in to the [Google Admin Console](https://admin.google.com/) as a super admin.
2. Go to **Apps > Google Workspace > Google Meet**.
3. Click **Meet video settings**.
4. Under the **Recording** section, enable **Let people record their meetings**.
5. Under the **Transcription** section, enable **Let people transcribe their meetings**.
6. Click **Save**.

> These settings can be scoped to a specific organizational unit (OU) if you don't want to enable them for your entire domain.

---

## Step 3: Create a Service Account

Projector accesses transcripts via a service account using domain-wide delegation.

1. In the Cloud Console, go to **IAM & Admin > Service Accounts**.
2. Click **Create Service Account**.
3. Give it a descriptive name (e.g., `projector-meet-transcripts`).
4. Click **Create and Continue**, then **Done**.
5. Click the service account, go to the **Keys** tab.
6. Click **Add Key > Create new key**, select **JSON**, and download the file.

The JSON file contains the `client_email` and `private_key` values you will need in the next steps.

---

## Step 4: Enable Domain-Wide Delegation

1. On the service account detail page, click **Edit** (pencil icon).
2. Expand **Advanced settings** and note the **Client ID**.
3. Go to the [Admin Console](https://admin.google.com/) and navigate to **Security > Access and data controls > API controls**.
4. Click **Manage Domain Wide Delegation > Add new**.
5. Enter the service account's **Client ID**.
6. Add the following OAuth scopes:

```
https://www.googleapis.com/auth/meetings.space.readonly
https://www.googleapis.com/auth/meetings.space.created
```

7. Click **Authorize**.

---

## Step 5: Configure the Organization in Projector

1. In Projector, go to **Organizations** and open the organization you want to configure.
2. Click **Edit**.
3. Under **Meeting Transcripts**, set **Meeting Provider** to **Google Meet**.
4. Fill in the following fields:

| Field | Where to find it |
|-------|-----------------|
| **Service Account Email** | The `client_email` value from the downloaded JSON key file |
| **Private Key (PEM)** | The `private_key` value from the JSON file — paste the full block including `-----BEGIN PRIVATE KEY-----` and `-----END PRIVATE KEY-----` |
| **Impersonate Email** | A Google Workspace user the service account will act on behalf of (requires domain-wide delegation to be active) — typically a super admin or a dedicated service user |

5. Click **Save Changes**.

---

## Transcript Availability

- Transcripts are generated after a meeting ends, provided the host had transcription enabled.
- There is typically a delay of a few minutes before the transcript is available via the API.

---

## Troubleshooting

| Problem | Resolution |
|---------|-----------|
| `403 insufficientPermissions` | Confirm domain-wide delegation scopes are saved in the Admin Console and the correct Client ID was entered. |
| `404` on transcript fetch | The meeting may not have had transcription enabled, or the transcript is not yet ready. |
| Service account can't impersonate user | Ensure the impersonate email belongs to an active Workspace user and domain-wide delegation is configured. |
| Transcription toggle missing in Admin | Your Workspace edition does not include transcription — requires **Business Standard** or higher. |
