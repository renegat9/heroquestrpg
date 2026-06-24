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

    /*
    |--------------------------------------------------------------------------
    | Gemini TTS — voix des barks de monstres/boss (doc 05/06, ambiance)
    |--------------------------------------------------------------------------
    | Sert UNIQUEMENT à la GÉNÉRATION des assets audio (commande artisan
    | barks:generer + job par boss). Aucun appel en cours de partie : les
    | barks sont des fichiers audio pré-générés, joués par l'écran de table.
    | Sans clé : pas de génération, le jeu lit le texte des barks via la
    | synthèse vocale du navigateur (Web Speech) — toujours jouable sans clé.
    */
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        // Modèle TTS (audio uniquement) — voix des barks/narration pré-générée.
        'model' => env('GEMINI_TTS_MODEL', 'gemini-2.5-flash-preview-tts'),
        // Modèle TEXTE pour le MJ IA (histoire/narration) quand LLM_PROVIDER=gemini.
        // Distinct du modèle TTS (qui ne produit que de l'audio).
        'model_texte' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fournisseur LLM du MJ IA (histoire + narration)
    |--------------------------------------------------------------------------
    | LLM_PROVIDER = anthropic (défaut) | gemini. Choix GLOBAL : tout le texte
    | du MJ (squelette de campagne, détail de quête, narration, menus,
    | habillage des monstres, résumé de fin) passe par le fournisseur choisi.
    | Repli automatique sur Anthropic si « gemini » est demandé sans
    | GEMINI_API_KEY. Le TTS reste sur Gemini quoi qu'il arrive. Sans aucune
    | clé, les skills retombent sur leurs replis codés en dur (jouable).
    */
    'llm' => [
        'provider' => env('LLM_PROVIDER', 'anthropic'),
    ],

];
