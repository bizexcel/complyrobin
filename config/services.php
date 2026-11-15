<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // ComplyRobin's Telegram bot, which posts reminders to the chats organisations verify. Telegram is offered only when set.
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    ],

    // Hosts ComplyRobin may post team chat reminders to (exact for Slack, by dot-prefixed suffix for Teams workflows).
    'chat_webhooks' => [
        'slack_hosts' => ['hooks.slack.com'],
        'teams_host_suffixes' => ['.logic.azure.com', '.environment.api.powerplatform.com'],
    ],

];
