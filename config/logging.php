<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',

            // я провів спостереження

            // опис спостереження номер 1
            // Питання:
            // Чи (спрацює / залогує помилку) 'daily' якщо відомо що
            //
            // 1. перед 'daily' каналом в списку каналів буде йти 'telegram' канал.
            // Наприклад ['telegram', 'daily']
            // 2. 'telegram' канал (не спрацює / не залогує помилку) через telegram api помилку.
            // Наприклад, "Too many requests, retry after n seconds"

            // Результати спостережень
            // канал 'daily' не спрацював. До нього не дійшла черга.
            // Логування зупинилося в 'telegram' каналі

            // як я зрозумів, що 'daily' канал не спрацював?
            // логи від daily каналу були порожні.
            // В папці storage/logs не з'явився файл system-YYYY-MM-DD.log

            // Помилка не залогувалась повністю. Жоден канал (не залогував помилку).

            // а чому я впевненний, що до 'daily' каналу не дійшла черга, може він з інших причин не спрацював?

            // для цього я провів наступне спостереження.

            // Опис спостереження номер 2
            // Те ж саме що й спостереження номер 1 тільки з іншим порядком каналів
            // ['daily', 'telegram']

            // Результати спостережень
            // канал daily спрацював. Черга дійшли до 'daily' каналу.

            // Висновки
            // помилка логується з початку списку каналів, а не з кінця.

            // Висновки до яких я дійшов на основі проведенних спостережень
            // 1. порядок каналів має значення
            // 2. Логування в поточній версії ларавел працює таким чинов,
            // що якщо один з каналів зламався(кинув виняток) (щось пішло не так в одному каналі), то черга до наступного канала не дійде (наступний канал не запуститься)

            // що якщо перший канал кинув виняток, то наступний канал не спрацює

            // для того щоб не втратити логи, 'telegram' канал має йти останнім в списку каналів. example ['daily', 'telegram']

            // Чому я вирішив це перевірити?
            // Я чомусь не бачив логів помилки від 'daily' канала. Хоча вони мали бути. Я подумав, а може це через
            // порядок каналів. На той момент причина була в іншому.
            // Тому що телеграм був канал був останнім в списку, він не міг ніяк вплинути на
            // daily канал який йшов до нього в списку.

            'channels' => array_merge(
                ['daily',],
                env('APP_ENV') === 'production' ? ['telegram']: []
            ),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/system.log'),
            'level' => 'debug',
            'permission' => octdec((string) env('DEFAULT_FILE_MASK')) ?: null,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/system.log'),
            'level' => 'debug',
            'days' => 14,
            'permission' => octdec((string) env('DEFAULT_FILE_MASK')) ?: null,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'October CMS Log',
            'emoji' => ':boom:',
            'level' => 'critical',
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => 'debug',
            'handler' => Monolog\Handler\SyslogUdpHandler::class,
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
            ],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => 'debug',
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => 'debug',
        ],

        'telegram' => [
            'driver' => 'monolog',
            'level'  => 'error',
            'handler' => \Monolog\Handler\TelegramBotHandler::class,
            'with'    => [
                'apiKey' => env('MY_LOG_BOT_TOKEN'),
                'channel' => env('MY_LOG_BOT_CHAT_ID')
            ],
        ]

    ],

];
