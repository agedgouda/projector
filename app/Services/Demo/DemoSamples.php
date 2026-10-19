<?php

namespace App\Services\Demo;

/**
 * The three projects every demo sandbox starts with, one meeting each. Each meeting's
 * transcript lives in resources/demo/samples/{id}.txt.
 */
class DemoSamples
{
    /**
     * @var array<string, array{client: string, contact: string, industry: string, project: string, description: string, title: string, team: list<string>}>
     */
    public const SAMPLES = [
        'kickoff' => [
            'client' => 'Harbor & Pine Coffee',
            'contact' => 'Sam Becker',
            'industry' => 'Food & Beverage',
            'project' => 'Harbor & Pine Website',
            'description' => 'Redesign of the Harbor & Pine Coffee website, moving the site and online store onto Shopify, including the monthly bean box subscription. Launch before Black Friday.',
            'title' => 'Website Redesign Kickoff',
            'team' => ['Maya Chen', 'Luis Ortega', 'Priya Natarajan', 'Sam Becker'],
        ],
        'checkin' => [
            'client' => 'Northfield Dental',
            'contact' => 'Elena Ruiz',
            'industry' => 'Healthcare',
            'project' => 'Northfield Patient Portal',
            'description' => 'Patient portal for Northfield Dental: online appointment booking, reminders, and insurance card uploads. Phase 1 go-live is November 2.',
            'title' => 'Patient Portal Weekly Check-in',
            'team' => ['Jordan Reyes', 'Aisha Patel', 'Tom Whitaker', 'Elena Ruiz'],
        ],
        'status' => [
            'client' => 'Brightline Fitness',
            'contact' => 'Greg Holt',
            'industry' => 'Fitness',
            'project' => 'Brightline App Launch',
            'description' => 'iOS and Android app for Brightline Fitness members to book classes and get reminders. App Store submission target is November 2.',
            'title' => 'App Launch Status Call',
            'team' => ['Nora Lindqvist', 'Ben Adeyemi', 'Carla Mendes', 'Greg Holt'],
        ],
    ];

    public function notes(string $id): string
    {
        $contents = file_get_contents(resource_path("demo/samples/{$id}.txt"));

        return $contents === false ? '' : $contents;
    }

    /**
     * The transcript as the HTML the document editor stores: one paragraph per line.
     */
    public function notesHtml(string $id): string
    {
        $lines = preg_split('/\R/', trim($this->notes($id))) ?: [];

        return implode('', array_map(
            fn (string $line): string => $line === '' ? '<p></p>' : '<p>'.e($line).'</p>',
            $lines,
        ));
    }
}
