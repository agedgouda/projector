<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $project->name }} Calendar</title>
    <style>
        @page {
            margin: 100px 40px 80px 40px;
        }

        body {
            font-family: 'Helvetica', sans-serif;
            font-size: 12px;
            color: #1e293b;
            line-height: 1.4;
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
            margin-bottom: 16px;
        }

        .month {
            margin-bottom: 24px;
        }

        .month-label {
            font-size: 16px;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 8px;
        }

        table.calendar-grid {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.calendar-grid th {
            text-align: left;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            border: 1px solid #e2e8f0;
            padding: 4px 6px;
            background: #f8fafc;
        }

        table.calendar-grid td {
            border: 1px solid #e2e8f0;
            padding: 3px 4px;
            vertical-align: top;
            height: 70px;
            width: 14.28%;
        }

        table.calendar-grid td.out-of-month {
            background: #f8fafc;
        }

        .day-number {
            font-size: 9px;
            font-weight: bold;
            color: #334155;
            margin-bottom: 2px;
        }

        .marker {
            font-size: 7.5px;
            font-weight: bold;
            padding: 1px 3px;
            border-radius: 2px;
            margin-bottom: 1px;
            color: #334155;
        }

        .marker .sub-tag {
            font-size: 6.5px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #64748b;
        }

        .marker .ext-tag {
            font-size: 6px;
            text-transform: uppercase;
            color: #92400e;
        }

        .color-primary { background: #e0e7ff; }
        .color-slate { background: #e2e8f0; }
        .color-red { background: #fee2e2; }
        .color-amber { background: #fef3c7; }
        .color-emerald { background: #d1fae5; }
        .color-blue { background: #dbeafe; }
        .color-purple { background: #ede9fe; }
        .color-pink { background: #fce7f3; }
        .color-orange { background: #ffedd5; }
        .color-indigo { background: #e0e7ff; }
        .color-teal { background: #ccfbf1; }

        .empty {
            color: #94a3b8;
            font-style: italic;
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

    <div class="doc-title">{{ $project->name }} Calendar</div>
    <div class="doc-meta">
        @if ($usesExternalDueDates)
            Internal &amp; external due dates
        @else
            Due dates
        @endif
    </div>

    @forelse ($months as $month)
        <div class="month" @if (! $loop->first) style="page-break-before: always;" @endif>
            <div class="month-label">{{ $month['label'] }}</div>
            <table class="calendar-grid">
                <thead>
                    <tr>
                        <th>Sun</th>
                        <th>Mon</th>
                        <th>Tue</th>
                        <th>Wed</th>
                        <th>Thu</th>
                        <th>Fri</th>
                        <th>Sat</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($month['weeks'] as $week)
                        <tr>
                            @foreach ($week as $cell)
                                <td class="{{ $cell['inMonth'] ? '' : 'out-of-month' }}">
                                    @if ($cell['inMonth'])
                                        <div class="day-number">{{ $cell['day'] }}</div>
                                        @foreach ($cell['markers'] as $marker)
                                            <div class="marker color-{{ $marker['color'] }}">
                                                {{ $marker['name'] }}
                                                @if ($marker['isExternal'])
                                                    <span class="ext-tag">Ext</span>
                                                @endif
                                                @if ($marker['isSubproject'])
                                                    <div class="sub-tag">{{ $marker['projectName'] }}</div>
                                                @endif
                                            </div>
                                        @endforeach
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p class="empty">No items with due dates.</p>
    @endforelse
</body>
</html>
