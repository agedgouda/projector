<?php

beforeEach(function () {
    config(['services.teams.app_id' => 'fake-teams-app-id']);
    @unlink(storage_path('app/teams/projector-teams-app.zip'));
});

it('builds a zip with a manifest pointing the bot at the given public URL', function () {
    $this->artisan('app:build-teams-app-package', ['--url' => 'https://abc.sharedwithexpose.com'])
        ->expectsOutputToContain('https://abc.sharedwithexpose.com/teams/messages')
        ->assertSuccessful();

    $zip = new ZipArchive;
    $zip->open(storage_path('app/teams/projector-teams-app.zip'));
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

    expect($manifest['id'])->toBe('fake-teams-app-id')
        ->and($manifest['bots'][0]['botId'])->toBe('fake-teams-app-id')
        ->and($manifest['validDomains'])->toBe(['abc.sharedwithexpose.com'])
        ->and(getimagesizefromstring((string) $zip->getFromName('color.png')))->toMatchArray([0 => 192, 1 => 192])
        ->and(getimagesizefromstring((string) $zip->getFromName('outline.png')))->toMatchArray([0 => 32, 1 => 32]);

    $zip->close();
});

it('refuses a non-HTTPS URL', function () {
    $this->artisan('app:build-teams-app-package', ['--url' => 'http://projector.test'])->assertFailed();
});

it('refuses to build without an app id', function () {
    config(['services.teams.app_id' => null]);

    $this->artisan('app:build-teams-app-package', ['--url' => 'https://abc.sharedwithexpose.com'])->assertFailed();
});
