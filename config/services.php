<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'embed_model' => env('OPENAI_EMBED_MODEL', 'text-embedding-3-small'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model'       => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        'embed_model' => env('GEMINI_EMBED_MODEL', 'text-embedding-004'),
    ],


    'ollama' => [
        'host' => env('OLLAMA_HOST'),
        'model' => env('OLLAMA_MODEL', 'deepseek-r1:8b'),
        'embedding_model' => env('OLLAMA_EMBED_MODEL', 'nomic-embed-text'),
    ],

    'vector_driver' => env('VECTOR_DRIVER', 'gemini'),
    'llm_driver' => env('LLM_DRIVER', 'gemini'),

];
