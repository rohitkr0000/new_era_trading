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

    // On-chain deposit verification (Etherscan API V2 covers BNB Smart Chain with chainid=56). Leave the key empty to verify by hand.
    'chain' => [
        'key'      => env('ETHERSCAN_API_KEY'),
        'chain_id' => (int) env('CHAIN_ID', 56),
        'usdt'     => env('USDT_CONTRACT', '0x55d398326f99059fF775485246999027B3197955'),   // USDT (BEP20)
        'decimals' => (int) env('USDT_DECIMALS', 18),
        'api'      => env('CHAIN_API_URL', 'https://api.etherscan.io/v2/api'),
    ],

];
