<?php

use App\Models\Faq;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Adds the task start date and task linking questions to "Tasks & Events" (after the
     * existing ones) and updates the answers that now behave differently — creating tasks,
     * the board, the Calendar, importing, Slack /task, and task reports.
     */
    public function up(): void
    {
        $order = (int) Faq::query()->where('category', 'Tasks & Events')->max('order');

        foreach ($this->newFaqs() as $faq) {
            Faq::query()->firstOrCreate(
                ['question' => $faq['question']],
                [...$faq, 'category' => 'Tasks & Events', 'order' => ++$order],
            );
        }

        foreach ($this->updatedAnswers() as $question => $values) {
            Faq::query()->where('question', $question)->update($values);
        }
    }

    public function down(): void
    {
        Faq::query()->whereIn('question', array_column($this->newFaqs(), 'question'))->delete();

        foreach ($this->originalAnswers() as $question => $values) {
            Faq::query()->where('question', $question)->update($values);
        }
    }

    /**
     * @return list<array{question: string, answer: string, keywords: string}>
     */
    private function newFaqs(): array
    {
        return [
            [
                'question' => 'What are task start dates?',
                'answer' => 'Tasks can have a Start Date as well as a Due Date. An Org Admin turns this on with "Track start dates on tasks" on the organization\'s Configuration tab; while it\'s off, start dates (and task linking) are hidden everywhere.

Once it\'s on, a task\'s Start Date appears next to its due date — in the task\'s panel, on its own page, on board cards, in the task List, and in task reports and their downloads. A few rules:
- The start date can\'t be after the due date.
- If a task already has both dates and you move its start date, the due date moves by the same number of days, so the task keeps its length. (Change both in one go and both are kept as you set them.)
- Start dates always pair with the internal due date, even when your organization also tracks external due dates.
- On the Calendar, a task with a start date shows as a bar running from its start to its due date.

Start dates can also come in from imports — see "How do I import a list of tasks or events from a spreadsheet?" under Importing.',
                'keywords' => 'start date, start, task dates, duration, track start dates, configuration, due date',
            ],
            [
                'question' => 'How do I link tasks so one starts when another ends?',
                'answer' => 'When your organization tracks task start dates (see "What are task start dates?"), you can link tasks into chains: a task can wait on another one, and it then starts the day that task is due.

There are two ways to link tasks:
- In a task\'s panel, use "Waits On" to pick the task it waits on, or "Followed By" to add tasks that wait on it. Click a linked task\'s name there to unlink it.
- On the Tasks tab\'s List view, drag a task by the handle at the left of its row and drop it onto the task it should wait on. Drop it on the "No dependency" strip that appears at the top to unlink it.

A few rules:
- A task waits on at most one other task, but any number of tasks can wait on it.
- Both tasks have to be in the same project (a sub-project counts as a different project).
- Tasks can\'t wait on each other in a loop — Projector won\'t let you link a task to one that\'s already waiting on it.
- A task that waits on another can\'t have its start date changed directly; it always starts the day the task it waits on is due. Unlink it first if you need to set its start yourself.
- Unlinking keeps a task\'s dates as they are. Deleting a task, or moving it to another board, unlinks anything that was waiting on it.',
                'keywords' => 'link tasks, linked tasks, dependency, dependencies, waits on, followed by, predecessor, chain, gantt, drag',
            ],
            [
                'question' => 'What happens to linked tasks when a date changes?',
                'answer' => 'Linked tasks move together. When a task\'s due date changes, every task waiting on it moves to start on the new date, and its own due date shifts by the same number of days so it keeps its length — and the same then happens to the tasks waiting on those, all the way down the chain. Boards, lists, reports, and the Calendar update right away.

If a task\'s due date is cleared, the tasks waiting on it lose their start date (their due dates stay). While an organization has start dates turned off, links are kept but have no effect; they pick up again if start dates are turned back on.',
                'keywords' => 'linked tasks, cascade, reschedule, move dates, shift, chain, dependency, due date change',
            ],
            [
                'question' => 'What\'s the List view on the Tasks tab?',
                'answer' => 'When your organization tracks task start dates, the Tasks tab has a Board / List switch above the search box. The Board is the usual columns of cards; the List shows the same tasks as compact rows, each task nested under the task it waits on, so you can see whole chains at a glance.

In the List you can:
- Collapse or expand a chain with the arrow beside a task.
- Change a task\'s status, start or due date, priority, or assignee right in its row, or click the row to open the task.
- Drag a task by the handle at the left of its row onto another task to make it wait on that task, or onto the "No dependency" strip to unlink it (see "How do I link tasks so one starts when another ends?").

The search, priority, and tag filters work in both views; the List always orders tasks by chain and date rather than by the sort menu. Projector remembers which view you last used in that browser.',
                'keywords' => 'list view, board view, tasks tab, switch, chains, nested, hierarchy, drag and drop, linked tasks',
            ],
        ];
    }

    /**
     * @return array<string, array{answer: string, keywords: string}>
     */
    private function updatedAnswers(): array
    {
        return [
            'How do I create a task?' => [
                'answer' => 'On a project\'s Tasks tab, click "New Task". Give it a name, and fill in any of the rest: a description, Assignee, Due Date, Priority, Status, and Tags — plus a Start Date if your organization tracks task start dates. Once a task exists you can also link it to the task it waits on (see "How do I link tasks so one starts when another ends?"). Tasks can also come from Slack ("/task"), from importing a task list, or from a transformation that turns Meeting Notes into tasks — and, if your organization tracks task start dates, when the source says one task comes after another ("once the design is approved…"), Projector links them for you. Anyone working in the project can create tasks.',
                'keywords' => 'new task, create task, add task, assignee, due date, start date, priority, status, waits on',
            ],
            'How does the task board work?' => [
                'answer' => 'The Tasks tab shows the project\'s tasks as a board, with one column per status. Drag a task between columns to change its status. Above the board you can search tasks or people, filter by priority or tag (including "None" for untagged tasks), and sort by Due Date, Priority, or Created Date. When your organization tracks task start dates, cards show each task\'s start and due dates, and a Board / List switch lets you see the tasks as a list of linked chains instead (see "What\'s the List view on the Tasks tab?").',
                'keywords' => 'kanban, board, columns, drag, search, filter, sort, priority, tag, list view, start date',
            ],
            'What are internal and external due dates?' => [
                'answer' => 'Some teams need two deadlines for the same task — for example, the date the team is aiming for and a later date shared with the client. An Org Admin can turn this on with "Track separate internal and external due dates on tasks" on the organization\'s Configuration tab. Once it\'s on, every task has both an Internal and an External due date. If your organization also tracks start dates, a task\'s start date (and task linking) always works from its internal due date.',
                'keywords' => 'internal due date, external due date, deadline, client date, due dates, start date',
            ],
            'How do I use the Calendar?' => [
                'answer' => 'The Calendar shows a project\'s tasks and events by month. Use the arrows to change months and "Today" to jump back. You can show tasks, events, or both, filter by tag (including "None" for untagged items), and hide individual sub-projects if the project has any. A task with a start date shows as a bar from its start to its due date. Click an item (or "View Details" on its card) to open it in a side panel, where you can edit it without leaving the calendar. The PDF, CSV, and Excel buttons download what you\'re showing (see "How do I download a project\'s calendar?").',
                'keywords' => 'campaign calendar, calendar, month, filter, tag, sub-project, events, tasks, start date, view details',
            ],
            'How do I import a list of tasks or events from a spreadsheet?' => [
                'answer' => 'On the Import page choose your project and then "Task List" or "Event List" — or click "Import Tasks" on the project\'s Tasks tab, or "Import Events" on its Calendar tab. Pick a CSV, XLSX, XLS, or TXT file, up to 10 MB. Projector then shows how it matched your spreadsheet\'s columns to task or event fields; change any that are wrong (or leave a column as "Not mapped" to skip it), check "My file doesn\'t have a header row" if your first row is data rather than column names, and use Preview to see what will be created. Then click Import — progress shows at the top of the page while it runs.

If your organization tracks task start dates, a task list can also have a Start Date column and a Predecessor column — the name of the task each row waits on. A Predecessor can be another row in the same file (even one further down) or a task already in the project, and capitals don\'t matter. Anything that can\'t be linked — no task by that name, or a link that would make two tasks wait on each other — is listed on the import\'s record so you can fix it by hand.

If your file mixes tasks and events, or isn\'t a neat spreadsheet, use Smart Import instead (see the next question).',
                'keywords' => 'import tasks, import events, spreadsheet, csv, excel, xlsx, task list, event list, column mapping, start date, predecessor, linked tasks',
            ],
            'What is Smart Import ("Import Data")?' => [
                'answer' => 'Smart Import lets AI work out what\'s in a file for you. Choose "Smart Import" on the Import page, or click "Import Data" on a project\'s Tasks or Calendar tab, and pick a spreadsheet (CSV, XLSX, XLS) or a text file (TXT or Markdown). Projector analyzes it and detects the tasks and events it contains — even a mix of both in one file — and walks you through each detected list with Previous and Next so you can check and adjust it before importing.

If your organization tracks task start dates, Smart Import also picks up start dates and which task each one waits on — from a Predecessor column in a spreadsheet, or from the wording of a text file ("after the venue is booked…") — and links the tasks for you.

If you import the same kind of file regularly, use "Save as Transformation…" to save how it was set up. Next time, choose that saved transformation instead of "Start fresh (AI-detect)" and the file is set up the same way.',
                'keywords' => 'smart import, import data, ai, transformation, mixed, tasks and events, detect, predecessor, linked tasks',
            ],
            'How do I create a task from Slack?' => [
                'answer' => 'In a channel that\'s bound to a project, run "/task" followed by a description, e.g. "/task follow up with the client about the contract by Friday, high priority". Projector uses AI to pull out a title, description, assignee, due date, priority, and tag, then posts the created task in the channel. If your organization tracks task start dates, it also picks up a start date, and links the new task to an existing one when you say so — e.g. "/task send the invites after the guest list is final". You need to have linked your Slack identity first (see the previous question).',
                'keywords' => 'slack, task, slash command, create, start date, after, linked task',
            ],
            'How do I run a task report on the website?' => [
                'answer' => 'Open the project and go to its Reports tab. Choose your filters:
- Project — if the project has sub-projects, pick which ones to include.
- Assignee — one or more people (or leave it as "Anyone").
- Status — one or more statuses (or leave it as "Any Status").
- Due or Done, with a From and To date — "Due" filters by when tasks are due; "Done" filters by when they were completed.

Click "Search" to see the matching tasks. Click a column heading to sort by it, and click a task to open it. You can also change a task\'s status, assignee, start or due date, or tags right in the results. Projector remembers your last filters for each project, even on another computer; "Reset" clears them.

If your organization tracks task start dates, the results also show how tasks are linked: by default each task is listed under the task it waits on, a ↳ arrow marks a task that waits on another, and a "→ 2"-style badge shows how many tasks wait on it. Hover over a row to highlight the task it waits on and the ones waiting on it. Sorting by a column flattens the list (the arrows and badges stay); click "Chain order" next to Task Name to go back.',
                'keywords' => 'task report, reports tab, filter, assignee, status, due, done, date range, search, sort, linked tasks, chain order, start date',
            ],
            'How do I download a task report?' => [
                'answer' => 'After you run a search on the Reports tab, use the buttons above the results:
- Excel, Word, or PDF — downloads the file to your computer.
- Google Sheets or Google Docs — creates the file in your Google Drive and opens it in a new tab. The first time, you\'ll be asked to connect your Google account.

Every download contains exactly the tasks you searched for, in the order you sorted them, with these columns: Name, Status, Assignee, Start Date (if your organization tracks start dates), Due Date (or Done Date when you searched by Done), Priority, and Tags — plus a Project column first when the report covers sub-projects. Check "Include task details column in export" first if you want each task\'s full description added as a last column. When the results are in chain order, linked tasks are indented under the task they wait on in the download too.',
                'keywords' => 'download report, export, excel, word, pdf, google sheets, google docs, task details, columns, linked tasks, start date',
            ],
            'What reports can I get from Projector?' => [
                'answer' => 'There are four kinds, all available to anyone who can see the project:
- Task reports — on a project\'s Reports tab, filter the project\'s tasks and download the result as Excel, Word, PDF, Google Sheets, or Google Docs. When your organization tracks task start dates, reports also show how tasks are linked.
- Calendars — on a project\'s Calendar tab, download its tasks and events as a PDF calendar, CSV, or Excel file.
- Single documents — download any document, such as a set of Meeting Notes, as a PDF or Word file, or send it to Google Docs.
- From Slack — run "/report" in a channel bound to a project to get a task report or calendar posted right there.

Each one is explained in the questions below.',
                'keywords' => 'reports, report, download, export, overview, excel, pdf, word, google sheets, google docs, slack, linked tasks',
            ],
            'What\'s on a project\'s page?' => [
                'answer' => 'A project has five tabs:
- Tasks — the project\'s tasks, as a board (or, when your organization tracks task start dates, also as a List of linked tasks).
- Calendar — the project\'s events on a calendar, which you can download as a PDF, CSV, or Excel file.
- Documentation — the project\'s documents.
- Transcripts — meeting recordings and their transcripts.
- Reports — task reports you can filter and download.

The main button at the top right changes with the tab you\'re on ("New Task", "New Event", or "New Document").',
                'keywords' => 'project page, tabs, tasks, campaign calendar, documentation, transcripts, reports, list view',
            ],
        ];
    }

    /**
     * The wording these answers had before this migration, restored by down().
     *
     * @return array<string, array{answer: string, keywords: string}>
     */
    private function originalAnswers(): array
    {
        return [
            'How do I create a task from Slack?' => [
                'answer' => 'In a channel that\'s bound to a project, run "/task" followed by a description, e.g. "/task follow up with the client about the contract by Friday, high priority". Projector uses AI to pull out a title, description, assignee, due date, priority, and tag, then posts the created task in the channel. You need to have linked your Slack identity first (see the previous question).',
                'keywords' => 'slack, task, slash command, create',
            ],
            'What\'s on a project\'s page?' => [
                'answer' => 'A project has five tabs:
- Tasks — the project\'s tasks.
- Calendar — the project\'s events on a calendar, which you can download as a PDF, CSV, or Excel file.
- Documentation — the project\'s documents.
- Transcripts — meeting recordings and their transcripts.
- Reports — task reports you can filter and download.

The main button at the top right changes with the tab you\'re on ("New Task", "New Event", or "New Document").',
                'keywords' => 'project page, tabs, tasks, campaign calendar, documentation, transcripts, reports',
            ],
            'How do I import a list of tasks or events from a spreadsheet?' => [
                'answer' => 'On the Import page choose your project and then "Task List" or "Event List" — or click "Import Tasks" on the project\'s Tasks tab, or "Import Events" on its Calendar tab. Pick a CSV, XLSX, XLS, or TXT file, up to 10 MB. Projector then shows how it matched your spreadsheet\'s columns to task or event fields; change any that are wrong (or leave a column as "Not mapped" to skip it), check "My file doesn\'t have a header row" if your first row is data rather than column names, and use Preview to see what will be created. Then click Import — progress shows at the top of the page while it runs.

If your file mixes tasks and events, or isn\'t a neat spreadsheet, use Smart Import instead (see the next question).',
                'keywords' => 'import tasks, import events, spreadsheet, csv, excel, xlsx, task list, event list, column mapping',
            ],
            'What is Smart Import ("Import Data")?' => [
                'answer' => 'Smart Import lets AI work out what\'s in a file for you. Choose "Smart Import" on the Import page, or click "Import Data" on a project\'s Tasks or Calendar tab, and pick a spreadsheet (CSV, XLSX, XLS) or a text file (TXT or Markdown). Projector analyzes it and detects the tasks and events it contains — even a mix of both in one file — and walks you through each detected list with Previous and Next so you can check and adjust it before importing.

If you import the same kind of file regularly, use "Save as Transformation…" to save how it was set up. Next time, choose that saved transformation instead of "Start fresh (AI-detect)" and the file is set up the same way.',
                'keywords' => 'smart import, import data, ai, transformation, mixed, tasks and events, detect',
            ],
            'What reports can I get from Projector?' => [
                'answer' => 'There are four kinds, all available to anyone who can see the project:
- Task reports — on a project\'s Reports tab, filter the project\'s tasks and download the result as Excel, Word, PDF, Google Sheets, or Google Docs.
- Calendars — on a project\'s Calendar tab, download its tasks and events as a PDF calendar, CSV, or Excel file.
- Single documents — download any document, such as a set of Meeting Notes, as a PDF or Word file, or send it to Google Docs.
- From Slack — run "/report" in a channel bound to a project to get a task report or calendar posted right there.

Each one is explained in the questions below.',
                'keywords' => 'reports, report, download, export, overview, excel, pdf, word, google sheets, google docs, slack',
            ],
            'How do I run a task report on the website?' => [
                'answer' => 'Open the project and go to its Reports tab. Choose your filters:
- Project — if the project has sub-projects, pick which ones to include.
- Assignee — one or more people (or leave it as "Anyone").
- Status — one or more statuses (or leave it as "Any Status").
- Due or Done, with a From and To date — "Due" filters by when tasks are due; "Done" filters by when they were completed.

Click "Search" to see the matching tasks. Click a column heading to sort by it, and click a task to open it. You can also change a task\'s status, assignee, due date, or tags right in the results. Projector remembers your last filters for each project, even on another computer; "Reset" clears them.',
                'keywords' => 'task report, reports tab, filter, assignee, status, due, done, date range, search, sort',
            ],
            'How do I download a task report?' => [
                'answer' => 'After you run a search on the Reports tab, use the buttons above the results:
- Excel, Word, or PDF — downloads the file to your computer.
- Google Sheets or Google Docs — creates the file in your Google Drive and opens it in a new tab. The first time, you\'ll be asked to connect your Google account.

Every download contains exactly the tasks you searched for, in the order you sorted them. Check "Include task details column in export" first if you want each task\'s full description included too.',
                'keywords' => 'download report, export, excel, word, pdf, google sheets, google docs, task details',
            ],
            'How do I create a task?' => [
                'answer' => 'On a project\'s Tasks tab, click "New Task". Give it a name, and fill in any of the rest: a description, Assignee, Due Date, Priority, Status, and Tags. Tasks can also come from Slack ("/task"), from importing a task list, or from a transformation that turns Meeting Notes into tasks. Anyone working in the project can create tasks.',
                'keywords' => 'new task, create task, add task, assignee, due date, priority, status',
            ],
            'How does the task board work?' => [
                'answer' => 'The Tasks tab shows the project\'s tasks as a board, with one column per status. Drag a task between columns to change its status. Above the board you can search tasks or people, filter by priority or tag (including "None" for untagged tasks), and sort by Due Date, Priority, or Created Date.',
                'keywords' => 'kanban, board, columns, drag, search, filter, sort, priority, tag',
            ],
            'What are internal and external due dates?' => [
                'answer' => 'Some teams need two deadlines for the same task — for example, the date the team is aiming for and a later date shared with the client. An Org Admin can turn this on with "Track separate internal and external due dates on tasks" on the organization\'s Configuration tab. Once it\'s on, every task has both an Internal and an External due date.',
                'keywords' => 'internal due date, external due date, deadline, client date, due dates',
            ],
            'How do I use the Calendar?' => [
                'answer' => 'The Calendar shows a project\'s tasks and events by month. Use the arrows to change months and "Today" to jump back. You can show tasks, events, or both, filter by tag (including "None" for untagged items), and hide individual sub-projects if the project has any. Click an item to see its details. The PDF, CSV, and Excel buttons download what you\'re showing (see "How do I download a project\'s calendar?").',
                'keywords' => 'campaign calendar, calendar, month, filter, tag, sub-project, events, tasks',
            ],
        ];
    }
};
