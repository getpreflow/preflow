<?php

declare(strict_types=1);

/**
 * AI connection. Everything AI is off unless `enabled` is true AND the provider's key is set in
 * the environment (.env). Keys are never put in this file.
 */
return [
    // Master switch. Off: no AI buttons, endpoints answer 404.
    'enabled' => false,

    // 'claude' or 'gemini'.
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

        // Fixed terms: ['term' => 'Meeple', 'translations' => ['de' => 'Meeple', 'fr' => null], 'types' => ['game'], 'note' => '...'].
        // A translation of null means "keep the term as is" in that language.
        'glossary' => [],

        // Most characters of text sent in one request.
        'batch_chars' => 12000,
    ],
];
