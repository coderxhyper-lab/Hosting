<?php
declare(strict_types=1);

/*
============================================================
 VICKY RAILWAY BOT HOSTER
============================================================

Flow:

Admin uploads .php / .py
        ↓
Read source
        ↓
Detect Telegram BOT TOKEN
Detect ADMIN ID
        ↓
Telegram getMe()
        ↓
Show real bot username
        ↓
VERIFY button
        ↓
Create Railway project
        ↓
Set environment variables
        ↓
Deploy
        ↓
Generate Railway domain
        ↓
Set Telegram webhook
        ↓
LIVE

Requirements on the SERVER:

PHP 8+
cURL
shell_exec / exec
Railway CLI installed
Railway authenticated

Railway automation:
RAILWAY_API_TOKEN must be configured
on the hosting server environment.

============================================================
*/


/* =========================================================
   CONFIG
========================================================= */

$MANAGER_BOT_TOKEN =
    '7832316573:AAHuDnlgw1pUSFnWFrxe5flPpoKlBJ7nYqI';

$ADMIN_ID =
    '8897821078';

/*
 * DO NOT put Railway token in Telegram source.
 *
 * Set it on the server:
 *
 * export RAILWAY_API_TOKEN="YOUR_RAILWAY_TOKEN"
 *
 * or configure it in hosting environment variables.
 */

$RAILWAY_TOKEN =
    getenv('RAILWAY_API_TOKEN');

if (!$RAILWAY_TOKEN) {

    /*
     * Manager can still start,
     * but deployment will be blocked.
     */

    $RAILWAY_TOKEN = '';
}


/* =========================================================
   PATHS
========================================================= */

$BASE =
    __DIR__;

$DATA =
    $BASE . '/data';

$UPLOADS =
    $DATA . '/uploads';

$PROJECTS =
    $DATA . '/projects';

$DB =
    $DATA . '/bots.json';

$USAGE =
    $DATA . '/usage.json';


foreach ([
    $DATA,
    $UPLOADS,
    $PROJECTS
] as $dir) {

    if (!is_dir($dir)) {

        mkdir(
            $dir,
            0755,
            true
        );
    }
}


/* =========================================================
   LIMIT
========================================================= */

$DAILY_LIMIT = 100;


/* =========================================================
   JSON
========================================================= */

function readJson(
    string $file,
    array $default = []
): array {

    if (!file_exists($file)) {
        return $default;
    }

    $data =
        json_decode(
            file_get_contents($file),
            true
        );

    return is_array($data)
        ? $data
        : $default;
}


function writeJson(
    string $file,
    array $data
): void {

    file_put_contents(
        $file,
        json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES
        ),
        LOCK_EX
    );
}


/* =========================================================
   TELEGRAM API
========================================================= */

function tg(
    string $method,
    array $data = []
): array {

    global $MANAGER_BOT_TOKEN;

    $url =
        'https://api.telegram.org/bot'
        . $MANAGER_BOT_TOKEN
        . '/'
        . $method;

    $ch =
        curl_init($url);

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60
        ]
    );

    $response =
        curl_exec($ch);

    curl_close($ch);

    $json =
        json_decode(
            $response ?: '',
            true
        );

    return is_array($json)
        ? $json
        : [];
}


function send(
    string $chat,
    string $text,
    ?array $keyboard = null
): void {

    $data = [
        'chat_id' => $chat,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {

        $data['reply_markup'] =
            json_encode(
                $keyboard
            );
    }

    tg(
        'sendMessage',
        $data
    );
}


/* =========================================================
   ADMIN
========================================================= */

function admin(
    $id
): bool {

    global $ADMIN_ID;

    return
        (string)$id ===
        (string)$ADMIN_ID;
}


/* =========================================================
   DAILY USAGE
========================================================= */

function usage(): array {

    global $USAGE;

    $today =
        date('Y-m-d');

    $data =
        readJson(
            $USAGE,
            [
                'date' => $today,
                'count' => 0
            ]
        );

    if (
        ($data['date'] ?? '')
        !==
        $today
    ) {

        $data = [
            'date' => $today,
            'count' => 0
        ];

        writeJson(
            $USAGE,
            $data
        );
    }

    return $data;
}


function canDeploy(): bool {

    global $DAILY_LIMIT;

    return
        usage()['count']
        <
        $DAILY_LIMIT;
}


function addUsage(): void {

    global $USAGE;

    $data =
        usage();

    $data['count']++;

    writeJson(
        $USAGE,
        $data
    );
}


/* =========================================================
   COMMAND EXECUTION
========================================================= */

function runCommand(
    string $command
): array {

    $output = [];

    $code = 0;

    exec(
        $command . ' 2>&1',
        $output,
        $code
    );

    return [
        'code' => $code,
        'output' =>
            implode(
                "\n",
                $output
            )
    ];
}


/* =========================================================
   RAILWAY ENVIRONMENT
========================================================= */

function railwayEnv(
    string $token
): string {

    return
        'RAILWAY_API_TOKEN='
        . escapeshellarg(
            $token
        );
}


/* =========================================================
   RAILWAY COMMAND
========================================================= */

function railway(
    string $command
): array {

    global $RAILWAY_TOKEN;

    if (!$RAILWAY_TOKEN) {

        return [
            'code' => 1,
            'output' =>
                'RAILWAY_API_TOKEN is not configured.'
        ];
    }

    return runCommand(
        railwayEnv(
            $RAILWAY_TOKEN
        )
        . ' railway '
        . $command
    );
}


/* =========================================================
   TOKEN DETECTION
========================================================= */

function detectToken(
    string $source
): ?string {

    $patterns = [

        /*
         * Direct Telegram token
         */

        '/\b\d{8,12}:[A-Za-z0-9_-]{30,}\b/',

        /*
         * PHP/Python assignments
         */

        '/(?:BOT_TOKEN|TOKEN)\s*(?:=|=>)\s*[\'"]([^\'"]+)[\'"]/i',

        '/(?:BOT_TOKEN|TOKEN)\s*=\s*([^\s;]+)/i'

    ];

    foreach (
        $patterns as $pattern
    ) {

        if (
            preg_match(
                $pattern,
                $source,
                $m
            )
        ) {

            return
                $m[1]
                ??
                $m[0];
        }
    }

    return null;
}


/* =========================================================
   ADMIN ID DETECTION
========================================================= */

function detectAdmin(
    string $source
): ?string {

    $patterns = [

        '/(?:ADMIN_ID|OWNER_ID)\s*(?:=|=>)\s*[\'"]?(\d{5,15})/i',

        '/(?:ADMIN_IDS)\s*=\s*[\[\(]\s*[\'"]?(\d{5,15})/i'

    ];

    foreach (
        $patterns as $pattern
    ) {

        if (
            preg_match(
                $pattern,
                $source,
                $m
            )
        ) {

            return $m[1];
        }
    }

    return null;
}


/* =========================================================
   TELEGRAM BOT REAL VERIFICATION
========================================================= */

function verifyTelegramBot(
    string $token
): array {

    if (!$token) {

        return [
            'ok' => false,
            'error' =>
                'Telegram bot token was not detected.'
        ];
    }

    $url =
        'https://api.telegram.org/bot'
        . $token
        . '/getMe';

    $ch =
        curl_init($url);

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10
        ]
    );

    $response =
        curl_exec($ch);

    $http =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);

    $json =
        json_decode(
            $response ?: '',
            true
        );

    if (
        $http !== 200
        ||
        !isset(
            $json['ok']
        )
    ) {

        return [
            'ok' => false,
            'error' =>
                'Telegram token is invalid or Telegram API is unreachable.'
        ];
    }

    $result =
        $json['result'];

    return [

        'ok' => true,

        'id' =>
            $result['id'] ?? '',

        'username' =>
            $result['username'] ?? '',

        'name' =>
            $result['first_name'] ?? '',

        'can_join_groups' =>
            $result['can_join_groups'] ?? false

    ];
}


/* =========================================================
   MASK TOKEN
========================================================= */

function mask(
    ?string $token
): string {

    if (!$token) {
        return 'Not detected';
    }

    if (
        strlen($token) < 10
    ) {

        return '••••••••';
    }

    return
        '••••••'
        .
        substr(
            $token,
            -6
        );
}


/* =========================================================
   PROJECT ID
========================================================= */

function projectId(): string {

    return
        'bot_' .
        date('Ymd_His') .
        '_' .
        bin2hex(
            random_bytes(3)
        );
}


/* =========================================================
   PATCH SOURCE
========================================================= */

function prepareSource(
    string $file,
    string $token,
    string $adminId,
    string $webhook
): void {

    $source =
        file_get_contents(
            $file
        );

    /*
     * Replace common token assignments.
     *
     * This is intentionally conservative.
     */

    $source =
        preg_replace(
            '/(BOT_TOKEN\s*=\s*[\'"])[^\'"]+([\'"])/i',
            '$1'
            . addslashes($token)
            . '$2',
            $source
        );


    $source =
        preg_replace(
            '/(ADMIN_ID\s*=\s*[\'"]?)[0-9]{5,15}([\'"]?)/i',
            '$1'
            . $adminId
            . '$2',
            $source
        );


    /*
     * WEBHOOK_URL replacement where already present.
     */

    $source =
        preg_replace(
            '/(WEBHOOK_URL\s*=\s*[\'"])[^\'"]*([\'"])/i',
            '$1'
            . $webhook
            . '$2',
            $source
        );


    file_put_contents(
        $file,
        $source
    );
}


/* =========================================================
   CREATE RAILWAY PROJECT
========================================================= */

function railwayCreateProject(
    string $dir,
    string $name
): array {

    $old =
        getcwd();

    chdir($dir);

    /*
     * --new creates a new project/service.
     * --yes avoids interactive prompts.
     */

    $result =
        railway(
            'up --new --yes --name '
            . escapeshellarg($name)
            . ' --detach'
        );

    chdir($old);

    return $result;
}


/* =========================================================
   GENERATE DOMAIN
========================================================= */

function railwayDomain(
    string $dir
): ?string {

    $old =
        getcwd();

    chdir($dir);

    $result =
        railway(
            'domain --json'
        );

    chdir($old);

    if (
        $result['code'] !== 0
    ) {

        return null;
    }


    /*
     * Try JSON first.
     */

    $json =
        json_decode(
            $result['output'],
            true
        );

    if (
        is_array($json)
    ) {

        foreach (
            $json as $item
        ) {

            if (
                is_string($item)
                &&
                str_contains(
                    $item,
                    'railway.app'
                )
            ) {

                return
                    'https://'
                    . preg_replace(
                        '#^https?://#',
                        '',
                        $item
                    );
            }
        }
    }


    /*
     * Fallback parser.
     */

    if (
        preg_match(
            '#https://[A-Za-z0-9.-]+\.up\.railway\.app#',
            $result['output'],
            $m
        )
    ) {

        return $m[0];
    }

    return null;
}


/* =========================================================
   SET RAILWAY VARIABLES
========================================================= */

function setRailwayVariables(
    string $dir,
    string $token,
    string $adminId,
    string $webhook
): bool {

    $old =
        getcwd();

    chdir($dir);


    /*
     * Railway CLI variable set.
     */

    $commands = [

        'variable set '
        . 'BOT_TOKEN='
        . escapeshellarg($token),

        'variable set '
        . 'ADMIN_ID='
        . escapeshellarg($adminId),

        'variable set '
        . 'WEBHOOK_URL='
        . escapeshellarg($webhook)

    ];


    foreach (
        $commands as $command
    ) {

        $result =
            railway(
                $command
            );

        if (
            $result['code'] !== 0
        ) {

            chdir($old);

            return false;
        }
    }


    chdir($old);

    return true;
}


/* =========================================================
   REDEPLOY AFTER VARIABLES
========================================================= */

function railwayRedeploy(
    string $dir
): bool {

    $old =
        getcwd();

    chdir($dir);

    $result =
        railway(
            'redeploy -y'
        );

    chdir($old);

    return
        $result['code'] === 0;
}


/* =========================================================
   SET TELEGRAM WEBHOOK
========================================================= */

function setWebhook(
    string $botToken,
    string $domain
): array {

    $url =
        rtrim(
            $domain,
            '/'
        )
        . '/';

    $telegramUrl =
        'https://api.telegram.org/bot'
        . $botToken
        . '/setWebhook';

    $ch =
        curl_init(
            $telegramUrl
        );

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'url' => $url
            ],
            CURLOPT_TIMEOUT => 30
        ]
    );

    $response =
        curl_exec($ch);

    curl_close($ch);

    $json =
        json_decode(
            $response ?: '',
            true
        );

    return is_array($json)
        ? $json
        : [
            'ok' => false
        ];
}


/* =========================================================
   MAIN KEYBOARD
========================================================= */

function mainKeyboard(): array {

    return [
        'inline_keyboard' => [

            [

                [
                    'text' =>
                        '📤 Upload Bot',
                    'callback_data' =>
                        'upload'
                ]

            ],

            [

                [
                    'text' =>
                        '🤖 My Bots',
                    'callback_data' =>
                        'bots'
                ],

                [
                    'text' =>
                        '📊 Usage',
                    'callback_data' =>
                        'usage'
                ]

            ]

        ]
    ];
}


/* =========================================================
   INPUT
========================================================= */

$raw =
    file_get_contents(
        'php://input'
    );


/*
 * Browser health check
 */

if (!$raw) {

    header(
        'Content-Type: text/plain'
    );

    echo
        "VICKY Railway Bot Host Manager ONLINE\n";

    echo
        "PHP: "
        . PHP_VERSION
        . "\n";

    echo
        "Railway CLI: ";

    $test =
        railway(
            '--version'
        );

    echo
        $test['code'] === 0
        ? trim($test['output'])
        : 'NOT CONFIGURED';

    exit;
}


$update =
    json_decode(
        $raw,
        true
    );


if (!is_array($update)) {
    exit;
}


/* =========================================================
   CALLBACK
========================================================= */

if (
    isset(
        $update['callback_query']
    )
) {

    $callback =
        $update['callback_query'];

    $userId =
        $callback['from']['id'] ??
        null;

    $chatId =
        $callback['message']['chat']['id'] ??
        null;

    $action =
        $callback['data'] ??
        '';


    if (!admin($userId)) {

        tg(
            'answerCallbackQuery',
            [
                'callback_query_id' =>
                    $callback['id'],
                'text' =>
                    'Admin access only',
                'show_alert' => true
            ]
        );

        exit;
    }


    tg(
        'answerCallbackQuery',
        [
            'callback_query_id' =>
                $callback['id']
        ]
    );


    /* ---------------------------------------------
       UPLOAD
    --------------------------------------------- */

    if (
        $action === 'upload'
    ) {

        send(
            (string)$chatId,

            "📤 <b>Upload Bot</b>\n\n"
            . "Send your Telegram bot file:\n\n"
            . "• .php\n"
            . "• .py\n\n"
            . "I will read and verify it."
        );

        exit;
    }


    /* ---------------------------------------------
       USAGE
    --------------------------------------------- */

    if (
        $action === 'usage'
    ) {

        $u =
            usage();

        send(
            (string)$chatId,

            "📊 <b>Daily Deployment</b>\n\n"
            . "Used: <b>"
            . $u['count']
            . "/"
            . $DAILY_LIMIT
            . "</b>\n"
            . "Remaining: <b>"
            . max(
                0,
                $DAILY_LIMIT
                -
                $u['count']
            )
            . "</b>"
        );

        exit;
    }


    /* ---------------------------------------------
       VERIFY
    --------------------------------------------- */

    if (
        str_starts_with(
            $action,
            'verify:'
        )
    ) {

        $id =
            substr(
                $action,
                7
            );


        $bots =
            readJson(
                $DB
            );


        if (
            !isset(
                $bots[$id]
            )
        ) {

            send(
                (string)$chatId,
                "❌ Verification job expired."
            );

            exit;
        }


        $bot =
            $bots[$id];


        if (
            empty(
                $bot['token']
            )
        ) {

            send(
                (string)$chatId,
                "❌ Telegram bot token not detected."
            );

            exit;
        }


        send(
            (string)$chatId,
            "🔎 <b>Verifying Telegram bot...</b>"
        );


        $check =
            verifyTelegramBot(
                $bot['token']
            );


        if (
            !$check['ok']
        ) {

            send(
                (string)$chatId,

                "❌ <b>Telegram verification failed</b>\n\n"
                . htmlspecialchars(
                    $check['error']
                )
            );

            exit;
        }


        $bots[$id]['telegram_id'] =
            $check['id'];

        $bots[$id]['username'] =
            $check['username'];

        $bots[$id]['name'] =
            $check['name'];

        $bots[$id]['verified'] =
            true;


        writeJson(
            $DB,
            $bots
        );


        send(
            (string)$chatId,

            "✅ <b>Telegram Bot Verified</b>\n\n"

            . "🤖 Username: <b>@"
            . htmlspecialchars(
                $check['username']
            )
            . "</b>\n"

            . "📛 Name: <b>"
            . htmlspecialchars(
                $check['name']
            )
            . "</b>\n"

            . "🆔 Telegram Bot ID: <code>"
            . $check['id']
            . "</code>\n"

            . "👤 Admin ID: <code>"
            . $bot['admin_id']
            . "</code>\n"

            . "🔐 Token: <code>"
            . mask(
                $bot['token']
            )
            . "</code>\n\n"

            . "Everything is ready for Railway deployment.",

            [
                'inline_keyboard' => [

                    [

                        [
                            'text' =>
                                '🚀 DEPLOY TO RAILWAY',
                            'callback_data' =>
                                'deploy:' . $id
                        ]

                    ]

                ]
            ]
        );

        exit;
    }


    /* ---------------------------------------------
       DEPLOY
    --------------------------------------------- */

    if (
        str_starts_with(
            $action,
            'deploy:'
        )
    ) {

        $id =
            substr(
                $action,
                7
            );


        if (!canDeploy()) {

            send(
                (string)$chatId,
                "⛔ Daily 100-bot deployment limit reached."
            );

            exit;
        }


        if (!$RAILWAY_TOKEN) {

            send(
                (string)$chatId,

                "❌ <b>Railway authentication missing.</b>\n\n"
                . "Set <code>RAILWAY_API_TOKEN</code> "
                . "on the manager server."
            );

            exit;
        }


        $bots =
            readJson(
                $DB
            );


        if (
            !isset(
                $bots[$id]
            )
        ) {

            send(
                (string)$chatId,
                "❌ Bot not found."
            );

            exit;
        }


        $bot =
            $bots[$id];


        if (
            empty(
                $bot['verified']
            )
        ) {

            send(
                (string)$chatId,
                "❌ Verify the bot first."
            );

            exit;
        }


        send(
            (string)$chatId,
            "🚀 <b>Railway deployment started...</b>\n\n"
            . "1️⃣ Creating project\n"
            . "2️⃣ Uploading source\n"
            . "3️⃣ Setting variables\n"
            . "4️⃣ Building\n"
            . "5️⃣ Creating domain\n"
            . "6️⃣ Setting Telegram webhook"
        );


        $projectDir =
            $PROJECTS
            . '/'
            . $id;


        if (!is_dir(
            $projectDir
        )) {

            mkdir(
                $projectDir,
                0755,
                true
            );
        }


        /*
         * Copy uploaded source
         */

        $source =
            $bot['source'];

        $target =
            $projectDir
            . '/'
            . basename(
                $source
            );


        copy(
            $source,
            $target
        );


        /*
         * Detect entry file
         */

        $entry =
            basename(
                $target
            );


        /*
         * Railway/Docker deployment.
         *
         * For PHP webhook bot:
         * create simple Dockerfile.
         */

        if (
            $bot['language']
            ===
            'PHP'
        ) {

            $dockerfile = <<<DOCKER
FROM php:8.3-apache

WORKDIR /var/www/html

COPY . /var/www/html/

RUN a2enmod rewrite

EXPOSE 80

CMD ["apache2-foreground"]
DOCKER;

        } else {

            /*
             * Python webhook/polling bot.
             *
             * Railway will use this start command.
             */

            $dockerfile = <<<DOCKER
FROM python:3.12-slim

WORKDIR /app

COPY . /app

RUN if [ -f requirements.txt ]; then pip install --no-cache-dir -r requirements.txt; fi

CMD ["python", "{$entry}"]
DOCKER;
        }


        file_put_contents(
            $projectDir
            . '/Dockerfile',
            $dockerfile
        );


        /*
         * Create Railway project
         */

        $create =
            railwayCreateProject(
                $projectDir,
                'vicky-' . $id
            );


        if (
            $create['code'] !== 0
        ) {

            send(
                (string)$chatId,

                "❌ <b>Railway project creation failed</b>\n\n"
                . "<pre>"
                . htmlspecialchars(
                    substr(
                        $create['output'],
                        -3000
                    )
                )
                . "</pre>"
            );

            exit;
        }


        /*
         * First deployment has been queued.
         *
         * Give Railway a little time
         * before domain/variables operations.
         */

        sleep(8);


        /*
         * Generate Railway domain
         */

        $domain =
            railwayDomain(
                $projectDir
            );


        if (!$domain) {

            send(
                (string)$chatId,

                "⚠️ Deployment created, "
                . "but Railway domain could not yet be generated.\n\n"
                . "Open Railway project and create a service domain."
            );

            exit;
        }


        /*
         * Webhook URL
         */

        $webhook =
            rtrim(
                $domain,
                '/'
            )
            . '/';


        /*
         * Set environment variables
         */

        $variables =
            setRailwayVariables(
                $projectDir,
                $bot['token'],
                $ADMIN_ID,
                $webhook
            );


        if (!$variables) {

            send(
                (string)$chatId,

                "⚠️ Railway deployed, but "
                . "environment variables could not be updated.\n\n"
                . "Domain:\n"
                . $webhook
            );

            exit;
        }


        /*
         * Redeploy so new variables are used.
         */

        railwayRedeploy(
            $projectDir
        );


        sleep(8);


        /*
         * Set Telegram webhook.
         */

        $hook =
            setWebhook(
                $bot['token'],
                $webhook
            );


        /*
         * Save deployment.
         */

        $bots[$id]['domain'] =
            $domain;

        $bots[$id]['webhook'] =
            $webhook;

        $bots[$id]['webhook_ok'] =
            $hook['ok'] ?? false;

        $bots[$id]['status'] =
            'LIVE';

        $bots[$id]['deployed_at'] =
            date('c');


        writeJson(
            $DB,
            $bots
        );


        addUsage();


        /*
         * FINAL RESULT
         */

        $webhookStatus =
            ($hook['ok'] ?? false)
            ? '🟢 Webhook set'
            : '🔴 Webhook failed';


        send(
            (string)$chatId,

            "🎉 <b>BOT DEPLOYED SUCCESSFULLY</b>\n\n"

            . "🤖 Bot: <b>@"
            . htmlspecialchars(
                $bot['username']
            )
            . "</b>\n"

            . "💻 Language: <b>"
            . $bot['language']
            . "</b>\n"

            . "🆔 Bot ID: <code>"
            . $id
            . "</code>\n\n"

            . "🌐 <b>Railway Domain</b>\n"
            . "<code>"
            . htmlspecialchars(
                $domain
            )
            . "</code>\n\n"

            . "🔗 <b>Webhook</b>\n"
            . "<code>"
            . htmlspecialchars(
                $webhook
            )
            . "</code>\n\n"

            . $webhookStatus
            . "\n\n"

            . "📊 Today's deployments: <b>"
            . usage()['count']
            . "/"
            . $DAILY_LIMIT
            . "</b>",

            [
                'inline_keyboard' => [

                    [

                        [
                            'text' =>
                                '🌐 OPEN DOMAIN',
                            'url' =>
                                $domain
                        ]

                    ],

                    [

                        [
                            'text' =>
                                '🤖 My Bots',
                            'callback_data' =>
                                'bots'
                        ]

                    ]

                ]
            ]
        );

        exit;
    }


    /* ---------------------------------------------
       MY BOTS
    --------------------------------------------- */

    if (
        $action === 'bots'
    ) {

        $bots =
            readJson(
                $DB
            );


        if (!$bots) {

            send(
                (string)$chatId,
                "🤖 No deployed bots."
            );

            exit;
        }


        foreach (
            $bots as $id => $bot
        ) {

            send(
                (string)$chatId,

                "🤖 <b>"
                . htmlspecialchars(
                    $bot['name']
                    ?? 'Bot'
                )
                . "</b>\n\n"

                . "Username: <b>@"
                . htmlspecialchars(
                    $bot['username']
                    ?? 'unknown'
                )
                . "</b>\n"

                . "Status: <b>"
                . htmlspecialchars(
                    $bot['status']
                    ?? 'unknown'
                )
                . "</b>\n\n"

                . "🌐 "
                . htmlspecialchars(
                    $bot['domain']
                    ?? 'No domain'
                ),

                [
                    'inline_keyboard' => [

                        [

                            [
                                'text' =>
                                    '🌐 Open',
                                'url' =>
                                    $bot['domain']
                                    ?? 'https://railway.app'
                            ]

                        ]

                    ]
                ]
            );
        }

        exit;
    }
}


/* =========================================================
   MESSAGE / FILE UPLOAD
========================================================= */

if (
    isset(
        $update['message']
    )
) {

    $message =
        $update['message'];

    $chatId =
        $message['chat']['id']
        ?? null;

    $userId =
        $message['from']['id']
        ?? null;


    if (!admin($userId)) {

        send(
            (string)$chatId,
            "⛔ <b>Admin access only.</b>"
        );

        exit;
    }


    /* START */

    if (
        isset(
            $message['text']
        )
        &&
        trim(
            $message['text']
        )
        ===
        '/start'
    ) {

        $u =
            usage();

        send(
            (string)$chatId,

            "🚀 <b>VICKY RAILWAY BOT HOST</b>\n\n"

            . "📤 Upload .PHP / .PY\n"
            . "🔍 Automatic source analysis\n"
            . "🤖 Real Telegram verification\n"
            . "🚂 Railway deployment\n"
            . "🌐 Automatic domain\n"
            . "🔗 Automatic webhook\n\n"

            . "📊 Deployments today: <b>"
            . $u['count']
            . "/"
            . $DAILY_LIMIT
            . "</b>",

            mainKeyboard()
        );

        exit;
    }


    /* FILE */

    if (
        isset(
            $message['document']
        )
    ) {

        $document =
            $message['document'];

        $filename =
            $document['file_name']
            ?? '';


        if (
            !preg_match(
                '/\.(php|py)$/i',
                $filename
            )
        ) {

            send(
                (string)$chatId,
                "❌ Only .php and .py files are allowed."
            );

            exit;
        }


        if (
            !canDeploy()
        ) {

            send(
                (string)$chatId,
                "⛔ Daily 100 deployment limit reached."
            );

            exit;
        }


        send(
            (string)$chatId,

            "🔎 <b>Reading your bot...</b>\n\n"
            . "Checking source..."
        );


        $fileId =
            $document['file_id'];


        $file =
            tg(
                'getFile',
                [
                    'file_id' =>
                        $fileId
                ]
            );


        if (
            !isset(
                $file['result']['file_path']
            )
        ) {

            send(
                (string)$chatId,
                "❌ Telegram file download failed."
            );

            exit;
        }


        $remote =
            $file['result']['file_path'];


        $download =
            'https://api.telegram.org/file/bot'
            . $MANAGER_BOT_TOKEN
            . '/'
            . $remote;


        $source =
            file_get_contents(
                $download
            );


        if ($source === false) {

            send(
                (string)$chatId,
                "❌ Could not read uploaded file."
            );

            exit;
        }


        $id =
            projectId();


        $project =
            $PROJECTS
            . '/'
            . $id;


        mkdir(
            $project,
            0755,
            true
        );


        $sourceFile =
            $project
            . '/'
            . basename(
                $filename
            );


        file_put_contents(
            $sourceFile,
            $source
        );


        /*
         * Analyze
         */

        $token =
            detectToken(
                $source
            );


        $detectedAdmin =
            detectAdmin(
                $source
            );


        $language =
            strtolower(
                pathinfo(
                    $filename,
                    PATHINFO_EXTENSION
                )
            )
            ===
            'php'
            ? 'PHP'
            : 'PYTHON';


        /*
         * Save job
         */

        $bots =
            readJson(
                $DB
            );


        $bots[$id] = [

            'name' =>
                $filename,

            'language' =>
                $language,

            'token' =>
                $token,

            'admin_id' =>
                $detectedAdmin
                ?: $ADMIN_ID,

            'source' =>
                $sourceFile,

            'verified' =>
                false,

            'status' =>
                'ANALYZED',

            'created_at' =>
                date('c')

        ];


        writeJson(
            $DB,
            $bots
        );


        /*
         * Immediately verify if token found.
         */

        $telegram =
            verifyTelegramBot(
                $token
            );


        if (
            !$telegram['ok']
        ) {

            send(
                (string)$chatId,

                "⚠️ <b>Bot file read successfully</b>\n\n"

                . "📄 File: <code>"
                . htmlspecialchars(
                    $filename
                )
                . "</code>\n"

                . "💻 Language: <b>"
                . $language
                . "</b>\n"

                . "🔐 Token: <code>"
                . mask(
                    $token
                )
                . "</code>\n"

                . "👤 Admin ID: <code>"
                . (
                    $detectedAdmin
                    ?: $ADMIN_ID
                )
                . "</code>\n\n"

                . "❌ Telegram verification failed:\n"
                . htmlspecialchars(
                    $telegram['error']
                )
            );

            exit;
        }


        /*
         * Save real Telegram identity.
         */

        $bots[$id]['verified'] =
            true;

        $bots[$id]['telegram_id'] =
            $telegram['id'];

        $bots[$id]['username'] =
            $telegram['username'];

        $bots[$id]['bot_name'] =
            $telegram['name'];


        writeJson(
            $DB,
            $bots
        );


        /*
         * VERIFY BUTTON
         */

        send(
            (string)$chatId,

            "✅ <b>REAL BOT DETECTED</b>\n\n"

            . "🤖 Username: <b>@"
            . htmlspecialchars(
                $telegram['username']
            )
            . "</b>\n"

            . "📛 Name: <b>"
            . htmlspecialchars(
                $telegram['name']
            )
            . "</b>\n"

            . "🆔 Telegram ID: <code>"
            . $telegram['id']
            . "</code>\n"

            . "👤 Admin ID: <code>"
            . (
                $detectedAdmin
                ?: $ADMIN_ID
            )
            . "</code>\n"

            . "💻 Language: <b>"
            . $language
            . "</b>\n"

            . "🔐 Token: <code>"
            . mask(
                $token
            )
            . "</code>\n\n"

            . "Everything has been read successfully.\n"
            . "Press <b>VERIFY</b> to continue.",

            [
                'inline_keyboard' => [

                    [

                        [
                            'text' =>
                                '✅ VERIFY',
                            'callback_data' =>
                                'verify:' . $id
                        ]

                    ]

                ]
            ]
        );

        exit;
    }
}


/* =========================================================
   END
========================================================= */

http_response_code(200);

?>
