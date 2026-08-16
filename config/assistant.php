<?php

declare(strict_types=1);

return [
    /*
    |---------------------------------------------------------------------------
    | The assistant
    |---------------------------------------------------------------------------
    |
    | Answers questions about this tool in the command palette. Off unless a key
    | is set, so an install without one shows no dead affordance rather than a
    | button that fails when pressed.
    |
    */

    'enabled' => (bool) env('ASSISTANT_ENABLED', true),

    'key' => env('OPENROUTER_API_KEY'),

    'model' => env('ASSISTANT_MODEL', 'openai/gpt-4-turbo'),

    /*
    | Short answers, and a hard ceiling on them. This is a palette, not a chat
    | window — an answer that scrolls is one nobody reads, and a runaway
    | generation is a bill nobody sanctioned.
    */
    'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 1024),

    /*
    | Effort trades depth against latency and spend. Questions about a fixed
    | catalogue of pages are not the hard end of what the model can do, and the
    | palette is somewhere people wait — so this is deliberately low rather
    | than the API default.
    */
    'effort' => env('ASSISTANT_EFFORT', 'low'),

    /*
    | Per account, per minute. Rate limiting here is per subscriber rather than
    | per IP for the same reason it is everywhere else in this application: one
    | noisy account must not be able to spend everybody else's headroom.
    */
    'rate_limit' => (int) env('ASSISTANT_RATE_LIMIT', 10),
];
