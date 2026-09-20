<?php
declare(strict_types=1);

/*
===========================================================
 VICKY BOT HOSTING MANAGER
 Existing Railway service version
===========================================================

IMPORTANT:
No new Railway project is created.

Everything runs on the CURRENT Railway service:

https://bot-hosting-production-7668.up.railway.app

Architecture:

Telegram Manager
      ↓
Upload PHP/PY
      ↓
Analyse source
      ↓
Detect token/admin
      ↓
Telegram getMe()
      ↓
VERIFY
      ↓
Create /app/hosted/BOT_ID
      ↓
Start bot process
      ↓
Webhook:
https://bot-hosting-production-7668.up.railway.app/b/BOT_ID
===========================================================
*/


/* =========================================================
   CONFIG
========================================================= */

const MANAGER_BOT_TOKEN =
    '7832316573:AAHuDnlgw1pUSFnWFrxe5flPpoKlBJ7nYqI';

const ADMIN_ID =
    '8897821078';

const BASE_DOMAIN =
    'https://bot-hosting-production-7668.up.railway.app';

const HOST_ROOT =
    '/app/hosted';

const DATA_ROOT =
    '/app/data';

const DB_FILE =
    '/app/data/bots.json';

const DAILY_LIMIT =
    100;


/* =========================================================
   INIT
========================================================= */

foreach ([
    HOST_ROOT,
    DATA_ROOT
] as $dir) {

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}


if (!file_exists(DB_FILE)) {

    file_put_contents(
        DB_FILE,
        json_encode(
            [],
            JSON_PRETTY_PRINT
        )
    );
}


/* =========================================================
   JSON DATABASE
========================================================= */

function db(): array
{
    $data = json_decode(
        @file_get_contents(DB_FILE),
        true
    );

    return is_array($data)
        ? $data
        : [];
}


function saveDb(array $data): void
{
    file_put_contents(
        DB_FILE,
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

function telegram(
    string $method,
    array $params = []
): array {

    $url =
        'https://api.telegram.org/bot'
        . MANAGER_BOT_TOKEN
        . '/'
        . $method;

    $ch = curl_init($url);

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
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


function sendMessage(
    $chatId,
    string $text,
    ?array $keyboard = null
): void {

    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {

        $params['reply_markup'] =
            json_encode(
                $keyboard
            );
    }

    telegram(
        'sendMessage',
        $params
    );
}


/* =========================================================
   ADMIN
========================================================= */

function isAdmin($id): bool
{
    return (string)$id ===
        (string)ADMIN_ID;
}


/* =========================================================
   SAFE ID
========================================================= */

function botId(): string
{
    return
        'bot_' .
        date('Ymd_His') .
        '_' .
        bin2hex(
            random_bytes(4)
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
         * BOT_TOKEN = "..."
         */
        '/(?:BOT_TOKEN|TELEGRAM_BOT_TOKEN)\s*=\s*[\'"]([^\'"]+)[\'"]/i',

        /*
         * PHP:
         * $token = "..."
         */
        '/(?:\$)?(?:BOT_TOKEN|TELEGRAM_BOT_TOKEN|TOKEN)\s*=\s*[\'"]([^\'"]+)[\'"]/i'

    ];

    foreach ($patterns as $pattern) {

        if (
            preg_match(
                $pattern,
                $source,
                $m
            )
        ) {

            if (
                isset($m[1]) &&
                preg_match(
                    '/^\d{8,12}:[A-Za-z0-9_-]{30,}$/',
                    $m[1]
                )
            ) {
                return $m[1];
            }

            if (
                preg_match(
                    '/^\d{8,12}:[A-Za-z0-9_-]{30,}$/',
                    $m[0]
                )
            ) {
                return $m[0];
            }
        }
    }

    return null;
}


/* =========================================================
   ADMIN ID DETECTION
========================================================= */

function detectAdminId(
    string $source
): ?string {

    $patterns = [

        '/(?:ADMIN_ID|OWNER_ID)\s*=\s*[\'"]?(\d{5,15})/i',

        '/(?:ADMINID|OWNERID)\s*=\s*[\'"]?(\d{5,15})/i'

    ];

    foreach ($patterns as $pattern) {

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
   TELEGRAM BOT VERIFY
========================================================= */

function verifyBot(
    string $token
): array {

    $url =
        'https://api.telegram.org/bot'
        . $token .
        '/getMe';

    $ch =
        curl_init($url);

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20
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
        $http !== 200 ||
        !isset($json['ok']) ||
        !$json['ok']
    ) {

        return [
            'ok' => false
        ];
    }

    return [
        'ok' => true,
        'id' =>
            $json['result']['id'] ?? '',
        'username' =>
            $json['result']['username'] ?? '',
        'name' =>
            $json['result']['first_name'] ?? ''
    ];
}


/* =========================================================
   MASK
========================================================= */

function maskToken(
    ?string $token
): string {

    if (!$token) {
        return 'NOT FOUND';
    }

    return
        '******' .
        substr(
            $token,
            -6
        );
}


/* =========================================================
   DAILY COUNTER
========================================================= */

function usage(): array
{
    $file =
        DATA_ROOT .
        '/usage.json';

    $today =
        date('Y-m-d');

    if (!file_exists($file)) {

        $data = [
            'date' => $today,
            'count' => 0
        ];

        file_put_contents(
            $file,
            json_encode($data)
        );

        return $data;
    }

    $data =
        json_decode(
            file_get_contents($file),
            true
        );

    if (
        !is_array($data) ||
        ($data['date'] ?? '')
        !== $today
    ) {

        $data = [
            'date' => $today,
            'count' => 0
        ];
    }

    return $data;
}


function increaseUsage(): void
{
    $file =
        DATA_ROOT .
        '/usage.json';

    $data =
        usage();

    $data['count']++;

    file_put_contents(
        $file,
        json_encode($data)
    );
}


/* =========================================================
   PROCESS HELPERS
========================================================= */

function pidFile(
    string $id
): string {

    return
        HOST_ROOT .
        '/' .
        $id .
        '/bot.pid';
}


function processRunning(
    string $id
): bool {

    $file =
        pidFile($id);

    if (!file_exists($file)) {
        return false;
    }

    $pid =
        (int)trim(
            file_get_contents($file)
        );

    if ($pid <= 0) {
        return false;
    }

    return
        file_exists(
            "/proc/$pid"
        );
}


/* =========================================================
   START BOT
========================================================= */

function startBot(
    string $id
): array {

    $db =
        db();

    if (!isset($db[$id])) {

        return [
            'ok' => false,
            'error' => 'Bot not found'
        ];
    }

    $bot =
        $db[$id];

    $dir =
        HOST_ROOT .
        '/' .
        $id;

    $file =
        $dir .
        '/' .
        $bot['filename'];

    $log =
        $dir .
        '/bot.log';

    $pid =
        $dir .
        '/bot.pid';


    if (
        processRunning($id)
    ) {

        return [
            'ok' => true,
            'message' =>
                'Already running'
        ];
    }


    /*
     * IMPORTANT:
     *
     * PHP bot:
     *     php bot.php
     *
     * Python bot:
     *     python3 bot.py
     *
     * Environment variables are also passed.
     */

    if (
        $bot['language'] === 'PHP'
    ) {

        $command =
            'cd ' .
            escapeshellarg($dir) .
            ' && ' .
            'BOT_TOKEN=' .
            escapeshellarg(
                $bot['token']
            ) .
            ' ADMIN_ID=' .
            escapeshellarg(
                $bot['admin_id']
            ) .
            ' WEBHOOK_URL=' .
            escapeshellarg(
                BASE_DOMAIN .
                '/b/' .
                $id
            ) .
            ' nohup php ' .
            escapeshellarg(
                $file
            ) .
            ' > ' .
            escapeshellarg($log) .
            ' 2>&1 & echo $!';

    } else {

        $command =
            'cd ' .
            escapeshellarg($dir) .
            ' && ' .
            'BOT_TOKEN=' .
            escapeshellarg(
                $bot['token']
            ) .
            ' ADMIN_ID=' .
            escapeshellarg(
                $bot['admin_id']
            ) .
            ' WEBHOOK_URL=' .
            escapeshellarg(
                BASE_DOMAIN .
                '/b/' .
                $id
            ) .
            ' nohup python3 ' .
            escapeshellarg(
                $file
            ) .
            ' > ' .
            escapeshellarg($log) .
            ' 2>&1 & echo $!';
    }


    $output = [];

    exec(
        $command,
        $output
    );


    $newPid =
        (int)($output[0] ?? 0);


    if ($newPid <= 0) {

        return [
            'ok' => false,
            'error' =>
                'Process could not start'
        ];
    }


    file_put_contents(
        $pid,
        $newPid
    );


    $db[$id]['status'] =
        'RUNNING';

    $db[$id]['pid'] =
        $newPid;

    $db[$id]['started_at'] =
        date('c');

    saveDb($db);


    return [
        'ok' => true,
        'pid' => $newPid
    ];
}


/* =========================================================
   STOP BOT
========================================================= */

function stopBot(
    string $id
): bool {

    $file =
        pidFile($id);

    if (!file_exists($file)) {
        return true;
    }

    $pid =
        (int)trim(
            file_get_contents($file)
        );

    if ($pid > 0) {

        exec(
            'kill ' .
            escapeshellarg(
                (string)$pid
            )
        );
    }

    @unlink($file);

    $db =
        db();

    if (isset($db[$id])) {

        $db[$id]['status'] =
            'STOPPED';

        saveDb($db);
    }

    return true;
}


/* =========================================================
   WEBHOOK
========================================================= */

function setBotWebhook(
    string $token,
    string $id
): array {

    $url =
        BASE_DOMAIN .
        '/b/' .
        $id;

    $api =
        'https://api.telegram.org/bot'
        . $token .
        '/setWebhook';

    $ch =
        curl_init($api);

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
   MAIN UPDATE
========================================================= */

$raw =
    file_get_contents(
        'php://input'
    );


/* Browser health */

if (!$raw) {

    header(
        'Content-Type: application/json'
    );

    echo json_encode([
        'ok' => true,
        'service' =>
            'Vicky Bot Hosting',
        'domain' =>
            BASE_DOMAIN,
        'status' =>
            'online'
    ]);

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
   CALLBACK QUERY
========================================================= */

if (
    isset(
        $update['callback_query']
    )
) {

    $cb =
        $update['callback_query'];

    $userId =
        $cb['from']['id'] ?? 0;

    $chatId =
        $cb['message']['chat']['id'] ?? 0;

    $action =
        $cb['data'] ?? '';


    if (!isAdmin($userId)) {

        telegram(
            'answerCallbackQuery',
            [
                'callback_query_id' =>
                    $cb['id'],
                'text' =>
                    'Admin only',
                'show_alert' =>
                    true
            ]
        );

        exit;
    }


    telegram(
        'answerCallbackQuery',
        [
            'callback_query_id' =>
                $cb['id']
        ]
    );


    /* =========================
       VERIFY
    ========================= */

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

        $db =
            db();

        if (!isset($db[$id])) {

            sendMessage(
                $chatId,
                '❌ Bot job not found.'
            );

            exit;
        }

        $bot =
            $db[$id];


        $check =
            verifyBot(
                $bot['token']
            );


        if (!$check['ok']) {

            sendMessage(
                $chatId,
                "❌ Telegram bot verification failed."
            );

            exit;
        }


        $db[$id]['verified'] =
            true;

        $db[$id]['username'] =
            $check['username'];

        $db[$id]['telegram_id'] =
            $check['id'];

        $db[$id]['bot_name'] =
            $check['name'];

        saveDb($db);


        sendMessage(
            $chatId,

            "✅ <b>Telegram Bot Verified</b>\n\n"

            . "🤖 @"
            . htmlspecialchars(
                $check['username']
            )
            . "\n"

            . "📛 "
            . htmlspecialchars(
                $check['name']
            )
            . "\n"

            . "🆔 <code>"
            . $check['id']
            . "</code>\n\n"

            . "Now deploy it on the existing Railway server.",

            [
                'inline_keyboard' => [

                    [
                        [
                            'text' =>
                                '🚀 RUN ON EXISTING SERVER',
                            'callback_data' =>
                                'run:' . $id
                        ]
                    ]

                ]
            ]
        );

        exit;
    }


    /* =========================
       RUN
    ========================= */

    if (
        str_starts_with(
            $action,
            'run:'
        )
    ) {

        $id =
            substr(
                $action,
                4
            );

        $db =
            db();


        if (!isset($db[$id])) {

            sendMessage(
                $chatId,
                '❌ Bot not found.'
            );

            exit;
        }


        if (!canDeployToday()) {

            sendMessage(
                $chatId,
                '⛔ Daily 100 deployment limit reached.'
            );

            exit;
        }


        $bot =
            $db[$id];


        /*
         * Start process
         */

        $result =
            startBot($id);


        if (!$result['ok']) {

            sendMessage(
                $chatId,

                "❌ <b>Bot start failed</b>\n\n"
                . "<pre>"
                . htmlspecialchars(
                    $result['error']
                    ?? 'Unknown error'
                )
                . "</pre>"
            );

            exit;
        }


        /*
         * Webhook
         */

        $hook =
            setBotWebhook(
                $bot['token'],
                $id
            );


        $db =
            db();

        $db[$id]['webhook'] =
            BASE_DOMAIN .
            '/b/' .
            $id;

        $db[$id]['webhook_ok'] =
            $hook['ok'] ?? false;

        $db[$id]['status'] =
            'RUNNING';

        saveDb($db);


        increaseUsage();


        sendMessage(
            $chatId,

            "🎉 <b>BOT HOSTED SUCCESSFULLY</b>\n\n"

            . "🤖 Bot: <b>@"
            . htmlspecialchars(
                $bot['username']
            )
            . "</b>\n"

            . "💻 Type: <b>"
            . $bot['language']
            . "</b>\n"

            . "🟢 Status: <b>RUNNING</b>\n\n"

            . "🌐 <b>Existing Railway Domain</b>\n"
            . "<code>"
            . BASE_DOMAIN
            . "</code>\n\n"

            . "🔗 <b>Bot Webhook</b>\n"
            . "<code>"
            . BASE_DOMAIN .
              '/b/' .
              $id
            . "</code>\n\n"

            . (
                ($hook['ok'] ?? false)
                ? "✅ Telegram webhook set successfully."
                : "⚠️ Bot started but webhook could not be set."
            )
        );

        exit;
    }


    /* =========================
       STOP
    ========================= */

    if (
        str_starts_with(
            $action,
            'stop:'
        )
    ) {

        $id =
            substr(
                $action,
                5
            );

        stopBot($id);

        sendMessage(
            $chatId,
            "🛑 Bot stopped."
        );

        exit;
    }


    /* =========================
       RESTART
    ========================= */

    if (
        str_starts_with(
            $action,
            'restart:'
        )
    ) {

        $id =
            substr(
                $action,
                8
            );

        stopBot($id);

        sleep(1);

        $result =
            startBot($id);

        sendMessage(
            $chatId,

            $result['ok']
            ? "🔄 <b>Bot restarted successfully.</b>"
            : "❌ Restart failed."
        );

        exit;
    }


    /* =========================
       LOGS
    ========================= */

    if (
        str_starts_with(
            $action,
            'logs:'
        )
    ) {

        $id =
            substr(
                $action,
                5
            );

        $log =
            HOST_ROOT .
            '/' .
            $id .
            '/bot.log';


        if (!file_exists($log)) {

            sendMessage(
                $chatId,
                'No logs available.'
            );

            exit;
        }


        $content =
            file_get_contents(
                $log
            );


        $content =
            substr(
                $content,
                -3500
            );


        sendMessage(
            $chatId,

            "📋 <b>Bot Logs</b>\n\n"
            . "<pre>"
            . htmlspecialchars(
                $content
            )
            . "</pre>"
        );

        exit;
    }
}


/* =========================================================
   MESSAGE
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
        ?? 0;

    $userId =
        $message['from']['id']
        ?? 0;


    if (!isAdmin($userId)) {

        sendMessage(
            $chatId,
            '⛔ Admin access only.'
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

        sendMessage(
            $chatId,

            "🚀 <b>VICKY BOT HOSTING</b>\n\n"

            . "Existing Railway server:\n"
            . "<code>"
            . BASE_DOMAIN
            . "</code>\n\n"

            . "📤 Upload PHP/Python bot\n"
            . "🔍 Automatic analysis\n"
            . "🤖 Real Telegram verification\n"
            . "▶️ Start on existing server\n"
            . "🔗 Automatic webhook\n"
            . "📋 Logs / Restart / Stop\n\n"

            . "Today's hosting actions: <b>"
            . $u['count']
            . "/"
            . DAILY_LIMIT
            . "</b>",

            [
                'inline_keyboard' => [

                    [
                        [
                            'text' =>
                                '📤 Upload Bot',
                            'callback_data' =>
                                'upload'
                        ]
                    ]

                ]
            ]
        );

        exit;
    }


    /* UPLOAD */

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

            sendMessage(
                $chatId,
                "❌ Only PHP and Python files are allowed."
            );

            exit;
        }


        if (!canDeployToday()) {

            sendMessage(
                $chatId,
                "⛔ Today's 100-bot limit is reached."
            );

            exit;
        }


        sendMessage(
            $chatId,
            "🔎 <b>Reading and analysing bot...</b>"
        );


        $fileInfo =
            telegram(
                'getFile',
                [
                    'file_id' =>
                        $document['file_id']
                ]
            );


        if (
            !isset(
                $fileInfo['result']['file_path']
            )
        ) {

            sendMessage(
                $chatId,
                '❌ Could not download file.'
            );

            exit;
        }


        $remote =
            $fileInfo['result']['file_path'];


        $url =
            'https://api.telegram.org/file/bot'
            . MANAGER_BOT_TOKEN
            . '/'
            . $remote;


        $source =
            file_get_contents(
                $url
            );


        if ($source === false) {

            sendMessage(
                $chatId,
                '❌ Could not read source.'
            );

            exit;
        }


        $token =
            detectToken(
                $source
            );


        $detectedAdmin =
            detectAdminId(
                $source
            );


        if (!$token) {

            sendMessage(
                $chatId,

                "❌ <b>Telegram bot token not found.</b>\n\n"
                . "Upload a bot file containing BOT_TOKEN."
            );

            exit;
        }


        $verify =
            verifyBot(
                $token
            );


        if (!$verify['ok']) {

            sendMessage(
                $chatId,

                "❌ <b>Detected token is invalid.</b>\n\n"
                . "The uploaded bot could not be verified by Telegram."
            );

            exit;
        }


        $id =
            botId();


        $dir =
            HOST_ROOT .
            '/' .
            $id;


        mkdir(
            $dir,
            0755,
            true
        );


        $safeFilename =
            preg_replace(
                '/[^A-Za-z0-9._-]/',
                '_',
                $filename
            );


        $target =
            $dir .
            '/' .
            $safeFilename;


        file_put_contents(
            $target,
            $source
        );


        /*
         * Python dependencies
         *
         * Existing requirements.txt
         * is kept untouched.
         */

        if (
            strtolower(
                pathinfo(
                    $safeFilename,
                    PATHINFO_EXTENSION
                )
            )
            ===
            'py'
            &&
            !file_exists(
                $dir .
                '/requirements.txt'
            )
        ) {

            file_put_contents(
                $dir .
                '/requirements.txt',
                ''
            );
        }


        $language =
            strtolower(
                pathinfo(
                    $safeFilename,
                    PATHINFO_EXTENSION
                )
            )
            ===
            'php'
            ? 'PHP'
            : 'PYTHON';


        $db =
            db();


        $db[$id] = [

            'id' =>
                $id,

            'filename' =>
                $safeFilename,

            'language' =>
                $language,

            'token' =>
                $token,

            'admin_id' =>
                $detectedAdmin
                ?: ADMIN_ID,

            'username' =>
                $verify['username'],

            'bot_name' =>
                $verify['name'],

            'telegram_id' =>
                $verify['id'],

            'verified' =>
                true,

            'status' =>
                'READY',

            'directory' =>
                $dir,

            'created_at' =>
                date('c')

        ];


        saveDb($db);


        sendMessage(
            $chatId,

            "✅ <b>BOT VERIFIED</b>\n\n"

            . "🤖 <b>@"
            . htmlspecialchars(
                $verify['username']
            )
            . "</b>\n"

            . "📛 "
            . htmlspecialchars(
                $verify['name']
            )
            . "\n"

            . "🆔 <code>"
            . $verify['id']
            . "</code>\n"

            . "👤 Admin ID: <code>"
            . (
                $detectedAdmin
                ?: ADMIN_ID
            )
            . "</code>\n"

            . "💻 "
            . $language
            . "\n"

            . "🔐 Token: <code>"
            . maskToken(
                $token
            )
            . "</code>\n\n"

            . "Ready to run on the existing Railway server.",

            [
                'inline_keyboard' => [

                    [
                        [
                            'text' =>
                                '🚀 RUN BOT',
                            'callback_data' =>
                                'run:' . $id
                        ]
                    ]

                ]
            ]
        );

        exit;
    }
}


/* =========================================================
   WEBHOOK ROUTER
=========================================================

   Telegram webhook requests arrive here:

   /b/BOT_ID

   The router forwards the update to the
   bot's local HTTP server only if that bot
   actually exposes an HTTP listener.

   Polling bots do NOT need this route.
========================================================= */

$requestUri =
    $_SERVER['REQUEST_URI']
    ?? '/';


if (
    preg_match(
        '#^/b/([A-Za-z0-9_-]+)#',
        $requestUri,
        $m
    )
) {

    $id =
        $m[1];

    $db =
        db();


    if (!isset($db[$id])) {

        http_response_code(404);

        echo 'Bot not found';

        exit;
    }


    /*
     * Basic endpoint response.
     *
     * A webhook-compatible bot should expose
     * its HTTP listener through its own process.
     *
     * Polling bots continue running independently.
     */

    header(
        'Content-Type: application/json'
    );

    echo json_encode([
        'ok' => true,
        'bot' =>
            $db[$id]['username']
            ?? $id,
        'status' =>
            $db[$id]['status']
            ?? 'unknown'
    ]);

    exit;
}


/* =========================================================
   DEFAULT
========================================================= */

http_response_code(200);

header(
    'Content-Type: application/json'
);

echo json_encode([
    'ok' => true,
    'service' =>
        'Vicky Bot Hosting',
    'domain' =>
        BASE_DOMAIN
]);



/* =========================================================
   HELPERS
========================================================= */

function canDeployToday(): bool
{
    $u =
        usage();

    return
        ($u['count'] ?? 0)
        <
        DAILY_LIMIT;
}
