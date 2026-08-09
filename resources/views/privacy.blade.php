<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy - Projector</title>
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 640px; margin: 0 auto; padding: 3rem 1.5rem; color: #1e293b; line-height: 1.6; }
        .brand { display: flex; align-items: center; gap: 0.6rem; }
        .brand svg { width: 2rem; height: 2rem; flex-shrink: 0; }
        h1 { font-size: 1.75rem; font-weight: 900; letter-spacing: -0.02em; margin: 0; }
        .updated { color: #94a3b8; font-size: 0.8rem; margin-top: 0.5rem; margin-bottom: 2rem; }
        h2 { font-size: 1.15rem; font-weight: 700; margin-top: 2rem; }
        a { color: #c63615; }
        code, .scope { background: #f1f5f9; padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.9em; }
        ul { padding-left: 1.25rem; }
    </style>
</head>
<body>
    <div class="brand">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 500 500">
            <circle fill="#1c1210" cx="249.4" cy="249.7" r="239.5"/>
            <g>
                <path fill="#e84924" d="M303.5,335.5h-74.4c-13.6,0-24.6-11-24.6-24.6s11-24.6,24.6-24.6h74.4c40.1,0,72.7-32.6,72.7-72.7s-32.6-72.7-72.7-72.7h-169.6c-13.6,0-24.6-11-24.6-24.6s11-24.6,24.6-24.6h169.6c67.2,0,121.9,54.7,121.9,121.9s-54.7,121.9-121.9,121.9Z"/>
                <path fill="#fff" d="M334.5,208.3l-9.9-5.7-23.6-13.6-34.9-20.2c-4-2.3-9,.6-9,5.2v14.9h-71.2c-34.6,0-62.8,28.1-62.8,62.8v144.7c0,13.6,11,24.6,24.6,24.6s24.6-11,24.6-24.6v-144.7c0-7.5,6.1-13.6,13.6-13.6h71.2v14.9c0,4.6,5,7.6,9,5.2l34.9-20.2,23.6-13.6,9.9-5.7c4-2.3,4-8.1,0-10.4Z"/>
            </g>
        </svg>
        <h1>Privacy Policy</h1>
    </div>
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
