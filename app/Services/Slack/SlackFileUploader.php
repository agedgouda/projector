<?php

namespace App\Services\Slack;

use App\Models\SlackWorkspace;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Posts a file into a Slack channel as the workspace's bot. Slack retired the one-call
 * files.upload; the replacement is a three-step flow — reserve an upload URL, send the bytes
 * there, then complete the upload and share it to a channel. Requires the `files:write` bot
 * scope, and the bot has to be a member of the channel it's sharing into.
 */
class SlackFileUploader
{
    private const API_BASE = 'https://slack.com/api';

    /**
     * @throws \RuntimeException carrying Slack's own error code (e.g. `missing_scope`,
     *                           `not_in_channel`) so the caller can tell the user what to fix.
     */
    public function upload(SlackWorkspace $workspace, string $channelId, string $filename, string $contents, ?string $comment = null): void
    {
        $reservation = Http::withToken($workspace->bot_access_token)
            ->asForm()
            ->post(self::API_BASE.'/files.getUploadURLExternal', [
                'filename' => $filename,
                'length' => strlen($contents),
            ]);

        $this->assertOk($reservation, 'files.getUploadURLExternal');

        $uploadUrl = $reservation->json('upload_url');
        $fileId = $reservation->json('file_id');

        if (! is_string($uploadUrl) || ! is_string($fileId)) {
            throw new \RuntimeException('Slack did not return an upload URL.');
        }

        $upload = Http::withBody($contents, 'application/octet-stream')->post($uploadUrl);

        if ($upload->failed()) {
            throw new \RuntimeException('Slack rejected the file upload (HTTP '.$upload->status().').');
        }

        $completion = Http::withToken($workspace->bot_access_token)
            ->post(self::API_BASE.'/files.completeUploadExternal', array_filter([
                'files' => [['id' => $fileId, 'title' => $filename]],
                'channel_id' => $channelId,
                'initial_comment' => $comment,
            ]));

        $this->assertOk($completion, 'files.completeUploadExternal');
    }

    /**
     * Slack answers HTTP 200 even for failures — the real outcome is the JSON body's `ok`.
     */
    private function assertOk(Response $response, string $method): void
    {
        if ($response->failed() || $response->json('ok') !== true) {
            $error = $response->json('error');

            throw new \RuntimeException($method.' failed: '.(is_string($error) ? $error : $response->body()));
        }
    }
}
