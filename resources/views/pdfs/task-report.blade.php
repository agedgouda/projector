<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $project->name }} — Task Report</title>
    <style>
        @page {
            margin: 100px 40px 80px 40px;
        }

        body {
            font-family: 'Helvetica', sans-serif;
            font-size: 10px;
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

        .report-title {
            font-size: 18px;
            font-weight: bold;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .report-meta {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #6366f1;
            margin-bottom: 20px;
        }

        table.tasks {
            width: 100%;
            border-collapse: collapse;
        }

        table.tasks th {
            text-align: left;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            background-color: #f1f5f9;
            padding: 6px 8px;
            border-bottom: 1px solid #cbd5e1;
        }

        table.tasks td {
            padding: 6px 8px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        table.tasks tr:nth-child(even) td {
            background-color: #f8fafc;
        }

        .col-details {
            width: 30%;
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

    <div class="report-title">{{ $project->name }} — Task Report</div>
    <div class="report-meta">{{ $tasks->count() }} {{ Str::plural('Task', $tasks->count()) }}</div>

    <table class="tasks">
        <thead>
            <tr>
                <th>Status</th>
                <th>{{ $usesExternalDueDates ? 'Internal Due' : 'Due Date' }}</th>
                @if ($usesExternalDueDates)
                    <th>External Due</th>
                @endif
                <th>Task Name</th>
                <th>Assignee</th>
                <th>Priority</th>
                @if ($includeDetails)
                    <th class="col-details">Details</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @forelse ($tasks as $task)
                @php
                    $column = $columns->firstWhere('key', $task->task_status);
                    $assigneeName = $task->assignee?->name
                        ?? ($task->pendingAssignee ? trim(($task->pendingAssignee->first_name ?? '').' '.($task->pendingAssignee->last_name ?? '')) ?: $task->pendingAssignee->email : 'Unassigned');
                @endphp
                <tr>
                    <td>{{ $column?->label ?? $task->task_status ?? '—' }}</td>
                    <td>{{ $task->due_at ? \Illuminate\Support\Carbon::parse($task->due_at)->format('M j, Y') : '—' }}</td>
                    @if ($usesExternalDueDates)
                        <td>{{ $task->external_due_at ? \Illuminate\Support\Carbon::parse($task->external_due_at)->format('M j, Y') : '—' }}</td>
                    @endif
                    <td>{{ $task->name }}</td>
                    <td>{{ $assigneeName }}</td>
                    <td>{{ $task->priority ? ucfirst($task->priority) : '—' }}</td>
                    @if ($includeDetails)
                        <td class="col-details">{!! nl2br(e(Str::limit(strip_tags($task->content ?? ''), 600))) !!}</td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ 5 + ($usesExternalDueDates ? 1 : 0) + ($includeDetails ? 1 : 0) }}">No tasks match those filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
