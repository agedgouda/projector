<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $document->name }}</title>
    <style>
        @page {
            margin: 100px 60px 80px 60px;
        }

        body {
            font-family: 'Helvetica', sans-serif;
            font-size: 12px;
            color: #1e293b;
            line-height: 1.5;
        }

        .header {
            position: fixed;
            top: -80px;
            left: 0;
            right: 0;
            height: 60px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 12px;
        }

        .header table {
            width: 100%;
        }

        .header .logo {
            width: 60px;
        }

        .header .logo img {
            max-height: 48px;
            max-width: 140px;
        }

        .header .names {
            vertical-align: middle;
            padding-left: 16px;
        }

        .header .project-name {
            font-size: 16px;
            font-weight: bold;
            color: #0f172a;
        }

        .header .client-name {
            font-size: 10px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .footer {
            position: fixed;
            bottom: -60px;
            left: 0;
            right: 0;
            height: 40px;
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
            font-size: 9px;
            color: #94a3b8;
        }

        .footer table {
            width: 100%;
        }

        .footer .page-number:after {
            content: counter(page);
        }

        .header-image {
            max-width: 100%;
            max-height: 70px;
        }

        .footer-image {
            max-width: 100%;
            max-height: 40px;
        }

        .doc-title {
            font-size: 20px;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .doc-meta {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #6366f1;
            margin-bottom: 24px;
        }

        .content {
            font-size: 12px;
        }

        .content h1, .content h2, .content h3 {
            color: #0f172a;
        }

        .content ol, .content ul {
            margin-left: 18px;
        }
    </style>
</head>
<body>
    <div class="header">
        @if ($headerImagePath)
            <img src="{{ $headerImagePath }}" class="header-image" alt="">
        @else
            <table>
                <tr>
                    @if ($logoPath)
                        <td class="logo">
                            <img src="{{ $logoPath }}" alt="{{ $project->name }}">
                        </td>
                    @endif
                    <td class="names">
                        <div class="project-name">{{ $project->name }}</div>
                        @if ($client)
                            <div class="client-name">{{ $client->company_name }}</div>
                        @endif
                    </td>
                </tr>
            </table>
        @endif
    </div>

    <div class="footer">
        @if ($footerImagePath)
            <img src="{{ $footerImagePath }}" class="footer-image" alt="">
        @else
            <table>
                <tr>
                    <td>Generated {{ now()->format('F j, Y') }}</td>
                    <td style="text-align: right;">Page <span class="page-number"></span></td>
                </tr>
            </table>
        @endif
    </div>

    <div class="doc-title">{{ $document->name }}</div>
    <div class="doc-meta">{{ $typeLabel }}</div>

    <div class="content">
        {!! $document->content ?: '<p>No content provided.</p>' !!}
    </div>
</body>
</html>
