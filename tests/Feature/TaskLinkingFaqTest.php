<?php

use App\Models\Faq;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('adds the start date and task linking questions after the existing Tasks & Events ones', function () {
    $questions = Faq::where('category', 'Tasks & Events')->orderBy('order')->pluck('question')->all();

    expect(array_slice($questions, -4))->toBe([
        'What are task start dates?',
        'How do I link tasks so one starts when another ends?',
        'What happens to linked tasks when a date changes?',
        "What's the List view on the Tasks tab?",
    ]);
});

it('updates importing, Slack, and reporting answers to cover start dates and linked tasks', function (string $question, string $expected) {
    expect(Faq::where('question', $question)->firstOrFail()->answer)->toContain($expected);
})->with([
    'spreadsheet import' => ['How do I import a list of tasks or events from a spreadsheet?', 'Predecessor column'],
    'smart import' => ['What is Smart Import ("Import Data")?', 'which task each one waits on'],
    'slack /task' => ['How do I create a task from Slack?', 'after the guest list is final'],
    'run a report' => ['How do I run a task report on the website?', 'Chain order'],
    'download a report' => ['How do I download a task report?', 'Name, Status, Assignee, Start Date'],
    'calendar' => ['How do I use the Calendar?', 'from its start to its due date'],
    'board' => ['How does the task board work?', 'Board / List switch'],
]);

it('only references FAQ questions that exist, in every category', function () {
    $questions = Faq::pluck('question')->all();

    foreach (Faq::pluck('answer') as $answer) {
        preg_match_all('/see "([^"]+\?)"/i', $answer, $matches);

        foreach ($matches[1] as $referenced) {
            expect($questions)->toContain($referenced);
        }
    }
});
