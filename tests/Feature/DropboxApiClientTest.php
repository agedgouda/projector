<?php

use App\Models\DropboxWorkspace;
use App\Services\Dropbox\DropboxApiClient;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = DropboxWorkspace::factory()->create();
    $this->client = app(DropboxApiClient::class);
});

// ── listTopLevelFolders() ─────────────────────────────────────────────────────

it('returns only top-level folders, not files, from a single page', function () {
    Http::fake([
        'api.dropboxapi.com/2/files/list_folder' => Http::response([
            'entries' => [
                ['.tag' => 'folder', 'id' => 'id:folder1', 'name' => 'Client Intake', 'path_display' => '/Client Intake'],
                ['.tag' => 'file', 'id' => 'id:file1', 'name' => 'notes.txt', 'path_display' => '/notes.txt'],
                ['.tag' => 'folder', 'id' => 'id:folder2', 'name' => 'Projector', 'path_display' => '/Projector'],
            ],
            'cursor' => 'cursor-abc',
            'has_more' => false,
        ], 200),
    ]);

    $folders = $this->client->listTopLevelFolders($this->workspace);

    expect($folders)->toBe([
        ['id' => 'id:folder1', 'path' => '/Client Intake'],
        ['id' => 'id:folder2', 'path' => '/Projector'],
    ]);

    // Response::json($key) runs $key through data_get(), which splits on "." — a key literally
    // named ".tag" (Dropbox's own union-type discriminator) can never be reached that way. Real
    // regression: DropboxApiClient::resolveFolder() (since removed in favor of this method) used
    // exactly that broken form and always rejected every valid folder. Plain array access on the
    // fully-decoded body, as this method does, is what actually works.
    Http::assertSent(fn ($request) => $request->url() === 'https://api.dropboxapi.com/2/files/list_folder'
        && $request['path'] === ''
        && $request['recursive'] === false);
});

it('pages through list_folder/continue until has_more is false', function () {
    Http::fake([
        'api.dropboxapi.com/2/files/list_folder' => Http::response([
            'entries' => [
                ['.tag' => 'folder', 'id' => 'id:folder1', 'name' => 'Client Intake', 'path_display' => '/Client Intake'],
            ],
            'cursor' => 'cursor-1',
            'has_more' => true,
        ], 200),
        'api.dropboxapi.com/2/files/list_folder/continue' => Http::response([
            'entries' => [
                ['.tag' => 'folder', 'id' => 'id:folder2', 'name' => 'Projector', 'path_display' => '/Projector'],
            ],
            'cursor' => 'cursor-2',
            'has_more' => false,
        ], 200),
    ]);

    $folders = $this->client->listTopLevelFolders($this->workspace);

    expect($folders)->toBe([
        ['id' => 'id:folder1', 'path' => '/Client Intake'],
        ['id' => 'id:folder2', 'path' => '/Projector'],
    ]);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.dropboxapi.com/2/files/list_folder/continue'
        && $request['cursor'] === 'cursor-1');
});

it('throws when listing folders fails', function () {
    Http::fake([
        'api.dropboxapi.com/2/files/list_folder' => Http::response('server error', 500),
    ]);

    expect(fn () => $this->client->listTopLevelFolders($this->workspace))
        ->toThrow(RuntimeException::class);
});
