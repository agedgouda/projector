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
        .brand { display: flex; align-items: center; gap: 0.6rem; }
        .brand svg { width: 2rem; height: 2rem; flex-shrink: 0; }
        h1 { font-size: 1.75rem; font-weight: 900; letter-spacing: -0.02em; margin: 0; }
        .tagline { color: #64748b; margin-top: 0.5rem; margin-bottom: 2rem; }
        h2 { font-size: 1.15rem; font-weight: 700; margin-top: 2rem; }
        a { color: #c63615; }
        code { background: #f1f5f9; padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.9em; }
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
        <h1>Projector</h1>
    </div>
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
