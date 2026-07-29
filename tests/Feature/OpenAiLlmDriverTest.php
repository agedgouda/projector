<?php

use App\Services\Ai\Drivers\OpenAiLlmDriver;
use Illuminate\Support\Facades\Http;

it('requires priority in the auto-generated structured output schema, so the model is not blocked from returning it', function () {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [
                ['message' => ['content' => json_encode(['items' => [
                    ['title' => 'Item', 'task' => 'Do it', 'criteria' => [], 'due_date' => null, 'priority' => 'high'],
                ]])]],
            ],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ], 200),
    ]);

    $result = (new OpenAiLlmDriver)->call('System prompt', 'Extract "title", "task", and "criteria" fields.');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $itemSchema = $request->data()['response_format']['json_schema']['schema']['properties']['items']['items'];

        expect($itemSchema['properties'])->toHaveKey('priority');
        expect($itemSchema['properties']['priority']['enum'])->toBe(['low', 'medium', 'high']);
        expect($itemSchema['required'])->toContain('priority');

        return true;
    });

    expect($result['content'][0]['priority'])->toBe('high');
});
