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

    /*
    |--------------------------------------------------------------------------
    | Anthropic (MJ IA)
    |--------------------------------------------------------------------------
    | Clé en .env uniquement, jamais dans l'image (doc 11 §2).
    */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 4096),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Qdrant (bible RAG, doc 12 §7)
    |--------------------------------------------------------------------------
    */
    'qdrant' => [
        'host' => env('QDRANT_HOST', 'qdrant'),
        'port' => (int) env('QDRANT_PORT', 6333),
        'collection' => env('QDRANT_COLLECTION', 'bible'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Voyage AI — embeddings de la bible RAG (doc 11 §6)
    |--------------------------------------------------------------------------
    | Fournisseur retenu (Anthropic n'a pas d'API d'embeddings). Sans clé,
    | repli sur EmbeddingsNuls (similarité lexicale, dev uniquement).
    | ⚠ dimension : fixe la collection Qdrant — ne pas changer en cours de
    | campagne sans recréer la collection.
    */
    'voyage' => [
        'api_key' => env('VOYAGE_API_KEY'),
        'model' => env('VOYAGE_MODEL', 'voyage-3.5'),
        'dimension' => (int) env('VOYAGE_DIMENSION', 1024),
        'base_url' => env('VOYAGE_BASE_URL', 'https://api.voyageai.com'),
        'timeout' => (int) env('VOYAGE_TIMEOUT', 30),
    ],

];
