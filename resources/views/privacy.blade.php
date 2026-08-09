<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy - Projector</title>
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 640px; margin: 0 auto; padding: 3rem 1.5rem; color: #1e293b; line-height: 1.6; }
        h1 { font-size: 1.75rem; font-weight: 900; letter-spacing: -0.02em; margin-bottom: 0.25rem; }
        .updated { color: #94a3b8; font-size: 0.8rem; margin-top: 0; margin-bottom: 2rem; }
        h2 { font-size: 1.15rem; font-weight: 700; margin-top: 2rem; }
        a { color: #c63615; }
        code, .scope { background: #f1f5f9; padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.9em; }
        ul { padding-left: 1.25rem; }
    </style>
</head>
<body>
    <h1>Privacy Policy</h1>
    <p class="updated">Last updated August 9, 2026</p>

    <h2>Overview</h2>
    <p>
        Projector is a project and task management application. This policy explains what data
        Projector collects, how it's used, and &mdash; specifically, since this is required for our
        use of Google APIs &mdash; what happens when you connect a Google account.
    </p>

    <h2>Google Account Connection</h2>
    <p>
        Projector optionally lets you connect your own Google account to export task reports
        directly to Google Sheets and Google Docs in your own Google Drive.
    </p>
    <p>This feature requests exactly one Google OAuth scope:</p>
    <p class="scope">https://www.googleapis.com/auth/drive.file</p>
    <p>This scope only grants Projector access to files that Projector itself creates. It does not grant:</p>
    <ul>
        <li>Access to any existing files already in your Google Drive</li>
        <li>Access to files created by any other application</li>
        <li>Any access to Gmail, Calendar, Contacts, or any other Google service</li>
    </ul>
    <p>
        When you click an export button, Projector uses this connection to create a new Google
        Sheet or Google Doc containing the task report you requested, and writes that report's
        data into it. That file is created directly in your own Google Drive &mdash; Projector does
        not retain a copy of the exported file's contents after creating it.
    </p>
    <p>
        Projector stores your Google access and refresh tokens, encrypted at rest, solely to
        maintain this connection between export requests. You can revoke this connection at any
        time from <strong>Settings &gt; Integrations</strong> within Projector, which deletes the
        stored tokens immediately. You can also revoke access directly from your
        <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">Google Account permissions page</a>.
    </p>

    <h2>Other Data Projector Collects</h2>
    <p>
        Projector stores the account, organization, project, document, and task data you and your
        organization create while using the app. Some document content may be processed by a
        configured AI provider (such as OpenAI, Google Gemini, or a self-hosted Ollama instance) to
        generate drafts, summaries, or embeddings as part of Projector's core workflow features.
    </p>

    <h2>Data Sharing</h2>
    <p>
        Projector does not sell your data or share it with third parties, other than the AI and
        infrastructure providers necessary to operate the features described above.
    </p>

    <h2>Contact</h2>
    <p>
        Questions about this policy or your data can be sent to
        <a href="mailto:jeffkaufman@kaufmaninternational.com">jeffkaufman@kaufmaninternational.com</a>.
    </p>
</body>
</html>
