<?php

use App\Jobs\GenerateOrgDocumentEmbedding;
use App\Jobs\ProcessOrgDocumentAI;
use App\Models\Organization;
use App\Models\OrgDocument;
use App\Services\VectorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([GenerateOrgDocumentEmbedding::class, ProcessOrgDocumentAI::class]);

    $this->org = Organization::create(['name' => 'Test Org']);
});

function makeOrgDocument(Organization $org, array $attributes = []): OrgDocument
{
    return OrgDocument::create([
        'organization_id' => $org->id,
        'name' => 'Weekly Sync',
        'type' => 'status_meeting',
        'content' => 'Some transcript content',
        ...$attributes,
    ]);
}

it('sizes org_documents.embedding to match documents.embedding', function () {
    $typeOf = fn (string $table): string => DB::selectOne(
        'select format_type(atttypid, atttypmod) as t from pg_attribute where attrelid = ?::regclass and attname = ?',
        [$table, 'embedding']
    )->t;

    expect($typeOf('org_documents'))->toBe('vector(1536)')
        ->and($typeOf('org_documents'))->toBe($typeOf('documents'));
});

it('stores the default driver\'s 1536-dimension embedding on an org document', function () {
    config(['services.vector_driver' => 'openai', 'services.openai.key' => 'test-key']);

    $vector = array_fill(0, 1536, 0.01);

    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => $vector]]]),
    ]);

    $orgDocument = makeOrgDocument($this->org);

    (new GenerateOrgDocumentEmbedding($orgDocument))->handle(app(VectorService::class));

    $stored = DB::selectOne('select vector_dims(embedding) as d from org_documents where id = ?', [$orgDocument->id]);

    expect($stored->d)->toBe(1536)
        ->and($orgDocument->fresh()->processed_at)->not->toBeNull();
});

it('queues embeddings only for org documents with content and no embedding', function () {
    $missing = makeOrgDocument($this->org);
    makeOrgDocument($this->org, ['content' => '']);

    $embedded = makeOrgDocument($this->org);
    DB::table('org_documents')->where('id', $embedded->id)
        ->update(['embedding' => '['.implode(',', array_fill(0, 1536, 0.01)).']']);

    Queue::fake([GenerateOrgDocumentEmbedding::class]);

    $this->artisan('app:reembed-org-documents')
        ->expectsOutput('Queued 1 org document(s) for embedding.')
        ->assertSuccessful();

    Queue::assertPushed(GenerateOrgDocumentEmbedding::class, 1);
    Queue::assertPushed(GenerateOrgDocumentEmbedding::class, fn ($job) => $job->orgDocument->is($missing));
});

it('queues nothing on a dry run', function () {
    makeOrgDocument($this->org);

    Queue::fake([GenerateOrgDocumentEmbedding::class]);

    $this->artisan('app:reembed-org-documents', ['--dry-run' => true])
        ->expectsOutput('1 org document(s) would be queued for embedding.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
