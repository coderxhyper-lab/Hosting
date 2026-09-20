<?php

declare(strict_types=1);

/*
============================================================
 VICKY BOT HOSTING MANAGER
============================================================

Existing Railway domain:

https://bot-hosting-production-7668.up.railway.app

Features:
- Admin only
- PHP / Python upload
- Automatic token detection
- Automatic Telegram getMe verification
- Automatic start
- Existing Railway service only
- Per-bot localhost process
- Webhook proxy
- Total bots
- Running / stopped
- Start / stop / restart
- Logs
- Delete
- Webhook health
- 100 deployment actions/day

============================================================
CONFIG
============================================================
*/

const ADMIN_ID = '8897821078';

const BASE_DOMAIN =
    'https://bot-hosting-production-7668.up.railway.app';

const DAILY_LIMIT = 100;

const ROOT =
    '/app/storage';

const BOT_ROOT =
    '/app/storage/hosted';

const DATA_ROOT =
    '/app/storage/data';

const DB_FILE =
    '/app/storage/data/bots.json';

const USAGE_FILE =
    '/app/storage/data/usage.json';


/*
============================================================
MANAGER BOT TOKEN
============================================================

Railway Variables:

MANAGER_BOT_TOKEN=YOUR_NEW_MANAGER_BOT_TOKEN

Do NOT put the old exposed token here permanently.
Rotate it with BotFather first.
============================================================
*/

$MANAGER_BOT_TOKEN =
    getenv('7832316573:AAHuDnlgw1pUSFnWFrxe5flPpoKlBJ7nYqI') ?: '';


/*
============================================================
CREATE DIRECTORIES
============================================================
*/

foreach (
    [
        ROOT,
        BOT_ROOT,
        DATA_ROOT
    ] as $directory
) {

    if (
        !is_dir($directory)
    ) {

        @mkdir(
            $directory,
            0755,
            true
        );
    }
}


if (
    !file_exists(DB_FILE)
) {

    file_put_contents(
        DB_FILE,
        '{}',
        LOCK_EX
    );
}


/*
============================================================
DATABASE
============================================================
*/

function db(): array
{
    $data =
        @file_get_contents(
            DB_FILE
        );

    $json =
        json_decode(
            $data ?: '{}',
            true
        );

    return
        is_array($json)
        ? $json
        : [];
}


function saveDb(
    array $data
): void {

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


/*
============================================================
HTML ESCAPE
============================================================
*/

function h(
    $value
): string {

    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES |
        ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/*
============================================================
TELEGRAM API
============================================================
*/

function telegram(
    string $method,
    array $params = []
): array {

    global $MANAGER_BOT_TOKEN;


    if (
        $MANAGER_BOT_TOKEN === ''
    ) {

        return [
            'ok' => false,
            'description' =>
                'MANAGER_BOT_TOKEN is missing'
        ];
    }


    $url =
        'https://api.telegram.org/bot'
        .
        $MANAGER_BOT_TOKEN
        .
        '/'
        .
        $method;


    $ch =
        curl_init($url);


    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_POST =>
                true,

            CURLOPT_POSTFIELDS =>
                $params,

            CURLOPT_CONNECTTIMEOUT =>
                10,

            CURLOPT_TIMEOUT =>
                60
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


    return
        is_array($json)
        ? $json
        : [
            'ok' => false,
            'description' =>
                'Invalid Telegram response'
        ];
}


/*
============================================================
SEND MESSAGE
============================================================
*/

function sendMessage(
    $chatId,
    string $text,
    ?array $keyboard = null
): void {

    $params = [

        'chat_id' =>
            $chatId,

        'text' =>
            $text,

        'parse_mode' =>
            'HTML',

        'disable_web_page_preview' =>
            true
    ];


    if (
        $keyboard !== null
    ) {

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


/*
============================================================
EDIT MESSAGE
============================================================
*/

function editMessage(
    $chatId,
    $messageId,
    string $text,
    ?array $keyboard = null
): void {

    $params = [

        'chat_id' =>
            $chatId,

        'message_id' =>
            $messageId,

        'text' =>
            $text,

        'parse_mode' =>
            'HTML',

        'disable_web_page_preview' =>
            true
    ];


    if (
        $keyboard !== null
    ) {

        $params['reply_markup'] =
            json_encode(
                $keyboard
            );
    }


    telegram(
        'editMessageText',
        $params
    );
}


/*
============================================================
CALLBACK ANSWER
============================================================
*/

function answerCallback(
    string $id,
    string $text = ''
): void {

    telegram(
        'answerCallbackQuery',
        [
            'callback_query_id' =>
                $id,

            'text' =>
                $text
        ]
    );
}


/*
============================================================
ADMIN CHECK
============================================================
*/

function isAdmin(
    $id
): bool {

    return
        (string)$id ===
        ADMIN_ID;
}


/*
============================================================
ID
============================================================
*/

function createBotId(): string
{
    return
        'bot_' .
        date('Ymd_His') .
        '_' .
        bin2hex(
            random_bytes(5)
        );
}


/*
============================================================
PORT
============================================================
*/

function createPort(): int
{
    return random_int(
        12000,
        50000
    );
}


/*
============================================================
PROCESS CHECK
============================================================
*/

function processRunning(
    int $pid
): bool {

    return
        $pid > 0 &&
        file_exists(
            "/proc/$pid"
        );
}


/*
============================================================
TOKEN DETECTION
============================================================
*/

function detectToken(
    string $source
): ?string {

    $patterns = [

        '/\b\d{8,12}:[A-Za-z0-9_-]{30,}\b/',

        '/(?:BOT_TOKEN|TELEGRAM_BOT_TOKEN|TOKEN)'
        . '\s*=\s*[\'"]'
        . '(\d{8,12}:[A-Za-z0-9_-]{30,})'
        . '[\'"]/i'

    ];


    foreach (
        $patterns as $pattern
    ) {

        if (
            preg_match(
                $pattern,
                $source,
                $match
            )
        ) {

            if (
                isset($match[1])
            ) {

                return $match[1];
            }


            return $match[0];
        }
    }


    return null;
}


/*
============================================================
ADMIN ID DETECTION
============================================================
*/

function detectAdminId(
    string $source
): ?string {

    $patterns = [

        '/(?:ADMIN_ID|OWNER_ID)'
        . '\s*=\s*[\'"]?'
        . '(\d{5,15})/i',

        '/(?:ADMINID|OWNERID)'
        . '\s*=\s*[\'"]?'
        . '(\d{5,15})/i'

    ];


    foreach (
        $patterns as $pattern
    ) {

        if (
            preg_match(
                $pattern,
                $source,
                $match
            )
        ) {

            return $match[1];
        }
    }


    return null;
}


/*
============================================================
VERIFY TELEGRAM BOT
============================================================
*/

function verifyBot(
    string $token
): array {

    $url =
        'https://api.telegram.org/bot'
        .
        $token
        .
        '/getMe';


    $ch =
        curl_init($url);


    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_CONNECTTIMEOUT =>
                10,

            CURLOPT_TIMEOUT =>
                20
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
        !is_array($json) ||
        !($json['ok'] ?? false)
    ) {

        return [
            'ok' => false,
            'http' => $http
        ];
    }


    return [

        'ok' => true,

        'id' =>
            $json['result']['id']
            ?? '',

        'username' =>
            $json['result']['username']
            ?? '',

        'first_name' =>
            $json['result']['first_name']
            ?? ''

    ];
}


/*
============================================================
WEBHOOK SET
============================================================
*/

function setWebhook(
    string $token,
    string $botId
): array {

    $webhookUrl =
        BASE_DOMAIN .
        '/b/' .
        $botId;


    $url =
        'https://api.telegram.org/bot'
        .
        $token
        .
        '/setWebhook';


    $ch =
        curl_init($url);


    curl_setopt_array(
        $ch,
        [

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_POST =>
                true,

            CURLOPT_POSTFIELDS => [

                'url' =>
                    $webhookUrl,

                'drop_pending_updates' =>
                    'false',

                'allowed_updates' =>
                    json_encode([
                        'message',
                        'edited_message',
                        'callback_query',
                        'inline_query',
                        'chat_member',
                        'my_chat_member'
                    ])

            ],

            CURLOPT_CONNECTTIMEOUT =>
                10,

            CURLOPT_TIMEOUT =>
                30

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


    return
        is_array($json)
        ? $json
        : [
            'ok' => false
        ];
}


/*
============================================================
WEBHOOK INFO
============================================================
*/

function getWebhookInfo(
    string $token
): array {

    $url =
        'https://api.telegram.org/bot'
        .
        $token
        .
        '/getWebhookInfo';


    $ch =
        curl_init($url);


    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_CONNECTTIMEOUT =>
                10,

            CURLOPT_TIMEOUT =>
                20
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


    return
        is_array($json)
        ? $json
        : [];
}


/*
============================================================
USAGE
============================================================
*/

function usage(): array
{
    $today =
        date('Y-m-d');


    if (
        !file_exists(
            USAGE_FILE
        )
    ) {

        $data = [

            'date' =>
                $today,

            'count' =>
                0

        ];


        file_put_contents(
            USAGE_FILE,
            json_encode($data),
            LOCK_EX
        );


        return $data;
    }


    $data =
        json_decode(
            file_get_contents(
                USAGE_FILE
            ),
            true
        );


    if (
        !is_array($data) ||
        ($data['date'] ?? '')
        !==
        $today
    ) {

        $data = [

            'date' =>
                $today,

            'count' =>
                0

        ];
    }


    return $data;
}


function increaseUsage(): void
{
    $data =
        usage();


    $data['count'] =
        (int)$data['count'] + 1;


    file_put_contents(
        USAGE_FILE,
        json_encode($data),
        LOCK_EX
    );
}


/*
============================================================
START PHP BOT
============================================================
*/

function startPHPBot(
    array &$bot
): array {

    $dir =
        BOT_ROOT .
        '/' .
        $bot['id'];


    $file =
        $dir .
        '/' .
        $bot['filename'];


    if (
        !file_exists($file)
    ) {

        return [
            'ok' => false,
            'error' =>
                'Bot file not found'
        ];
    }


    $log =
        $dir .
        '/bot.log';


    $pidFile =
        $dir .
        '/bot.pid';


    $port =
        (int)(
            $bot['port']
            ?? 0
        );


    if (
        $port <= 0
    ) {

        $port =
            createPort();


        $bot['port'] =
            $port;
    }


    /*
     * Create index wrapper.
     */

    if (
        basename($file)
        !==
        'index.php'
    ) {

        file_put_contents(

            $dir .
            '/index.php',

            "<?php\n"
            .
            "require __DIR__ . '/"
            .
            basename($file)
            .
            "';\n"
        );
    }


    $webhook =
        BASE_DOMAIN .
        '/b/' .
        $bot['id'];


    $cmd =
        'cd ' .
        escapeshellarg($dir)
        .
        ' && '
        .
        'export BOT_TOKEN='
        .
        escapeshellarg(
            $bot['token']
        )
        .
        ' && '
        .
        'export ADMIN_ID='
        .
        escapeshellarg(
            $bot['admin_id']
        )
        .
        ' && '
        .
        'export WEBHOOK_URL='
        .
        escapeshellarg(
            $webhook
        )
        .
        ' && '
        .
        'export PORT='
        .
        escapeshellarg(
            (string)$port
        )
        .
        ' && '
        .
        'nohup php -S '
        .
        '127.0.0.1:'
        .
        $port
        .
        ' -t '
        .
        escapeshellarg($dir)
        .
        ' > '
        .
        escapeshellarg($log)
        .
        ' 2>&1 & echo $!';


    $output = [];


    exec(
        $cmd,
        $output
    );


    $pid =
        (int)(
            $output[0] ?? 0
        );


    if (
        $pid <= 0
    ) {

        return [
            'ok' => false,
            'error' =>
                'PHP process did not start'
        ];
    }


    file_put_contents(
        $pidFile,
        (string)$pid,
        LOCK_EX
    );


    usleep(900000);


    if (
        !processRunning($pid)
    ) {

        $error =
            file_exists($log)
            ? file_get_contents($log)
            : 'Unknown startup error';


        return [
            'ok' => false,
            'error' =>
                substr(
                    $error,
                    -3000
                )
        ];
    }


    $bot['pid'] =
        $pid;


    $bot['status'] =
        'RUNNING';


    $bot['started_at'] =
        date('c');


    return [
        'ok' => true,
        'pid' => $pid,
        'port' => $port
    ];
}


/*
============================================================
START PYTHON BOT
============================================================
*/

function startPythonBot(
    array &$bot
): array {

    $dir =
        BOT_ROOT .
        '/' .
        $bot['id'];


    $file =
        $dir .
        '/' .
        $bot['filename'];


    if (
        !file_exists($file)
    ) {

        return [
            'ok' => false,
            'error' =>
                'Python file not found'
        ];
    }


    $log =
        $dir .
        '/bot.log';


    $pidFile =
        $dir .
        '/bot.pid';


    $port =
        (int)(
            $bot['port']
            ?? 0
        );


    if (
        $port <= 0
    ) {

        $port =
            createPort();


        $bot['port'] =
            $port;
    }


    /*
     * Install declared requirements.
     */

    $requirements =
        $dir .
        '/requirements.txt';


    if (
        file_exists(
            $requirements
        )
    ) {

        exec(

            'python3 -m pip install '
            .
            '--user -r '
            .
            escapeshellarg(
                $requirements
            )
            .
            ' >/dev/null 2>&1'
        );
    }


    $webhook =
        BASE_DOMAIN .
        '/b/' .
        $bot['id'];


    $cmd =
        'cd ' .
        escapeshellarg($dir)
        .
        ' && '
        .
        'export BOT_TOKEN='
        .
        escapeshellarg(
            $bot['token']
        )
        .
        ' && '
        .
        'export ADMIN_ID='
        .
        escapeshellarg(
            $bot['admin_id']
        )
        .
        ' && '
        .
        'export WEBHOOK_URL='
        .
        escapeshellarg(
            $webhook
        )
        .
        ' && '
        .
        'export PORT='
        .
        escapeshellarg(
            (string)$port
        )
        .
        ' && '
        .
        'nohup python3 '
        .
        escapeshellarg($file)
        .
        ' > '
        .
        escapeshellarg($log)
        .
        ' 2>&1 & echo $!';


    $output = [];


    exec(
        $cmd,
        $output
    );


    $pid =
        (int)(
            $output[0] ?? 0
        );


    if (
        $pid <= 0
    ) {

        return [
            'ok' => false,
            'error' =>
                'Python process did not start'
        ];
    }


    file_put_contents(
        $pidFile,
        (string)$pid,
        LOCK_EX
    );


    usleep(900000);


    if (
        !processRunning($pid)
    ) {

        $error =
            file_exists($log)
            ? file_get_contents($log)
            : 'Unknown startup error';


        return [
            'ok' => false,
            'error' =>
                substr(
                    $error,
                    -3000
                )
        ];
    }


    $bot['pid'] =
        $pid;


    $bot['status'] =
        'RUNNING';


    $bot['started_at'] =
        date('c');


    return [
        'ok' => true,
        'pid' => $pid,
        'port' => $port
    ];
}


/*
============================================================
START BOT
============================================================
*/

function startBot(
    array &$bot
): array {

    $pid =
        (int)(
            $bot['pid']
            ?? 0
        );


    if (
        processRunning($pid)
    ) {

        $bot['status'] =
            'RUNNING';


        return [
            'ok' => true,
            'message' =>
                'Already running'
        ];
    }


    if (
        strtoupper(
            $bot['language']
        )
        ===
        'PHP'
    ) {

        return startPHPBot(
            $bot
        );
    }


    return startPythonBot(
        $bot
    );
}


/*
============================================================
STOP BOT
============================================================
*/

function stopBot(
    array &$bot
): void {

    $pid =
        (int)(
            $bot['pid']
            ?? 0
        );


    if (
        processRunning($pid)
    ) {

        exec(
            'kill ' .
            escapeshellarg(
                (string)$pid
            )
        );


        usleep(500000);


        if (
            processRunning($pid)
        ) {

            exec(
                'kill -9 ' .
                escapeshellarg(
                    (string)$pid
                )
            );
        }
    }


    @unlink(
        BOT_ROOT .
        '/' .
        $bot['id'] .
        '/bot.pid'
    );


    $bot['pid'] =
        0;


    $bot['status'] =
        'STOPPED';
}


/*
============================================================
BOT BUTTONS
============================================================
*/

function botButtons(
    string $id,
    string $status
): array {

    $rows = [];


    if (
        $status === 'RUNNING'
    ) {

        $rows[] = [

            [
                'text' =>
                    '🛑 STOP',

                'callback_data' =>
                    'stop:' . $id
            ],

            [
                'text' =>
                    '🔄 RESTART',

                'callback_data' =>
                    'restart:' . $id
            ]

        ];

    } else {

        $rows[] = [

            [
                'text' =>
                    '🚀 START',

                'callback_data' =>
                    'start:' . $id
            ]

        ];
    }


    $rows[] = [

        [
            'text' =>
                '📋 LOGS',

            'callback_data' =>
                'logs:' . $id
        ],

        [
            'text' =>
                'ℹ️ INFO',

            'callback_data' =>
                'info:' . $id
        ]

    ];


    $rows[] = [

        [
            'text' =>
                '🗑 DELETE',

            'callback_data' =>
                'delete:' . $id
        ]

    ];


    return [
        'inline_keyboard' =>
            $rows
    ];
}


/*
============================================================
DASHBOARD
============================================================
*/

function dashboard(): string
{
    $bots =
        db();


    $total =
        count($bots);


    $runningCount =
        0;


    $stoppedCount =
        0;


    foreach (
        $bots as $bot
    ) {

        $pid =
            (int)(
                $bot['pid']
                ?? 0
            );


        if (
            processRunning($pid)
        ) {

            $runningCount++;

        } else {

            $stoppedCount++;
        }
    }


    $u =
        usage();


    return

        "🚀 <b>VICKY BOT HOSTING</b>\n\n"

        .
        "🤖 Total Bots: <b>"
        .
        $total
        .
        "</b>\n"

        .
        "🟢 Running: <b>"
        .
        $runningCount
        .
        "</b>\n"

        .
        "🔴 Stopped: <b>"
        .
        $stoppedCount
        .
        "</b>\n"

        .
        "📤 Today: <b>"
        .
        $u['count']
        .
        "/"
        .
        DAILY_LIMIT
        .
        "</b>\n\n"

        .
        "🌐 Railway Domain:\n"
        .
        BASE_DOMAIN;
}


/*
============================================================
BOT INFO
============================================================
*/

function botInfo(
    array $bot
): string {

    $wh =
        BASE_DOMAIN .
        '/b/' .
        $bot['id'];


    $status =
        processRunning(
            (int)(
                $bot['pid']
                ?? 0
            )
        )
        ? '🟢 RUNNING'
        : '🔴 STOPPED';


    $webhook =
        getWebhookInfo(
            $bot['token']
        );


    $lastError =
        $webhook['result']['last_error_message']
        ?? 'None';


    $pending =
        $webhook['result']['pending_update_count']
        ?? 0;


    return

        "🤖 <b>"
        .
        h(
            $bot['username']
            ?? 'Unknown'
        )
        .
        "</b>\n\n"

        .
        "Status: "
        .
        $status
        .
        "\n"

        .
        "Language: "
        .
        h(
            $bot['language']
        )
        .
        "\n"

        .
        "Telegram ID: "
        .
        h(
            $bot['telegram_id']
            ?? ''
        )
        .
        "\n"

        .
        "Detected Admin ID: "
        .
        h(
            $bot['admin_id']
            ?? 'Not found'
        )
        .
        "\n\n"

        .
        "Webhook:\n"
        .
        h($wh)
        .
        "\n\n"

        .
        "Pending Updates: "
        .
        h($pending)
        .
        "\n"

        .
        "Last Telegram Error: "
        .
        h($lastError);
}


/*
============================================================
WEBHOOK PROXY
============================================================
*/

function handleWebhookProxy(): void
{
    $uri =
        $_SERVER['REQUEST_URI']
        ?? '';


    if (
        !preg_match(
            '#^/b/([A-Za-z0-9_-]+)(?:/.*)?$#',
            $uri,
            $match
        )
    ) {

        return;
    }


    $id =
        $match[1];


    $bots =
        db();


    if (
        !isset($bots[$id])
    ) {

        http_response_code(404);

        header(
            'Content-Type: application/json'
        );


        echo json_encode([
            'ok' => false,
            'error' =>
                'Bot not found'
        ]);


        exit;
    }


    $bot =
        $bots[$id];


    $pid =
        (int)(
            $bot['pid']
            ?? 0
        );


    if (
        !processRunning($pid)
    ) {

        http_response_code(503);

        header(
            'Content-Type: application/json'
        );


        echo json_encode([
            'ok' => false,
            'error' =>
                'Bot is not running'
        ]);


        exit;
    }


    $port =
        (int)(
            $bot['port']
            ?? 0
        );


    if (
        $port <= 0
    ) {

        http_response_code(503);

        echo 'Invalid bot port';

        exit;
    }


    $body =
        file_get_contents(
            'php://input'
        );


    $contentType =
        $_SERVER['CONTENT_TYPE']
        ??
        'application/json';


    $target =
        'http://127.0.0.1:'
        .
        $port
        .
        '/';


    $ch =
        curl_init(
            $target
        );


    curl_setopt_array(
        $ch,
        [

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_POST =>
                true,

            CURLOPT_POSTFIELDS =>
                $body,

            CURLOPT_HTTPHEADER => [

                'Content-Type: '
                .
                $contentType,

                'X-Vicky-Bot-ID: '
                .
                $id

            ],

            CURLOPT_CONNECTTIMEOUT =>
                5,

            CURLOPT_TIMEOUT =>
                30

        ]
    );


    $response =
        curl_exec($ch);


    $http =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    $error =
        curl_error($ch);


    curl_close($ch);


    if (
        $response === false
    ) {

        http_response_code(502);

        header(
            'Content-Type: application/json'
        );


        echo json_encode([
            'ok' => false,
            'error' =>
                'Internal bot unreachable',
            'details' =>
                $error
        ]);


        exit;
    }


    http_response_code(
        $http >= 100
        ? $http
        : 200
    );


    header(
        'Content-Type: application/json'
    );


    echo $response;


    exit;
}


/*
============================================================
WEBHOOK MUST RUN BEFORE TELEGRAM UPDATE HANDLER
============================================================
*/

if (
    PHP_SAPI !== 'cli'
) {

    handleWebhookProxy();
}


/*
============================================================
TELEGRAM UPDATE
============================================================
*/

$raw =
    file_get_contents(
        'php://input'
    );


if (
    !$raw
) {

    echo json_encode([
        'ok' => true,
        'service' =>
            'Vicky Bot Hosting',
        'domain' =>
            BASE_DOMAIN
    ]);


    exit;
}


$update =
    json_decode(
        $raw,
        true
    );


if (
    !is_array($update)
) {

    exit;
}


/*
============================================================
CALLBACK
============================================================
*/

if (
    isset(
        $update['callback_query']
    )
) {

    $cb =
        $update['callback_query'];


    $from =
        $cb['from']['id']
        ?? 0;


    if (
        !isAdmin($from)
    ) {

        answerCallback(
            $cb['id'],
            'Admin only'
        );

        exit;
    }


    $data =
        $cb['data']
        ?? '';


    $message =
        $cb['message']
        ?? [];


    $chatId =
        $message['chat']['id']
        ?? 0;


    $messageId =
        $message['message_id']
        ?? 0;


    if (
        !str_contains(
            $data,
            ':'
        )
    ) {

        answerCallback(
            $cb['id']
        );

        exit;
    }


    [$action,$id] =
        explode(
            ':',
            $data,
            2
        );


    $bots =
        db();


    if (
        !isset($bots[$id])
    ) {

        answerCallback(
            $cb['id'],
            'Bot not found'
        );

        exit;
    }


    $bot =
        &$bots[$id];


    /*
     * START
     */

    if (
        $action === 'start'
    ) {

        $result =
            startBot($bot);


        if (
            $result['ok']
        ) {

            $wh =
                setWebhook(
                    $bot['token'],
                    $id
                );


            $bot['webhook_ok'] =
                (bool)(
                    $wh['ok']
                    ?? false
                );


            saveDb($bots);


            answerCallback(
                $cb['id'],
                'Bot started'
            );


            editMessage(
                $chatId,
                $messageId,
                botInfo($bot),
                botButtons(
                    $id,
                    'RUNNING'
                )
            );


        } else {

            answerCallback(
                $cb['id'],
                'Start failed'
            );


            sendMessage(
                $chatId,
                "❌ <b>Start failed</b>\n\n"
                .
                h(
                    $result['error']
                    ?? 'Unknown error'
                )
            );
        }


        exit;
    }


    /*
     * STOP
     */

    if (
        $action === 'stop'
    ) {

        stopBot($bot);

        saveDb($bots);


        answerCallback(
            $cb['id'],
            'Bot stopped'
        );


        editMessage(
            $chatId,
            $messageId,
            botInfo($bot),
            botButtons(
                $id,
                'STOPPED'
            )
        );


        exit;
    }


    /*
     * RESTART
     */

    if (
        $action === 'restart'
    ) {

        stopBot($bot);

        usleep(500000);


        $result =
            startBot($bot);


        if (
            $result['ok']
        ) {

            setWebhook(
                $bot['token'],
                $id
            );


            saveDb($bots);


            answerCallback(
                $cb['id'],
                'Restarted'
            );


            editMessage(
                $chatId,
                $messageId,
                botInfo($bot),
                botButtons(
                    $id,
                    'RUNNING'
                )
            );

        } else {

            saveDb($bots);


            answerCallback(
                $cb['id'],
                'Restart failed'
            );
        }


        exit;
    }


    /*
     * INFO
     */

    if (
        $action === 'info'
    ) {

        answerCallback(
            $cb['id']
        );


        sendMessage(
            $chatId,
            botInfo($bot),
            botButtons(
                $id,
                $bot['status']
                ??
                'STOPPED'
            )
        );


        exit;
    }


    /*
     * LOGS
     */

    if (
        $action === 'logs'
    ) {

        $log =
            BOT_ROOT .
            '/' .
            $id .
            '/bot.log';


        $text =
            file_exists($log)
            ? file_get_contents($log)
            : 'No logs yet.';


        $text =
            substr(
                $text,
                -3500
            );


        answerCallback(
            $cb['id']
        );


        sendMessage(
            $chatId,
            "📋 <b>BOT LOG</b>\n\n<pre>"
            .
            h($text)
            .
            "</pre>"
        );


        exit;
    }


    /*
     * DELETE
     */

    if (
        $action === 'delete'
    ) {

        stopBot($bot);


        removeDir(
            BOT_ROOT .
            '/' .
            $id
        );


        unset(
            $bots[$id]
        );


        saveDb($bots);


        answerCallback(
            $cb['id'],
            'Deleted'
        );


        editMessage(
            $chatId,
            $messageId,
            "🗑 <b>Bot deleted successfully.</b>"
        );


        exit;
    }


    exit;
}


/*
============================================================
NORMAL MESSAGE
============================================================
*/

$message =
    $update['message']
    ?? null;


if (
    !$message
) {

    exit;
}


$chatId =
    $message['chat']['id']
    ?? 0;


$userId =
    $message['from']['id']
    ?? 0;


if (
    !isAdmin($userId)
) {

    sendMessage(
        $chatId,
        "⛔ <b>Access denied.</b>\n\n"
        .
        "This hosting manager is admin-only."
    );


    exit;
}


/*
============================================================
COMMANDS
============================================================
*/

$text =
    trim(
        $message['text']
        ?? ''
    );


if (
    $text === '/start'
    ||
    $text === '/panel'
)
{

    sendMessage(
        $chatId,
        dashboard(),
        [
            'inline_keyboard' => [

                [
                    [
                        'text' =>
                            '📊 REFRESH',
                        'callback_data' =>
                            'refresh:dashboard'
                    ]
                ],

                [
                    [
                        'text' =>
                            '🤖 MY BOTS',
                        'callback_data' =>
                            'list:dashboard'
                    ]
                ]

            ]
        ]
    );


    exit;
}


if (
    $text === '/bots'
)
{

    $bots =
        db();


    if (
        !$bots
    ) {

        sendMessage(
            $chatId,
            "🤖 <b>Total Bots: 0</b>"
        );


        exit;
    }


    $out =
        "🤖 <b>HOSTED BOTS</b>\n\n";


    foreach (
        $bots as $bot
    ) {

        $status =
            processRunning(
                (int)(
                    $bot['pid']
                    ?? 0
                )
            )
            ? '🟢'
            : '🔴';


        $out .=

            $status
            .
            " <b>"
            .
            h(
                $bot['username']
                ??
                $bot['filename']
            )
            .
            "</b>\n"
            .
            "ID: "
            .
            h(
                $bot['id']
            )
            .
            "\n"
            .
            "Webhook: "
            .
            BASE_DOMAIN .
            '/b/' .
            $bot['id']
            .
            "\n\n";
    }


    sendMessage(
        $chatId,
        $out
    );


    exit;
}


/*
============================================================
UPLOAD
============================================================
*/

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


    $extension =
        strtolower(
            pathinfo(
                $filename,
                PATHINFO_EXTENSION
            )
        );


    if (
        !in_array(
            $extension,
            ['php','py'],
            true
        )
    ) {

        sendMessage(
            $chatId,
            "❌ Sirf <b>.php</b> aur <b>.py</b> files allowed hain."
        );


        exit;
    }


    $usage =
        usage();


    if (
        $usage['count']
        >=
        DAILY_LIMIT
    ) {

        sendMessage(
            $chatId,
            "⚠️ <b>Daily limit reached.</b>\n\n"
            .
            "Limit: "
            .
            DAILY_LIMIT
        );


        exit;
    }


    /*
     * Telegram file download
     */

    $fileId =
        $document['file_id'];


    $fileInfo =
        telegram(
            'getFile',
            [
                'file_id' =>
                    $fileId
            ]
        );


    if (
        !($fileInfo['ok'] ?? false)
    ) {

        sendMessage(
            $chatId,
            "❌ Telegram file information failed."
        );


        exit;
    }


    $filePath =
        $fileInfo['result']['file_path']
        ??
        '';


    if (
        $filePath === ''
    ) {

        sendMessage(
            $chatId,
            "❌ File path unavailable."
        );


        exit;
    }


    global $MANAGER_BOT_TOKEN;


    $downloadUrl =
        'https://api.telegram.org/file/bot'
        .
        $MANAGER_BOT_TOKEN
        .
        '/'
        .
        $filePath;


    $source =
        @file_get_contents(
            $downloadUrl
        );


    if (
        $source === false
    ) {

        sendMessage(
            $chatId,
            "❌ File download failed."
        );


        exit;
    }


    /*
     * Basic size protection.
     */

    if (
        strlen($source)
        >
        20 * 1024 * 1024
    ) {

        sendMessage(
            $chatId,
            "❌ File too large for Telegram Bot API."
        );


        exit;
    }


    /*
     * Detect token.
     */

    $token =
        detectToken(
            $source
        );


    if (
        !$token
    ) {

        sendMessage(
            $chatId,
            "❌ <b>Telegram bot token not found.</b>\n\n"
            .
            "File mein valid Telegram bot token hona chahiye."
        );


        exit;
    }


    /*
     * Detect admin.
     */

    $detectedAdmin =
        detectAdminId(
            $source
        );


    if (
        !$detectedAdmin
    ) {

        $detectedAdmin =
            ADMIN_ID;
    }


    /*
     * Telegram verification.
     */

    $verify =
        verifyBot(
            $token
        );


    if (
        !($verify['ok'] ?? false)
    ) {

        sendMessage(
            $chatId,
            "❌ <b>BOT VERIFICATION FAILED</b>\n\n"
            .
            "Telegram token invalid hai."
        );


        exit;
    }


    /*
     * Create bot directory.
     */

    $id =
        createBotId();


    $dir =
        BOT_ROOT .
        '/' .
        $id;


    @mkdir(
        $dir,
        0755,
        true
    );


    file_put_contents(
        $dir .
        '/' .
        basename($filename),
        $source,
        LOCK_EX
    );


    $bots =
        db();


    $bots[$id] = [

        'id' =>
            $id,

        'filename' =>
            basename($filename),

        'language' =>
            strtoupper(
                $extension
            ),

        'token' =>
            $token,

        'admin_id' =>
            $detectedAdmin,

        'telegram_id' =>
            $verify['id']
            ??
            '',

        'username' =>
            '@' .
            (
                $verify['username']
                ??
                'unknown'
            ),

        'first_name' =>
            $verify['first_name']
            ??
            '',

        'pid' =>
            0,

        'port' =>
            createPort(),

        'status' =>
            'STOPPED',

        'created_at' =>
            date('c')

    ];


    /*
     * Instant start.
     */

    $result =
        startBot(
            $bots[$id]
        );


    if (
        !($result['ok'] ?? false)
    ) {

        $bots[$id]['status'] =
            'ERROR';


        $bots[$id]['error'] =
            $result['error']
            ??
            'Unknown error';


        saveDb($bots);


        sendMessage(
            $chatId,

            "❌ <b>BOT VERIFIED BUT START FAILED</b>\n\n"

            .
            "🤖 "
            .
            h(
                $bots[$id]['username']
            )
            .
            "\n\n"

            .
            "<b>Error:</b>\n"
            .
            h(
                $bots[$id]['error']
            )
        );


        exit;
    }


    /*
     * Automatic webhook.
     */

    $wh =
        setWebhook(
            $token,
            $id
        );


    $bots[$id]['webhook_ok'] =
        (bool)(
            $wh['ok']
            ??
            false
        );


    saveDb($bots);


    increaseUsage();


    $webhookUrl =
        BASE_DOMAIN .
        '/b/' .
        $id;


    sendMessage(
        $chatId,

        "✅ <b>BOT HOSTED SUCCESSFULLY</b>\n\n"

        .
        "🤖 Bot: <b>"
        .
        h(
            $bots[$id]['username']
        )
        .
        "</b>\n"

        .
        "🆔 Telegram ID: <code>"
        .
        h(
            $bots[$id]['telegram_id']
        )
        .
        "</code>\n"

        .
        "👤 Admin ID: <code>"
        .
        h(
            $bots[$id]['admin_id']
        )
        .
        "</code>\n"

        .
        "💻 Language: <b>"
        .
        h(
            $extension
        )
        .
        "</b>\n"

        .
        "🟢 Status: <b>RUNNING</b>\n\n"

        .
        "🌐 Railway:\n"
        .
        BASE_DOMAIN
        .
        "\n\n"

        .
        "🔗 Webhook:\n"
        .
        h(
            $webhookUrl
        )
        .
        "\n\n"

        .
        "📊 Today: "
        .
        usage()['count']
        .
        "/"
        .
        DAILY_LIMIT,

        botButtons(
            $id,
            'RUNNING'
        )
    );


    exit;
}


/*
============================================================
REFRESH / UNKNOWN
============================================================
*/

if (
    $text === '/status'
)
{

    sendMessage(
        $chatId,
        dashboard()
    );


    exit;
}


sendMessage(
    $chatId,

    "📌 <b>VICKY BOT HOSTING</b>\n\n"

    .
    "/start - Dashboard\n"
    .
    "/bots - All hosted bots\n"
    .
    "/status - Server status\n\n"

    .
    "📤 PHP/Python file upload karo."
);

?>
