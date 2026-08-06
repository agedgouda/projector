<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('redirects guests attempting to log an upload error', function () {
    $this->post('/log-upload-error', ['file_name' => 'diagram.png'])
        ->assertRedirectContains('login');
});

it('allows an authenticated user to log an upload error', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/log-upload-error', ['file_name' => 'diagram.png'])
        ->assertSuccessful()
        ->assertJson(['status' => 'logged']);
});

it('logs the full details of a failed upload', function () {
    $user = User::factory()->create();

    Log::shouldReceive('error')
        ->once()
        ->with('Content upload failed', Mockery::on(function (array $context) use ($user) {
            return $context['user_id'] === $user->id
                && $context['project_id'] === 'proj-123'
                && $context['file_name'] === 'diagram.png'
                && $context['file_size'] === 3145728
                && $context['file_type'] === 'image/png'
                && $context['status'] === 413
                && $context['message'] === 'Request Entity Too Large';
        }));

    $this->actingAs($user)
        ->postJson('/log-upload-error', [
            'project_id' => 'proj-123',
            'file_name' => 'diagram.png',
            'file_size' => 3145728,
            'file_type' => 'image/png',
            'status' => 413,
            'message' => 'Request Entity Too Large',
        ])
        ->assertSuccessful();
});
