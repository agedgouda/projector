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

        {{-- Back to one month per page (see exportCalendarPdf()'s docblock): every month after
             the first starts on a fresh page, rather than flowing directly into whatever space
             was left after the previous one — the source of the previous layout's mismatched
             partial-month-at-the-bottom look. Applied via a Blade-conditional class (below)
             rather than a :not(:first-child) selector — dompdf's CSS selector support doesn't
             reliably handle :not(), so that rule silently never applied at all. --}}
        .month-break {
            page-break-before: always;
        }

        table.calendar-grid {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        {{-- The month label lives inside <thead> (as its own full-width row, above the Sun–Sat
             row) specifically so it repeats at the top of every page — dompdf repeats a table's
             <thead> on each page the table spans, same as the day-of-week row already did, but
             a label placed in a plain <div> above the table (the old layout) never repeated,
             leaving a continuation page with no month/column context at all. --}}
        table.calendar-grid thead th.month-label-cell {
            border: none;
            background: none;
            text-align: left;
            text-transform: none;
            letter-spacing: normal;
            font-size: 16px;
            font-weight: bold;
            color: #0f172a;
            padding: 0 0 8px 0;
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

        {{-- Each week (its day-number row plus every lane row under it) is its own <tbody> so
             dompdf can keep the group intact — a week that doesn't fit in the space left on a
             page moves to the next page whole, rather than splitting mid-week with the day
             numbers stranded on one page and its event bars orphaned on the next (see
             screenshot from the original bug report). --}}
        table.calendar-grid tbody.week {
            page-break-inside: avoid;
        }

        table.calendar-grid td {
            border: 1px solid #e2e8f0;
            padding: 2px 4px;
            vertical-align: top;
        }

        table.calendar-grid td.day-cell {
            height: 20px;
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
        }

        table.calendar-grid td.bar-cell {
            height: 16px;
            padding: 1px 2px;
        }

        .marker {
            font-size: 7.5px;
            font-weight: bold;
            padding: 1px 3px;
            border-radius: 2px;
            color: #334155;
            overflow: hidden;
            white-space: nowrap;
        }

        .marker .sub-tag {
            font-size: 6.5px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #64748b;
            margin-left: 4px;
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
        .color-yellow { background: #fdf7e6; }
        .color-lime { background: #f3fae8; }
        .color-green { background: #e9f9ef; }
        .color-cyan { background: #e6f8fb; }
        .color-sky { background: #e7f6fd; }
        .color-violet { background: #f3effe; }
        .color-fuchsia { background: #fbedfd; }
        .color-rose { background: #feecef; }

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

    @foreach ($months as $month)
        <div class="month {{ $loop->first ? '' : 'month-break' }}">
            <table class="calendar-grid">
                <colgroup>
                    @for ($i = 0; $i < 7; $i++)
                        <col style="width: 14.2857%;">
                    @endfor
                </colgroup>
                <thead>
                    <tr>
                        <th colspan="7" class="month-label-cell">{{ $month['label'] }}</th>
                    </tr>
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
                @foreach ($month['weeks'] as $week)
                    <tbody class="week">
                        <tr>
                            @foreach ($week['days'] as $day)
                                <td class="day-cell {{ $day['inMonth'] ? '' : 'out-of-month' }}">
                                    <div class="day-number">{{ $day['day'] }}</div>
                                </td>
                            @endforeach
                        </tr>
                        @foreach ($week['laneRows'] as $lane)
                            <tr>
                                @foreach ($lane as $slot)
                                    @if ($slot === null)
                                        <td class="bar-cell"></td>
                                    @elseif (! ($slot['skip'] ?? false))
                                        <td colspan="{{ $slot['span'] }}" class="bar-cell">
                                            <div class="marker color-{{ $slot['color'] }}">
                                                {{ $slot['name'] }}
                                                @if ($slot['isSubproject'])
                                                    <span class="sub-tag">{{ $slot['projectName'] }}</span>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    @endforeach
</body>
</html>
