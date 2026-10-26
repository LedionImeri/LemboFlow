<?php
/**
 * LemboFlow local configuration.
 *
 * Copy this file to config/local.php and fill in your values.
 * config/local.php is gitignored: real secrets never go into the repository.
 */

return [
    // Full URL of the app, without a trailing slash.
    'app_url'   => 'http://localhost/LemboFlow',
    'app_name'  => 'LemboFlow',

    // true shows PHP errors in the browser. Keep false anywhere public.
    'debug'     => true,

    'timezone'  => 'Europe/Tirane',

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'lembo_flow',
        'user'     => 'root',
        'password' => '',
    ],

    // LemboAI (Phase 10). The key stays on the server and is never sent to the browser.
    'groq' => [
        'api_key' => '',
        'model'   => 'llama-3.3-70b-versatile',
        // Optional. Only change this to point LemboAI at another OpenAI-compatible endpoint.
        'base_url' => 'https://api.groq.com/openai/v1',
    ],
];
