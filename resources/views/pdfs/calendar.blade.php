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

        table.calendar-grid td.out-of-month .day-number {
            color: #cbd5e1;
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

        {{-- Matches the on-screen calendar's actual bar backgrounds: the Projector primary-50
             token for untagged/non-subproject items, and each named tag/sub-project color's own
             10%-opacity wash (kanbanCardBg's `bg-{color}-500/10`, flattened onto white here since
             dompdf doesn't support alpha-blended backgrounds) for everything else. --}}
        .color-primary { background: #fdf4f1; }
        .color-slate { background: #f0f1f3; }
        .color-red { background: #fdecec; }
        .color-amber { background: #fff9e9; }
        .color-emerald { background: #e7f8f2; }
        .color-blue { background: #ebf3fe; }
        .color-purple { background: #f6eefe; }
        .color-pink { background: #fdedf5; }
        .color-orange { background: #fef1e8; }
        .color-indigo { background: #eff0fe; }
        .color-teal { background: #e8f8f6; }

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
            External due dates
        @else
            Due dates
        @endif
    </div>

    <div class="month">
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
                                <div class="day-number">{{ $cell['day'] }}</div>
                                @foreach ($cell['markers'] as $marker)
                                    <div class="marker color-{{ $marker['color'] }}">
                                        {{ $marker['name'] }}
                                        @if ($marker['isSubproject'])
                                            <div class="sub-tag">{{ $marker['projectName'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</body>
</html>
