<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use ZipArchive;

class BuildTeamsAppPackage extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:build-teams-app-package
        {--url= : Public base URL the bot is reachable at (defaults to APP_URL; pass the Expose tunnel URL for local testing)}';

    /**
     * @var string
     */
    protected $description = 'Build the Teams app package (manifest.json + icons, zipped) that a customer\'s Teams admin uploads as a custom app — Teams\' equivalent of the Slack app manifest.';

    private const MANIFEST_VERSION = '1.17';

    private const ACCENT_COLOR = '#4F46E5';

    /**
     * ACCENT_COLOR as RGB, for drawing the color icon.
     */
    private const ACCENT_RGB = [79, 70, 229];

    public function handle(): int
    {
        $appId = config('services.teams.app_id');

        if (! is_string($appId) || blank($appId)) {
            $this->error('TEAMS_APP_ID is not set.');

            return self::FAILURE;
        }

        $url = $this->option('url') ?: config('app.url');
        $baseUrl = is_string($url) ? rtrim($url, '/') : '';
        $host = parse_url($baseUrl, PHP_URL_HOST);

        if (! is_string($host) || ! str_starts_with($baseUrl, 'https://')) {
            $this->error("The bot must be reachable over public HTTPS — got \"{$baseUrl}\". Pass --url=https://<tunnel-or-production-host>.");

            return self::FAILURE;
        }

        $directory = storage_path('app/teams');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = "{$directory}/projector-teams-app.zip";

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', (string) json_encode($this->manifest($appId, $baseUrl, $host), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('color.png', $this->colorIcon());
        $zip->addFromString('outline.png', $this->outlineIcon());
        $zip->close();

        $this->info("Built {$path}");
        $this->line("Messaging endpoint to set on the Azure Bot resource: {$baseUrl}/teams/messages");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $appId, string $baseUrl, string $host): array
    {
        return [
            '$schema' => 'https://developer.microsoft.com/en-us/json-schemas/teams/v'.self::MANIFEST_VERSION.'/MicrosoftTeams.schema.json',
            'manifestVersion' => self::MANIFEST_VERSION,
            'version' => '1.0.0',
            'id' => $appId,
            'developer' => [
                'name' => 'Projector',
                'websiteUrl' => $baseUrl,
                'privacyUrl' => "{$baseUrl}/privacy",
                'termsOfUseUrl' => "{$baseUrl}/privacy",
            ],
            'name' => ['short' => 'Projector', 'full' => 'Projector'],
            'description' => [
                'short' => 'Create tasks and events, and import files, from Teams.',
                'full' => 'Create Projector tasks and events, import files, and generate task reports from Microsoft Teams channels bound to a Projector project.',
            ],
            'icons' => ['color' => 'color.png', 'outline' => 'outline.png'],
            'accentColor' => self::ACCENT_COLOR,
            'bots' => [[
                'botId' => $appId,
                'scopes' => ['team', 'personal', 'groupChat'],
                'supportsFiles' => false,
                'isNotificationOnly' => false,
            ]],
            'permissions' => ['identity', 'messageTeamMembers'],
            'validDomains' => [$host],
        ];
    }

    /**
     * Teams requires a 192x192 full-color icon.
     */
    private function colorIcon(): string
    {
        $image = imagecreatetruecolor(192, 192);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, ...self::ACCENT_RGB));
        imagefilledrectangle($image, 64, 48, 88, 144, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 64, 48, 128, 104, (int) imagecolorallocate($image, 255, 255, 255));

        return $this->png($image);
    }

    /**
     * Teams requires a 32x32 outline icon: white on a transparent background.
     */
    private function outlineIcon(): string
    {
        $image = imagecreatetruecolor(32, 32);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 10, 6, 14, 26, $white);
        imagefilledrectangle($image, 10, 6, 22, 17, $white);

        return $this->png($image);
    }

    private function png(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
