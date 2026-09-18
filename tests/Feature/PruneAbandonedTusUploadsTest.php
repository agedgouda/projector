<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TusUpload;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createTusUpload(\Carbon\Carbon $createdAt, string $kind = 'partial'): TusUpload
{
    $org = Organization::create(['name' => 'Acme Inc']);
    $client = Client::create([
        'organization_id' => $org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $project = Project::create(['name' => 'Test Project', 'client_id' => $client->id]);
    $user = User::factory()->create();

    $path = 'tus-uploads/'.\Illuminate\Support\Str::uuid().'.partial';
    Storage::disk('local')->put($path, 'some bytes');

    $upload = TusUpload::create([
        'project_id' => $project->id,
        'creator_id' => $user->id,
        'kind' => $kind,
        'upload_length' => 10,
        'upload_offset' => 0,
        'storage_path' => $path,
    ]);
    $upload->timestamps = false;
    $upload->created_at = $createdAt;
    $upload->save();

    return $upload;
}

beforeEach(function () {
    Storage::fake('local');
});

it('deletes abandoned partial uploads older than the retention window', function () {
    $old = createTusUpload(now()->subHours(48));

    $this->artisan('app:prune-abandoned-tus-uploads')->assertSuccessful();

    expect(TusUpload::find($old->id))->toBeNull();
    expect(Storage::disk('local')->exists($old->storage_path))->toBeFalse();
});

it('keeps recent, still in-progress uploads', function () {
    $recent = createTusUpload(now()->subHours(2));

    $this->artisan('app:prune-abandoned-tus-uploads')->assertSuccessful();

    expect(TusUpload::find($recent->id))->not->toBeNull();
    expect(Storage::disk('local')->exists($recent->storage_path))->toBeTrue();
});

it('also deletes a stray old final upload', function () {
    $old = createTusUpload(now()->subHours(48), kind: 'final');

    $this->artisan('app:prune-abandoned-tus-uploads')->assertSuccessful();

    expect(TusUpload::find($old->id))->toBeNull();
});
