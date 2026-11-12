<?php

declare(strict_types=1);

/**
 * AI connection. Everything AI is off unless `enabled` is true AND the provider is available: the
 * built-ins need their key in the environment (.env). Keys are never put in this file.
 */
return [
    // Master switch. Off: no AI buttons, endpoints answer 404.
    'enabled' => false,

    // 'claude', 'gemini', 'fake' (no key, no cost, the same answer to every request: your machine
    // and tests only) or the class of a Preflow\Ai\AiClientFactory, built by the container.
    'provider' => 'claude',

    // The model each provider uses.
    'models' => [
        'claude' => 'claude-sonnet-5-5',
        'gemini' => 'gemini-2.5-flash',
    ],

    // Env var holding each provider's API key.
    'keys' => [
        'claude' => 'ANTHROPIC_API_KEY',
        'gemini' => 'GEMINI_API_KEY',
    ],

    // More settings per provider, by its name or class; a factory gets them with `model` and `key`.
    // 'fake' takes 'answer' (an array is sent as JSON, a string as text) and 'usage' ([input, output]).
    'options' => [
        // 'fake' => ['answer' => ['ok' => true]],
    ],

    // USD per million tokens [input, output]; merged over the built-in table, so list only
    // models you add or whose price differs. A model without a price shows a cost of "unknown".
    'pricing' => [
        // 'claude-sonnet-5-5' => [2.00, 10.00],
    ],

    // Usage log and admin edits (usage-YYYY-MM.jsonl, settings.json, jobs/). Relative to the app root.
    'storage' => 'storage/ai',

    // Instructions for every AI request of this project, one string each, e.g.
    // 'Our product is called "Foo"; never translate it.'
    'tuning' => [],

    'translation' => [
        // Per target language: extra instructions, e.g. 'de' => ['Address the reader with "du".'].
        'languages' => [],

        // Per content type: extra instructions, e.g. 'article' => ['Keep the tone playful.'].
        'types' => [],

        // Fixed terms: ['term' => 'Preflow', 'translations' => ['de' => 'Preflow', 'fr' => null], 'types' => ['job'], 'note' => '...'].
        // A translation of null means "keep the term as is" in that language.
        'glossary' => [],

        // Most characters of text sent in one request.
        'batch_chars' => 12000,

        // The machine translator (preflow/i18n): the class of a Preflow\I18n\Machine\MachineTranslator,
        // built by the container. Default: Preflow\I18n\Machine\AiTranslator, through the provider above.
        // 'translator' => \App\Translation\MyTranslator::class,
    ],
];
