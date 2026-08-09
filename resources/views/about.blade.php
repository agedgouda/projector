<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>About Projector</title>
    <meta name="description" content="Projector is a document-first, AI-assisted project and task management application.">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 640px; margin: 0 auto; padding: 3rem 1.5rem; color: #1e293b; line-height: 1.6; }
        h1 { font-size: 1.75rem; font-weight: 900; letter-spacing: -0.02em; margin-bottom: 0.25rem; }
        .tagline { color: #64748b; margin-top: 0; margin-bottom: 2rem; }
        h2 { font-size: 1.15rem; font-weight: 700; margin-top: 2rem; }
        a { color: #c63615; }
        code { background: #f1f5f9; padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.9em; }
    </style>
</head>
<body>
    <h1>Projector</h1>
    <p class="tagline">A document-first, AI-assisted project and task management application.</p>

    <h2>What Projector does</h2>
    <p>
        Projector helps teams turn raw meeting notes and transcripts into structured project
        documentation, action items, and tasks using admin-defined AI pipelines. Humans review and
        approve every AI-generated document before it becomes a task, and every task stays linked
        back to the document it came from for full traceability.
    </p>

    <h2>Google account connection</h2>
    <p>
        Projector optionally lets a user connect their own Google account to export task reports
        directly to Google Sheets and Google Docs, created in that user's own Google Drive. This
        requests exactly one permission &mdash; <code>https://www.googleapis.com/auth/drive.file</code>,
        access limited to files Projector itself creates &mdash; never access to a user's existing
        Drive contents or any other Google service.
    </p>

    <p>
        Full details on what data is collected and how it's used, including the Google integration,
        are in the <a href="/privacy">Privacy Policy</a>.
    </p>
</body>
</html>
