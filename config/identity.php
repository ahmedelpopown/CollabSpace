<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | Password reset links are sent to the API consumer's reset-password page.
    | Do not point this to the Laravel API unless the API also serves that page.
    |
    */
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:3000')),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Personal Access Token Lifetimes
    |--------------------------------------------------------------------------
    |
    | Sanctum supports a different expires_at value for each personal token.
    | These defaults are configurable and do not change the password/session
    | authentication behavior of Sanctum's first-party SPA mode.
    |
    */
    'token_expiration_minutes' => (int) env('AUTH_TOKEN_EXPIRATION_MINUTES', 720),
    'remember_token_expiration_minutes' => (int) env('AUTH_REMEMBER_TOKEN_EXPIRATION_MINUTES', 43200),
];
