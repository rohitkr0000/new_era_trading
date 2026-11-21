<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    // Credit due ROI whenever a dashboard loads (local dev without a scheduler). Production: run the scheduler instead.
    // public website URL used in e-mail links (register / referral links)
    'frontend_url' => env('FRONTEND_URL', 'https://neweratrading.net'),
    'support_email' => env('SUPPORT_EMAIL', 'support@neweratrading.net'),

    'accrue_on_request' => (bool) env('ACCRUE_ON_REQUEST', false),

    // DEMO_MODE=true lets sign-ups join with a simulated payment and allows the admin "reset demo data" button. Must be false in production.
    'demo_mode' => (bool) env('DEMO_MODE', false),

    // network-wide numbers (member count, leaderboard, top builders) are recomputed at most this often (seconds)
    'snapshot_cache' => (int) env('SNAPSHOT_CACHE', 60),
    // a member's own dashboard JSON is cached this many seconds (0 = off)
    'member_snapshot_cache' => (int) env('MEMBER_SNAPSHOT_CACHE', 15),

    // admins must confirm every sign-in with an e-mailed code (set ADMIN_2FA=true in production)
    'admin_2fa' => (bool) env('ADMIN_2FA', false),

    // shown on the login page of a DEMO server only (DEMO_MODE=true); the values come from .env, never from the code
    'demo_logins' => env('DEMO_MEMBER_PASSWORD') && env('DEMO_ADMIN_PASSWORD')
        ? ['member' => ['email' => 'demo@mlm.com', 'password' => env('DEMO_MEMBER_PASSWORD'), 'pin' => env('DEMO_PIN')],
           'admin' => ['email' => 'admin@mlm.com', 'password' => env('DEMO_ADMIN_PASSWORD')]]
        : null,

    'backup_keep' => (int) env('BACKUP_KEEP', 14),
    'backup_copy_path' => env('BACKUP_COPY_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
