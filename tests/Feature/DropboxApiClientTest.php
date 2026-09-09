<?php

use App\Models\DropboxWorkspace;
use App\Services\Dropbox\DropboxApiClient;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = DropboxWorkspace::factory()->create();
    $this->client = app(DropboxApiClient::class);
});

// ── resolveFolder() ───────────────────────────────────────────────────────────

it('resolves a real folder metadata response to its id and display path', function () {
    // Response::json($key) runs $key through data_get(), which splits on "." — a key literally
    // named ".tag" (Dropbox's own union-type discriminator) can never be reached that way, so
    // resolveFolder() always threw "not a folder" for every real Dropbox response until fixed
    // to read the fully-decoded body with plain array access instead. Mocking resolveFolder()
    // itself (as OrganizationDropboxFoldersControllerTest does) can't catch this — it has to
    // exercise the method against a real-shaped HTTP response.
    Http::fake([
        'api.dropboxapi.com/2/files/get_metadata' => Http::response([
            '.tag' => 'folder',
            'id' => 'id:6ztLq8dig90AAAAAAAFzOA',
            'name' => 'Projector',
            'path_display' => '/Projector',
            'path_lower' => '/projector',
        ], 200),
    ]);

    $resolved = $this->client->resolveFolder($this->workspace, '/Projector');

    expect($resolved)->toBe(['folder_id' => 'id:6ztLq8dig90AAAAAAAFzOA', 'folder_path' => '/Projector']);
});

it('throws when the path resolves to a file, not a folder', function () {
    Http::fake([
        'api.dropboxapi.com/2/files/get_metadata' => Http::response([
            '.tag' => 'file',
            'id' => 'id:somefile',
            'name' => 'notes.txt',
            'path_display' => '/notes.txt',
        ], 200),
    ]);

    expect(fn () => $this->client->resolveFolder($this->workspace, '/notes.txt'))
        ->toThrow(RuntimeException::class);
});

it('throws when dropbox reports the path does not exist', function () {
    Http::fake([
        'api.dropboxapi.com/2/files/get_metadata' => Http::response(
            "Error in call to API function 'files/get_metadata': path/not_found/...",
            409
        ),
    ]);

    expect(fn () => $this->client->resolveFolder($this->workspace, '/Does Not Exist'))
        ->toThrow(RuntimeException::class);
});
