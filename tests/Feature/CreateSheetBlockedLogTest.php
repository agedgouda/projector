<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;

it('redirects guests attempting to log a blocked create sheet', function () {
    $this->post('/client-logs/create-sheet-blocked', ['selected_document_id' => '1'])
        ->assertRedirectContains('login');
});

it('logs a blocked create sheet report for an authenticated user', function () {
    Log::spy();

    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/client-logs/create-sheet-blocked', [
        'selected_document_id' => '01a0926a-0254-7032-9707-428f8fcd883b',
        'project_id' => '01a09269-e5b1-704c-b171-e90fa30c53c7',
        'page_url' => 'https://projecthq.app/projects/1?tab=tasks',
    ]);

    $response->assertNoContent();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'Create sheet blocked by stale selectedDocumentId'
            && $context['user_id'] === $user->id
            && $context['selected_document_id'] === '01a0926a-0254-7032-9707-428f8fcd883b'
            && $context['project_id'] === '01a09269-e5b1-704c-b171-e90fa30c53c7');
});
