<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\GoogleOauthToken;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Google\GoogleExportService;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
    ]);

    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'action_items',
        'label' => 'Action Items',
        'is_task' => true,
        'order' => 1,
    ]);

    $this->user = User::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);
});

// ── Edit page ────────────────────────────────────────────────────────────────

it('reports google as not configured when oauth credentials are missing', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->actingAs($this->user)
        ->get(route('integrations.edit'))
        ->assertInertia(fn ($page) => $page->where('googleConfigured', false));
});

it('reports google as configured when oauth credentials are present', function () {
    config(['services.google.client_id' => 'fake-client-id', 'services.google.client_secret' => 'fake-client-secret']);

    $this->actingAs($this->user)
        ->get(route('integrations.edit'))
        ->assertInertia(fn ($page) => $page->where('googleConfigured', true));
});

// ── Connect redirect ────────────────────────────────────────────────────────

it('redirects to google with offline access, consent prompt, and the drive.file scope', function () {
    config(['services.google.client_id' => 'fake-client-id', 'services.google.client_secret' => 'fake-client-secret']);

    $response = $this->actingAs($this->user)
        ->get(route('integrations.google.connect'));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toContain('accounts.google.com')
        ->and($location)->toContain('access_type=offline')
        ->and($location)->toContain('prompt=consent')
        ->and(urldecode($location))->toContain('https://www.googleapis.com/auth/drive.file');
});

it('redirects back with a status instead of a broken google url when oauth credentials are missing', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $response = $this->actingAs($this->user)
        ->get(route('integrations.google.connect'));

    $response->assertRedirect(route('integrations.edit'));
    expect(session('status'))->toBe('google-not-configured');
});

// ── Callback ────────────────────────────────────────────────────────────────

it('stores a token when the google callback succeeds', function () {
    Socialite::fake('google', \Laravel\Socialite\Two\User::fake([
        'email' => 'jeff@example.com',
        'token' => 'fake-access-token',
        'refreshToken' => 'fake-refresh-token',
        'expiresIn' => 3600,
    ]));

    $this->actingAs($this->user)
        ->get(route('integrations.google.callback'))
        ->assertRedirect(route('integrations.edit'));

    $token = GoogleOauthToken::where('user_id', $this->user->id)->first();

    expect($token)->not->toBeNull()
        ->and($token->google_email)->toBe('jeff@example.com')
        ->and($token->access_token)->toBe('fake-access-token')
        ->and($token->refresh_token)->toBe('fake-refresh-token');
});

it('does not store a token when google returns no refresh token', function () {
    Socialite::fake('google', \Laravel\Socialite\Two\User::fake([
        'refreshToken' => null,
    ]));

    $this->actingAs($this->user)
        ->get(route('integrations.google.callback'))
        ->assertRedirect(route('integrations.edit'));

    expect(GoogleOauthToken::where('user_id', $this->user->id)->exists())->toBeFalse();
});

// ── Disconnect ──────────────────────────────────────────────────────────────

it('deletes the token on disconnect', function () {
    GoogleOauthToken::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->delete(route('integrations.google.disconnect'))
        ->assertRedirect(route('integrations.edit'));

    expect(GoogleOauthToken::where('user_id', $this->user->id)->exists())->toBeFalse();
});

// ── Export: not connected ──────────────────────────────────────────────────

it('returns 428 with a connect url when exporting without a connected google account', function () {
    Document::create(['project_id' => $this->project->id, 'name' => 'A Task', 'type' => 'action_items', 'content' => 'x']);

    $response = $this->actingAs($this->user)
        ->getJson(route('projects.reports.tasks.exportGoogleSheet', $this->project));

    $response->assertStatus(428);
    expect($response->json('connect_url'))->toBe(route('integrations.google.connect'));
});

// ── Export: connected ───────────────────────────────────────────────────────

it('creates a google sheet with the filtered tasks when connected', function () {
    GoogleOauthToken::factory()->create([
        'user_id' => $this->user->id,
        'expires_at' => now()->addHour(),
    ]);

    Document::create(['project_id' => $this->project->id, 'name' => 'Exportable Task', 'type' => 'action_items', 'content' => 'x', 'priority' => 'high']);

    Http::fake([
        // Specific values-write pattern first — must precede the broader create pattern.
        'sheets.googleapis.com/v4/spreadsheets/*/values/*' => Http::response(['updatedCells' => 5], 200),
        'sheets.googleapis.com/v4/spreadsheets' => Http::response([
            'spreadsheetId' => 'abc123',
            'spreadsheetUrl' => 'https://docs.google.com/spreadsheets/d/abc123/edit',
        ], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('projects.reports.tasks.exportGoogleSheet', $this->project));

    $response->assertOk();
    expect($response->json('url'))->toBe('https://docs.google.com/spreadsheets/d/abc123/edit');

    Http::assertSent(fn ($request) => $request->url() === 'https://sheets.googleapis.com/v4/spreadsheets'
        && $request['properties']['title'] === 'test-project-task-report');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/values/A1?valueInputOption=RAW')
        && $request['values'][0] === ['Status', 'Due Date', 'Task Name', 'Assignee', 'Priority']
        && $request['values'][1][2] === 'Exportable Task');
});

it('forbids exporting to google sheets for a user unrelated to the project', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->getJson(route('projects.reports.tasks.exportGoogleSheet', $this->project))
        ->assertNotFound();
});

it('creates a google doc with a filled table of the filtered tasks when connected', function () {
    GoogleOauthToken::factory()->create([
        'user_id' => $this->user->id,
        'expires_at' => now()->addHour(),
    ]);

    Document::create(['project_id' => $this->project->id, 'name' => 'Exportable Task', 'type' => 'action_items', 'content' => 'x', 'priority' => 'high']);

    // 2 rows (header + 1 task) x 5 columns = 10 cells. A brand-new table's cells are only
    // addressable once documents.get reports back their (empty) paragraph start indexes —
    // these are made up but internally consistent, standing in for whatever Google actually
    // assigns.
    $fakeCellIndex = fn (int $row, int $col) => 5 + ($row * 4 * 5) + ($col * 4);
    $fakeDocument = [
        'body' => [
            'content' => [
                [
                    'table' => [
                        'tableRows' => [
                            ['tableCells' => array_map(fn ($col) => ['content' => [['startIndex' => $fakeCellIndex(0, $col)]]], range(0, 4))],
                            ['tableCells' => array_map(fn ($col) => ['content' => [['startIndex' => $fakeCellIndex(1, $col)]]], range(0, 4))],
                        ],
                    ],
                ],
            ],
        ],
    ];

    Http::fake([
        // Specific patterns before the general wildcard — a bare "/documents/*" would
        // otherwise also swallow the ":batchUpdate" calls below.
        'docs.googleapis.com/v1/documents' => Http::response(['documentId' => 'doc123'], 200),
        'docs.googleapis.com/v1/documents/*:batchUpdate' => Http::response([], 200),
        'docs.googleapis.com/v1/documents/*' => Http::response($fakeDocument, 200),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('projects.reports.tasks.exportGoogleDoc', $this->project));

    $response->assertOk();
    expect($response->json('url'))->toBe('https://docs.google.com/document/d/doc123/edit');

    Http::assertSent(fn ($request) => $request->url() === 'https://docs.googleapis.com/v1/documents'
        && $request['title'] === 'test-project-task-report');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'doc123:batchUpdate')
        && ($request['requests'][0]['insertTable']['rows'] ?? null) === 2
        && ($request['requests'][0]['insertTable']['columns'] ?? null) === 5);

    // Cells must be filled last-to-first (by precomputed index) so that inserting text
    // into a later cell never invalidates an earlier cell's still-to-be-used index.
    Http::assertSent(function ($request) use ($fakeCellIndex) {
        if (! str_contains($request->url(), 'doc123:batchUpdate') || ! isset($request['requests'][0]['insertText'])) {
            return false;
        }

        $requests = $request['requests'];

        return count($requests) === 10
            && $requests[0]['insertText']['location']['index'] === $fakeCellIndex(1, 4)
            && $requests[0]['insertText']['text'] === 'High'
            && $requests[9]['insertText']['location']['index'] === $fakeCellIndex(0, 0)
            && $requests[9]['insertText']['text'] === 'Status';
    });
});

it('returns 428 with a connect url when exporting to google docs without a connected google account', function () {
    Document::create(['project_id' => $this->project->id, 'name' => 'A Task', 'type' => 'action_items', 'content' => 'x']);

    $response = $this->actingAs($this->user)
        ->getJson(route('projects.reports.tasks.exportGoogleDoc', $this->project));

    $response->assertStatus(428);
    expect($response->json('connect_url'))->toBe(route('integrations.google.connect'));
});

// ── Token refresh ────────────────────────────────────────────────────────────

it('refreshes an expired access token', function () {
    $token = GoogleOauthToken::factory()->create([
        'user_id' => $this->user->id,
        'access_token' => 'stale-token',
        'expires_at' => now()->subMinute(),
    ]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'refreshed-token',
            'expires_in' => 3600,
        ], 200),
    ]);

    $accessToken = app(GoogleExportService::class)->getValidAccessToken($this->user);

    expect($accessToken)->toBe('refreshed-token');
    expect($token->fresh()->access_token)->toBe('refreshed-token');
});

it('deletes the token when google reports the refresh token was revoked', function () {
    GoogleOauthToken::factory()->create([
        'user_id' => $this->user->id,
        'expires_at' => now()->subMinute(),
    ]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $accessToken = app(GoogleExportService::class)->getValidAccessToken($this->user);

    expect($accessToken)->toBeNull();
    expect(GoogleOauthToken::where('user_id', $this->user->id)->exists())->toBeFalse();
});
