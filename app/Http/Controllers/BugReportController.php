<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBugReportRequest;
use App\Models\BugReport;
use Inertia\Inertia;
use Inertia\Response;

class BugReportController extends Controller
{
    public function index(): Response
    {
        $bugReports = BugReport::with('user')
            ->latest()
            ->get()
            ->map(fn (BugReport $report) => [
                'id' => $report->id,
                'title' => $report->title,
                'description' => $report->description,
                'page_url' => $report->page_url,
                'status' => $report->status,
                'reporter' => $report->user->name,
                'reporter_email' => $report->user->email,
                'created_at' => $report->created_at->toDateTimeString(),
            ]);

        return Inertia::render('BugReports/Index', [
            'bugReports' => $bugReports,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('BugReports/Create');
    }

    public function store(StoreBugReportRequest $request): \Illuminate\Http\RedirectResponse
    {
        BugReport::create([
            'user_id' => $request->user()->id,
            'title' => $request->title,
            'description' => $request->description,
            'page_url' => $request->page_url,
        ]);

        return back()->with('success', 'Bug report submitted. Thank you!');
    }

    public function update(BugReport $bugReport): \Illuminate\Http\RedirectResponse
    {
        $bugReport->update([
            'status' => $bugReport->status === 'open' ? 'resolved' : 'open',
        ]);

        return back();
    }
}
