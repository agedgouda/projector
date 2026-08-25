<?php

use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->client = Client::create([
        'organization_id' => Organization::create(['name' => 'Test Org'])->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);

    $this->parent = Project::create([
        'name' => 'Website Relaunch',
        'client_id' => $this->client->id,
    ]);

    $this->child = Project::create([
        'name' => 'Design',
        'client_id' => $this->client->id,
        'parent_id' => $this->parent->id,
    ]);
});

it('moves a subproject\'s documents onto its parent and tags them with the subproject\'s name', function () {
    $document = Document::create([
        'project_id' => $this->child->id,
        'name' => 'A task',
        'type' => 'task',
        'content' => 'content',
    ]);

    $this->artisan('app:merge-subprojects')->assertExitCode(0);

    $document->refresh();
    expect($document->project_id)->toBe($this->parent->id);

    $tag = Category::where('project_id', $this->parent->id)->where('name', 'Design')->first();
    expect($tag)->not->toBeNull();
    expect($document->categories()->pluck('categories.id')->all())->toBe([$tag->id]);
});

it('reuses an existing family tag with the same name instead of creating a duplicate', function () {
    $existingTag = Category::create(['project_id' => $this->parent->id, 'name' => 'Design', 'color' => 'pink']);
    $document = Document::create([
        'project_id' => $this->child->id,
        'name' => 'A task',
        'type' => 'task',
        'content' => 'content',
    ]);

    $this->artisan('app:merge-subprojects')->assertExitCode(0);

    expect(Category::where('project_id', $this->parent->id)->where('name', 'Design')->count())->toBe(1);
    expect($document->fresh()->categories()->pluck('categories.id')->all())->toBe([$existingTag->id]);
});

it('adds the subproject tag alongside a document\'s existing tags rather than replacing them', function () {
    $keep = Category::create(['project_id' => $this->parent->id, 'name' => 'Backend', 'color' => 'blue']);
    $document = Document::create([
        'project_id' => $this->child->id,
        'name' => 'A task',
        'type' => 'task',
        'content' => 'content',
    ]);
    $document->categories()->attach($keep->id);

    $this->artisan('app:merge-subprojects')->assertExitCode(0);

    $tag = Category::where('project_id', $this->parent->id)->where('name', 'Design')->first();
    expect($document->fresh()->categories()->pluck('categories.id')->all())
        ->toEqualCanonicalizing([$keep->id, $tag->id]);
});

it('picks a color not already used by another tag in the family', function () {
    Category::create(['project_id' => $this->parent->id, 'name' => 'Backend', 'color' => 'slate']);
    Document::create([
        'project_id' => $this->child->id,
        'name' => 'A task',
        'type' => 'task',
        'content' => 'content',
    ]);

    $this->artisan('app:merge-subprojects')->assertExitCode(0);

    $tag = Category::where('project_id', $this->parent->id)->where('name', 'Design')->first();
    expect($tag->color)->not->toBe('slate');
});

it('does nothing to a subproject with no documents beyond leaving it untouched', function () {
    $this->artisan('app:merge-subprojects')->assertExitCode(0);

    expect(Category::where('project_id', $this->parent->id)->count())->toBe(0);
    expect(Project::find($this->child->id))->not->toBeNull();
});

it('changes nothing in dry-run mode', function () {
    $document = Document::create([
        'project_id' => $this->child->id,
        'name' => 'A task',
        'type' => 'task',
        'content' => 'content',
    ]);

    $this->artisan('app:merge-subprojects --dry-run')
        ->expectsOutputToContain('[DRY RUN]')
        ->assertExitCode(0);

    expect($document->fresh()->project_id)->toBe($this->child->id);
    expect(Category::where('project_id', $this->parent->id)->count())->toBe(0);
});

it('skips a subproject once every palette color is already taken in its family', function () {
    $palette = ['slate', 'red', 'amber', 'emerald', 'blue', 'purple', 'pink', 'orange', 'indigo', 'teal'];
    foreach ($palette as $i => $color) {
        Category::create(['project_id' => $this->parent->id, 'name' => "Existing {$i}", 'color' => $color]);
    }

    $document = Document::create([
        'project_id' => $this->child->id,
        'name' => 'A task',
        'type' => 'task',
        'content' => 'content',
    ]);

    $this->artisan('app:merge-subprojects')->assertExitCode(0);

    expect($document->fresh()->project_id)->toBe($this->child->id);
    expect(Category::where('project_id', $this->parent->id)->where('name', 'Design')->exists())->toBeFalse();
});

it('merges multiple subprojects across multiple clients in one run', function () {
    $otherClient = Client::create([
        'organization_id' => $this->client->organization_id,
        'company_name' => 'Other Client',
        'contact_name' => 'John Doe',
        'contact_phone' => '555-5678',
    ]);
    $otherParent = Project::create(['name' => 'Rebrand', 'client_id' => $otherClient->id]);
    $otherChild = Project::create(['name' => 'Copywriting', 'client_id' => $otherClient->id, 'parent_id' => $otherParent->id]);

    $docA = Document::create(['project_id' => $this->child->id, 'name' => 'A', 'type' => 'task', 'content' => 'x']);
    $docB = Document::create(['project_id' => $otherChild->id, 'name' => 'B', 'type' => 'task', 'content' => 'x']);

    $this->artisan('app:merge-subprojects')->assertExitCode(0);

    expect($docA->fresh()->project_id)->toBe($this->parent->id);
    expect($docB->fresh()->project_id)->toBe($otherParent->id);
    expect(Category::where('project_id', $this->parent->id)->where('name', 'Design')->exists())->toBeTrue();
    expect(Category::where('project_id', $otherParent->id)->where('name', 'Copywriting')->exists())->toBeTrue();
});
