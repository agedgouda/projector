<?php

namespace App\Services\Demo;

/**
 * The sample meetings offered on the public demo page. Each sample's notes live in
 * resources/demo/samples/{id}.txt, and its precomputed extraction (written by
 * `php artisan demo:generate-samples`) in resources/demo/results/{id}.json, so picking an
 * unedited sample costs nothing.
 */
class DemoSamples
{
    /**
     * @var array<string, array{title: string, meeting_date: string, team: list<string>}>
     */
    public const SAMPLES = [
        'kickoff' => [
            'title' => 'Website redesign kickoff',
            'meeting_date' => '2026-10-13',
            'team' => ['Maya Chen', 'Luis Ortega', 'Priya Natarajan', 'Sam Becker'],
        ],
        'checkin' => [
            'title' => 'Patient portal weekly check-in',
            'meeting_date' => '2026-10-14',
            'team' => ['Jordan Reyes', 'Aisha Patel', 'Tom Whitaker', 'Elena Ruiz'],
        ],
        'status' => [
            'title' => 'Fitness app launch status call',
            'meeting_date' => '2026-10-15',
            'team' => ['Nora Lindqvist', 'Ben Adeyemi', 'Carla Mendes', 'Greg Holt'],
        ],
    ];

    /**
     * @return list<array{id: string, title: string, meeting_date: string, team: list<string>, notes: string, tasks: list<array<mixed>>|null}>
     */
    public function all(): array
    {
        $samples = [];

        foreach (self::SAMPLES as $id => $sample) {
            $samples[] = [
                'id' => $id,
                ...$sample,
                'notes' => $this->notes($id),
                'tasks' => $this->storedTasks($id),
            ];
        }

        return $samples;
    }

    public function notes(string $id): string
    {
        $contents = file_get_contents(resource_path("demo/samples/{$id}.txt"));

        return $contents === false ? '' : $contents;
    }

    public function resultPath(string $id): string
    {
        return resource_path("demo/results/{$id}.json");
    }

    /**
     * @return list<array<mixed>>|null
     */
    private function storedTasks(string $id): ?array
    {
        $path = $this->resultPath($id);

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        $decoded = $contents === false ? null : json_decode($contents, true);

        if (! is_array($decoded) || ! isset($decoded['tasks']) || ! is_array($decoded['tasks'])) {
            return null;
        }

        return array_values(array_filter($decoded['tasks'], 'is_array'));
    }
}
