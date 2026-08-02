<?php

use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Spatie\Health\Notifications\CheckFailedNotification;
use Spatie\Health\Notifications\Notifiable;
use Spatie\Health\ResultStores\EloquentHealthResultStore;

return [
    /*
     * A result store is responsible for saving the results of the checks. The
     * `EloquentHealthResultStore` will save results in the database. You
     * can use multiple stores at the same time.
     */
    'result_stores' => [
        EloquentHealthResultStore::class => [
            'connection' => env('HEALTH_DB_CONNECTION', env('DB_CONNECTION')),
            'model' => HealthCheckResultHistoryItem::class,
            'keep_history_for_days' => 5,
        ],

        /*
        Spatie\Health\ResultStores\CacheHealthResultStore::class => [
            'store' => 'file',
        ],

        Spatie\Health\ResultStores\JsonFileHealthResultStore::class => [
            'disk' => 's3',
            'path' => 'health.json',
        ],

        Spatie\Health\ResultStores\InMemoryHealthResultStore::class,
        */
    ],

    /*
     * FlexPick operational thresholds. Every value here is the single
     * authoritative entry for that limit (spec §14.5, Appendix A).
     * Check names match Spatie's convention: class name minus the "Check" suffix.
     */
    'flexpick' => [
        'result_freshness_minutes' => (int) env('HEALTH_RESULT_FRESHNESS_MINUTES', 15),
        'endpoint_token' => env('HEALTH_ENDPOINT_TOKEN'),
        'queue_heartbeat_minutes' => (int) env('HEALTH_QUEUE_HEARTBEAT_MINUTES', 10),
        'schedule_heartbeat_minutes' => (int) env('HEALTH_SCHEDULE_HEARTBEAT_MINUTES', 10),
        'disk_fail_percent' => (int) env('HEALTH_DISK_FAIL_PERCENT', 85),
        'oldest_queued_minutes' => (int) env('HEALTH_OLDEST_QUEUED_MINUTES', 30),
        'oldest_analyzing_minutes' => (int) env('HEALTH_OLDEST_ANALYZING_MINUTES', 30),

        'pipeline_failure' => [
            'window_hours' => (int) env('HEALTH_PIPELINE_WINDOW_HOURS', 24),
            'min_samples' => (int) env('HEALTH_PIPELINE_MIN_SAMPLES', 5),
            'fail_percent' => (int) env('HEALTH_PIPELINE_FAIL_PERCENT', 40),
        ],

        'mail_failure' => [
            'window_hours' => (int) env('HEALTH_MAIL_WINDOW_HOURS', 24),
            'min_samples' => (int) env('HEALTH_MAIL_MIN_SAMPLES', 5),
            'fail_percent' => (int) env('HEALTH_MAIL_FAIL_PERCENT', 25),
        ],

        /*
         * Severity bands per spec §15.6. Only bands listed in `paging_bands`
         * affect the /health status code; the rest are reported in the body
         * and alert in-app only. A pager that fires on disk warnings stops
         * being trusted, which costs you the critical alerts too.
         */
        'bands' => [
            'Database' => 'critical',
            'Redis' => 'critical',
            'Horizon' => 'critical',
            'Queue' => 'critical',
            'OldestPendingAudit' => 'critical',
            'AuditPipelineFailureRate' => 'high',
            'MailFailureRate' => 'high',
            'Schedule' => 'medium',
            'UsedDiskSpace' => 'medium',
            'Cache' => 'medium',
        ],
        'paging_bands' => ['critical', 'high'],
        'default_band' => 'medium',

        'alert_channels' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('HEALTH_ALERT_CHANNELS', 'mail'))
        ))),
        'alert_throttle_minutes' => (int) env('HEALTH_ALERT_THROTTLE_MINUTES', 60),
        'channel_timeout_seconds' => (int) env('HEALTH_CHANNEL_TIMEOUT_SECONDS', 5),
        'telegram' => [
            'bot_token' => env('HEALTH_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('HEALTH_TELEGRAM_CHAT_ID'),
        ],
        'slack' => [
            'webhook_url' => env('HEALTH_SLACK_WEBHOOK_URL'),
        ],
        'mail' => [
            'to' => env('HEALTH_ALERT_MAIL_TO'),
        ],
    ],

    /*
     * You can get notified when specific events occur. Out of the box you can use 'mail' and 'slack'.
     * For Slack you need to install laravel/slack-notification-channel.
     */
    'notifications' => [
        /*
         * Notifications will only get sent if this option is set to `true`.
         */
        'enabled' => false,

        'notifications' => [
            CheckFailedNotification::class => ['mail'],
        ],

        /*
         * Here you can specify the notifiable to which the notifications should be sent. The default
         * notifiable will use the variables specified in this config file.
         */
        'notifiable' => Notifiable::class,

        /*
         * When checks start failing, you could potentially end up getting
         * a notification every minute.
         *
         * With this setting, notifications are throttled. By default, you'll
         * only get one notification per hour.
         */
        'throttle_notifications_for_minutes' => 60,
        'throttle_notifications_key' => 'health:latestNotificationSentAt:',

        /*
         * When set to true, notifications will only be sent when at least one
         * check has a 'failed' status. Warnings will be ignored.
         */
        'only_on_failure' => false,

        'mail' => [
            'to' => env('HEALTH_TO_ADDRESS', ''),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Example'),
            ],
        ],

        'slack' => [
            'webhook_url' => env('HEALTH_SLACK_WEBHOOK_URL', ''),

            /*
             * If this is set to null the default channel of the webhook will be used.
             */
            'channel' => null,

            'username' => null,

            'icon' => null,
        ],
    ],

    /*
     * You can let Oh Dear monitor the results of all health checks. This way, you'll
     * get notified of any problems even if your application goes totally down. Via
     * Oh Dear, you can also have access to more advanced notification options.
     */
    'oh_dear_endpoint' => [
        'enabled' => false,

        /*
         * When this option is enabled, the checks will run before sending a response.
         * Otherwise, we'll send the results from the last time the checks have run.
         */
        'always_send_fresh_results' => true,

        /*
         * The secret that is displayed at the Application Health settings at Oh Dear.
         */
        'secret' => env('OH_DEAR_HEALTH_CHECK_SECRET'),

        /*
         * The URL that should be configured in the Application health settings at Oh Dear.
         */
        'url' => '/oh-dear-health-check-results',
    ],

    /*
     * You can specify a heartbeat URL for the Horizon check.
     * This URL will be pinged if the Horizon check is successful.
     * This way you can get notified if Horizon goes down.
     */
    'horizon' => [
        'heartbeat_url' => env('HORIZON_HEARTBEAT_URL'),
    ],

    /*
     * You can specify a heartbeat URL for the Schedule check.
     * This URL will be pinged if the Schedule check is successful.
     * This way you can get notified if the schedule fails to run.
     */
    'schedule' => [
        'heartbeat_url' => env('SCHEDULE_HEARTBEAT_URL'),
    ],

    /*
     * You can set a theme for the local results page
     *
     * - light: light mode
     * - dark: dark mode
     */
    'theme' => 'light',

    /*
     * When enabled, completed `HealthQueueJob`s will be displayed
     * in Horizon's silenced jobs screen.
     */
    'silence_health_queue_job' => true,

    /*
     * The response code to use for HealthCheckJsonResultsController when a health
     * check has failed
     */
    'json_results_failure_status' => 200,

    /*
     * You can specify a secret token that needs to be sent in the X-Secret-Token for secured access.
     */
    'secret_token' => env('HEALTH_SECRET_TOKEN'),

/**
 * By default, conditionally skipped health checks are treated as failures.
 * You can override this behavior by uncommenting the configuration below.
 *
 * @link https://spatie.be/docs/laravel-health/v1/basic-usage/conditionally-running-or-modifying-checks
 */
    // 'treat_skipped_as_failure' => false
];
