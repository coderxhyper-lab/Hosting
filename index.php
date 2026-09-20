<?php
declare(strict_types=1);

/*
========================================================
 VICKY BOT HOSTING
 Single-file GitHub -> Railway version
========================================================
*/

const BOT_TOKEN = '7832316573:AAHuDnlgw1pUSFnWFrxe5flPpoKlBJ7nYqI';
const ADMIN_ID  = '8897821078';

const BASE_URL  = 'https://bot-hosting-production-7668.up.railway.app';

const STORAGE   = __DIR__ . '/storage';
const BOTS_DIR  = __DIR__ . '/storage/bots';
const DATA_FILE = __DIR__ . '/storage/bots.json';

const DAILY_LIMIT = 100;


/*
========================================================
 INITIALIZE
========================================================
*/

if (!is_dir(STORAGE)) {
    @mkdir(STORAGE, 0755, true);
}

if (!is_dir(BOTS_DIR)) {
    @mkdir(BOTS_DIR, 0755, true);
}

if (!file_exists(DATA_FILE)) {
    file_put_contents(DATA_FILE, '{}', LOCK_EX);
}


/*
========================================================
 DATABASE
========================================================
*/

function loadBots(): array
{
    $raw = @file_get_contents(DATA_FILE);

    if (!$raw) {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}


function saveBots(array $bots): void
{
    file_put_contents(
        DATA_FILE,
        json_encode(
            $bots,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ),
        LOCK_EX
    );
}


/*
========================================================
 HELPERS
========================================================
*/

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


function adminOnly($id): bool
{
    return (string)$id === ADMIN_ID;
}


function botId(): string
{
    return 'bot_' .
        date('Ymd_His') .
        '_' .
        bin2hex(random_bytes(4));
}


/*
========================================================
 TELEGRAM API
========================================================
*/

function tg(string $method, array $data = []): array
{
    $url =
        'https://api.telegram.org/bot' .
        BOT_TOKEN .
        '/' .
        $method;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);

    $result = curl_exec($ch);

    curl_close($ch);

    $json = json_decode(
        $result ?: '',
        true
    );

    return is_array($json)
        ? $json
        : [
            'ok' => false,
            'description' => 'Telegram API error'
        ];
}


function sendText(
    $chatId,
    string $text,
    ?array $keyboard = null
): void {

    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] =
            json_encode($keyboard);
    }

    tg('sendMessage', $data);
}


function editText(
    $chatId,
    $messageId,
    string $text,
    ?array $keyboard = null
): void {

    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] =
            json_encode($keyboard);
    }

    tg('editMessageText', $data);
}


function answerCallback(
    string $id,
    string $text = ''
): void {

    tg(
        'answerCallbackQuery',
        [
            'callback_query_id' => $id,
            'text' => $text
        ]
    );
}


/*
========================================================
 VERIFY TELEGRAM BOT
========================================================
*/

function verifyBot(string $token): array
{
    $url =
        'https://api.telegram.org/bot' .
        $token .
        '/getMe';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20
    ]);

    $result = curl_exec($ch);

    $http = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    $json = json_decode(
        $result ?: '',
        true
    );

    if (
        $http !== 200 ||
        !is_array($json) ||
        !($json['ok'] ?? false)
    ) {
        return [
            'ok' => false
        ];
    }

    return [
        'ok' => true,
        'id' => $json['result']['id'] ?? '',
        'username' =>
            $json['result']['username'] ?? '',
        'name' =>
            $json['result']['first_name'] ?? ''
    ];
}


/*
========================================================
 TOKEN DETECTION
========================================================
*/

function detectToken(string $source): ?string
{
    if (
        preg_match(
            '/\b\d{8,12}:[A-Za-z0-9_-]{30,}\b/',
            $source,
            $match
        )
    ) {
        return $match[0];
    }

    return null;
}


/*
========================================================
 ADMIN ID DETECTION
========================================================
*/

function detectAdminId(string $source): string
{
    $patterns = [
        '/(?:ADMIN_ID|OWNER_ID)\s*=\s*[\'"]?(\d{5,15})/i',
        '/(?:ADMINID|OWNERID)\s*=\s*[\'"]?(\d{5,15})/i'
    ];

    foreach ($patterns as $pattern) {

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

    return ADMIN_ID;
}


/*
========================================================
 WEBHOOK
========================================================
*/

function setBotWebhook(
    string $token,
    string $id
): array {

    $url =
        'https://api.telegram.org/bot' .
        $token .
        '/setWebhook';

    $webhook =
        BASE_URL .
        '/b/' .
        $id;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'url' => $webhook,
            'drop_pending_updates' => 'false'
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30
    ]);

    $result = curl_exec($ch);

    curl_close($ch);

    $json = json_decode(
        $result ?: '',
        true
    );

    return is_array($json)
        ? $json
        : ['ok' => false];
}


function setManagerWebhook(): array
{
    return tg(
        'setWebhook',
        [
            'url' =>
                BASE_URL .
                '/telegram',

            'drop_pending_updates' =>
                'false',

            'allowed_updates' =>
                json_encode([
                    'message',
                    'callback_query'
                ])
        ]
    );
}


/*
========================================================
 PROCESS
========================================================
*/

function alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }

    return file_exists(
        '/proc/' . $pid
    );
}


/*
========================================================
 START PHP BOT
========================================================
*/

function startPhpBot(array &$bot): array
{
    $dir =
        BOTS_DIR .
        '/' .
        $bot['id'];

    $file =
        $dir .
        '/' .
        $bot['filename'];

    if (!file_exists($file)) {
        return [
            'ok' => false,
            'error' => 'Bot file not found'
        ];
    }

    $port =
        (int)(
            $bot['port']
            ??
            random_int(12000, 50000)
        );

    $bot['port'] = $port;

    $log =
        $dir .
        '/bot.log';

    $cmd =
        'cd ' .
        escapeshellarg($dir) .
        ' && ' .
        'export BOT_TOKEN=' .
        escapeshellarg($bot['token']) .
        ' && ' .
        'export ADMIN_ID=' .
        escapeshellarg($bot['admin_id']) .
        ' && ' .
        'export PORT=' .
        $port .
        ' && ' .
        'export WEBHOOK_URL=' .
        escapeshellarg(
            BASE_URL .
            '/b/' .
            $bot['id']
        ) .
        ' && nohup php -S 127.0.0.1:' .
        $port .
        ' -t ' .
        escapeshellarg($dir) .
        ' > ' .
        escapeshellarg($log) .
        ' 2>&1 & echo $!';

    $output = [];

    exec(
        $cmd,
        $output
    );

    $pid =
        (int)(
            $output[0]
            ??
            0
        );

    if ($pid <= 0) {
        return [
            'ok' => false,
            'error' => 'PHP process could not start'
        ];
    }

    $bot['pid'] = $pid;
    $bot['status'] = 'RUNNING';

    file_put_contents(
        $dir . '/bot.pid',
        (string)$pid,
        LOCK_EX
    );

    sleep(1);

    if (!alive($pid)) {

        $logText =
            file_exists($log)
            ? file_get_contents($log)
            : 'No log available';

        return [
            'ok' => false,
            'error' => substr(
                $logText,
                -2500
            )
        ];
    }

    return [
        'ok' => true
    ];
}


/*
========================================================
 START PYTHON BOT
========================================================
*/

function startPythonBot(array &$bot): array
{
    $dir =
        BOTS_DIR .
        '/' .
        $bot['id'];

    $file =
        $dir .
        '/' .
        $bot['filename'];

    if (!file_exists($file)) {
        return [
            'ok' => false,
            'error' => 'Python file not found'
        ];
    }

    $port =
        (int)(
            $bot['port']
            ??
            random_int(12000, 50000)
        );

    $bot['port'] = $port;

    $log =
        $dir .
        '/bot.log';

    $cmd =
        'cd ' .
        escapeshellarg($dir) .
        ' && ' .
        'export BOT_TOKEN=' .
        escapeshellarg($bot['token']) .
        ' && ' .
        'export ADMIN_ID=' .
        escapeshellarg($bot['admin_id']) .
        ' && ' .
        'export PORT=' .
        $port .
        ' && ' .
        'export WEBHOOK_URL=' .
        escapeshellarg(
            BASE_URL .
            '/b/' .
            $bot['id']
        ) .
        ' && nohup python3 ' .
        escapeshellarg($file) .
        ' > ' .
        escapeshellarg($log) .
        ' 2>&1 & echo $!';

    $output = [];

    exec(
        $cmd,
        $output
    );

    $pid =
        (int)(
            $output[0]
            ??
            0
        );

    if ($pid <= 0) {
        return [
            'ok' => false,
            'error' =>
                'Python process could not start. Railway PHP runtime may not have python3.'
        ];
    }

    $bot['pid'] = $pid;
    $bot['status'] = 'RUNNING';

    file_put_contents(
        $dir . '/bot.pid',
        (string)$pid,
        LOCK_EX
    );

    sleep(1);

    if (!alive($pid)) {

        $logText =
            file_exists($log)
            ? file_get_contents($log)
            : 'No log available';

        return [
            'ok' => false,
            'error' => substr(
                $logText,
                -2500
            )
        ];
    }

    return [
        'ok' => true
    ];
}


/*
========================================================
 START
========================================================
*/

function startBot(array &$bot): array
{
    if (
        alive(
            (int)(
                $bot['pid']
                ??
                0
            )
        )
    ) {
        $bot['status'] = 'RUNNING';

        return [
            'ok' => true
        ];
    }

    if (
        strtoupper(
            $bot['language']
        ) === 'PHP'
    ) {
        return startPhpBot($bot);
    }

    return startPythonBot($bot);
}


/*
========================================================
 STOP
========================================================
*/

function stopBot(array &$bot): void
{
    $pid =
        (int)(
            $bot['pid']
            ??
            0
        );

    if (alive($pid)) {

        @exec(
            'kill ' .
            escapeshellarg(
                (string)$pid
            )
        );

        usleep(500000);

        if (alive($pid)) {
            @exec(
                'kill -9 ' .
                escapeshellarg(
                    (string)$pid
                )
            );
        }
    }

    $bot['pid'] = 0;
    $bot['status'] = 'STOPPED';
}


/*
========================================================
 DELETE DIRECTORY
========================================================
*/

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {

        if (
            $item === '.' ||
            $item === '..'
        ) {
            continue;
        }

        $path =
            $dir .
            '/' .
            $item;

        if (is_dir($path)) {
            removeDir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}


/*
========================================================
 BUTTONS
========================================================
*/

function buttons(
    string $id,
    bool $running
): array {

    return [

        'inline_keyboard' => [

            [

                [
                    'text' =>
                        $running
                        ? '🛑 STOP'
                        : '▶️ START',

                    'callback_data' =>
                        ($running
                            ? 'stop:'
                            : 'start:')
                        .
                        $id
                ],

                [
                    'text' =>
                        '🔄 RESTART',

                    'callback_data' =>
                        'restart:' .
                        $id
                ]

            ],

            [

                [
                    'text' =>
                        '📋 LOGS',

                    'callback_data' =>
                        'logs:' .
                        $id
                ],

                [
                    'text' =>
                        'ℹ️ INFO',

                    'callback_data' =>
                        'info:' .
                        $id
                ]

            ],

            [

                [
                    'text' =>
                        '🗑 DELETE',

                    'callback_data' =>
                        'delete:' .
                        $id
                ]

            ]

        ]

    ];
}


/*
========================================================
 DASHBOARD
========================================================
*/

function dashboard(): string
{
    $bots =
        loadBots();

    $total = count($bots);

    $running = 0;

    $stopped = 0;

    foreach ($bots as $bot) {

        if (
            alive(
                (int)(
                    $bot['pid']
                    ??
                    0
                )
            )
        ) {
            $running++;
        } else {
            $stopped++;
        }
    }

    return
        "🚀 <b>VICKY BOT HOSTING</b>\n\n" .

        "🤖 Total Bots: <b>" .
        $total .
        "</b>\n" .

        "🟢 Running: <b>" .
        $running .
        "</b>\n" .

        "🔴 Stopped: <b>" .
        $stopped .
        "</b>\n\n" .

        "🌐 Railway Domain:\n" .
        BASE_URL .
        "\n\n" .

        "📤 Upload a PHP/Python bot file to deploy it automatically.";
}


/*
========================================================
 BOT INFO
========================================================
*/

function botInfo(array $bot): string
{
    $running =
        alive(
            (int)(
                $bot['pid']
                ??
                0
            )
        );

    return
        "🤖 <b>" .
        h(
            $bot['username']
            ??
            'Unknown'
        ) .
        "</b>\n\n" .

        "Status: " .
        (
            $running
            ? '🟢 RUNNING'
            : '🔴 STOPPED'
        ) .
        "\n" .

        "Language: <b>" .
        h(
            $bot['language']
        ) .
        "</b>\n" .

        "Bot ID: <code>" .
        h(
            $bot['telegram_id']
            ??
            ''
        ) .
        "</code>\n" .

        "Admin ID: <code>" .
        h(
            $bot['admin_id']
            ??
            ''
        ) .
        "</code>\n\n" .

        "Webhook:\n" .
        BASE_URL .
        '/b/' .
        $bot['id'];
}


/*
========================================================
 HOSTED WEBHOOK PROXY
========================================================
*/

function proxyBot(string $id): void
{
    $bots =
        loadBots();

    if (!isset($bots[$id])) {

        http_response_code(404);

        header(
            'Content-Type: application/json'
        );

        echo json_encode([
            'ok' => false,
            'error' => 'Bot not found'
        ]);

        exit;
    }

    $bot =
        $bots[$id];

    $pid =
        (int)(
            $bot['pid']
            ??
            0
        );

    if (!alive($pid)) {

        http_response_code(503);

        header(
            'Content-Type: application/json'
        );

        echo json_encode([
            'ok' => false,
            'error' => 'Bot is stopped'
        ]);

        exit;
    }

    $port =
        (int)(
            $bot['port']
            ??
            0
        );

    if ($port <= 0) {

        http_response_code(503);

        echo json_encode([
            'ok' => false,
            'error' => 'Invalid bot port'
        ]);

        exit;
    }

    $body =
        file_get_contents(
            'php://input'
        );

    $ch =
        curl_init(
            'http://127.0.0.1:' .
            $port .
            '/'
        );

    curl_setopt_array($ch, [

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_POST => true,

        CURLOPT_POSTFIELDS => $body,

        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],

        CURLOPT_CONNECTTIMEOUT => 5,

        CURLOPT_TIMEOUT => 30

    ]);

    $result =
        curl_exec($ch);

    $http =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);

    if ($result === false) {

        http_response_code(502);

        echo json_encode([
            'ok' => false,
            'error' =>
                'Bot process unreachable'
        ]);

        exit;
    }

    http_response_code(
        $http > 0
        ? $http
        : 200
    );

    header(
        'Content-Type: application/json'
    );

    echo $result;

    exit;
}


/*
========================================================
 CALLBACK HANDLER
========================================================
*/

function handleCallback(
    array $callback
): void {

    $userId =
        $callback['from']['id']
        ??
        0;

    if (!adminOnly($userId)) {

        answerCallback(
            $callback['id'],
            'Admin only'
        );

        return;
    }

    $data =
        $callback['data']
        ??
        '';

    $parts =
        explode(
            ':',
            $data,
            2
        );

    if (count($parts) !== 2) {
        return;
    }

    $action = $parts[0];

    $id = $parts[1];

    $bots =
        loadBots();

    if (!isset($bots[$id])) {

        answerCallback(
            $callback['id'],
            'Bot not found'
        );

        return;
    }

    $bot =
        &$bots[$id];

    $chatId =
        $callback['message']['chat']['id']
        ??
        0;

    $messageId =
        $callback['message']['message_id']
        ??
        0;


    /*
     * START
     */

    if ($action === 'start') {

        $result =
            startBot($bot);

        if ($result['ok']) {

            setBotWebhook(
                $bot['token'],
                $id
            );

            saveBots($bots);

            answerCallback(
                $callback['id'],
                'Bot started'
            );

            editText(
                $chatId,
                $messageId,
                botInfo($bot),
                buttons(
                    $id,
                    true
                )
            );

        } else {

            answerCallback(
                $callback['id'],
                'Start failed'
            );

            sendText(
                $chatId,

                "❌ <b>START FAILED</b>\n\n" .
                h(
                    $result['error']
                    ??
                    'Unknown error'
                )
            );
        }

        return;
    }


    /*
     * STOP
     */

    if ($action === 'stop') {

        stopBot($bot);

        saveBots($bots);

        answerCallback(
            $callback['id'],
            'Bot stopped'
        );

        editText(
            $chatId,
            $messageId,
            botInfo($bot),
            buttons(
                $id,
                false
            )
        );

        return;
    }


    /*
     * RESTART
     */

    if ($action === 'restart') {

        stopBot($bot);

        usleep(500000);

        $result =
            startBot($bot);

        if ($result['ok']) {

            setBotWebhook(
                $bot['token'],
                $id
            );
        }

        saveBots($bots);

        answerCallback(
            $callback['id'],
            $result['ok']
                ? 'Restarted'
                : 'Restart failed'
        );

        editText(
            $chatId,
            $messageId,
            botInfo($bot),
            buttons(
                $id,
                $result['ok']
            )
        );

        return;
    }


    /*
     * INFO
     */

    if ($action === 'info') {

        $running =
            alive(
                (int)(
                    $bot['pid']
                    ??
                    0
                )
            );

        answerCallback(
            $callback['id']
        );

        sendText(
            $chatId,
            botInfo($bot),
            buttons(
                $id,
                $running
            )
        );

        return;
    }


    /*
     * LOGS
     */

    if ($action === 'logs') {

        $log =
            BOTS_DIR .
            '/' .
            $id .
            '/bot.log';

        $text =
            file_exists($log)
            ? file_get_contents($log)
            : 'No logs available.';

        $text =
            substr(
                $text,
                -3500
            );

        answerCallback(
            $callback['id']
        );

        sendText(
            $chatId,

            "📋 <b>BOT LOGS</b>\n\n" .
            "<pre>" .
            h($text) .
            "</pre>"
        );

        return;
    }


    /*
     * DELETE
     */

    if ($action === 'delete') {

        stopBot($bot);

        removeDir(
            BOTS_DIR .
            '/' .
            $id
        );

        unset(
            $bots[$id]
        );

        saveBots($bots);

        answerCallback(
            $callback['id'],
            'Bot deleted'
        );

        editText(
            $chatId,
            $messageId,
            "🗑 <b>Bot deleted successfully.</b>"
        );

        return;
    }
}


/*
========================================================
 PROCESS INCOMING REQUEST
========================================================
*/

$path =
    parse_url(
        $_SERVER['REQUEST_URI']
        ??
        '/',
        PHP_URL_PATH
    );


/*
 Hosted bot webhook
 */

if (
    is_string($path) &&
    preg_match(
        '#^/b/([A-Za-z0-9_-]+)$#',
        $path,
        $match
    )
) {

    proxyBot(
        $match[1]
    );

    exit;
}


/*
 Health
 */

if ($path === '/health') {

    header(
        'Content-Type: application/json'
    );

    echo json_encode([
        'ok' => true,
        'service' => 'Vicky Bot Hosting',
        'domain' => BASE_URL,
        'time' => date('c')
    ]);

    exit;
}


/*
 Manager Telegram webhook
 */

if ($path === '/telegram') {

    $raw =
        file_get_contents(
            'php://input'
        );

    $update =
        json_decode(
            $raw ?: '',
            true
        );

    if (!is_array($update)) {
        exit;
    }

    if (
        isset(
            $update['callback_query']
        )
    ) {

        handleCallback(
            $update['callback_query']
        );

        exit;
    }

    $message =
        $update['message']
        ??
        null;

    if (!$message) {
        exit;
    }

    $chatId =
        $message['chat']['id']
        ??
        0;

    $userId =
        $message['from']['id']
        ??
        0;

    if (!adminOnly($userId)) {

        sendText(
            $chatId,
            "⛔ <b>ACCESS DENIED</b>"
        );

        exit;
    }


    /*
     * COMMAND
     */

    $text =
        trim(
            $message['text']
            ??
            ''
        );


    /*
     * START
     */

    if (
        $text === '/start' ||
        $text === '/panel'
    ) {

        sendText(
            $chatId,
            dashboard(),
            [
                'inline_keyboard' => [
                    [
                        [
                            'text' =>
                                '🔄 REFRESH',
                            'callback_data' =>
                                'refresh:dashboard'
                        ]
                    ]
                ]
            ]
        );

        exit;
    }


    /*
     * BOTS
     */

    if ($text === '/bots') {

        $bots =
            loadBots();

        if (!$bots) {

            sendText(
                $chatId,
                "🤖 <b>Total Bots: 0</b>"
            );

            exit;
        }

        foreach ($bots as $bot) {

            $running =
                alive(
                    (int)(
                        $bot['pid']
                        ??
                        0
                    )
                );

            sendText(
                $chatId,
                botInfo($bot),
                buttons(
                    $bot['id'],
                    $running
                )
            );
        }

        exit;
    }


    /*
     * STATUS
     */

    if ($text === '/status') {

        sendText(
            $chatId,
            dashboard()
        );

        exit;
    }


    /*
     * FILE UPLOAD
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
            ??
            '';

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
                ['php', 'py'],
                true
            )
        ) {

            sendText(
                $chatId,

                "❌ <b>Invalid file</b>\n\n" .
                "Only <b>.php</b> and <b>.py</b> files are supported."
            );

            exit;
        }


        /*
         * Telegram file info
         */

        $fileInfo =
            tg(
                'getFile',
                [
                    'file_id' =>
                        $document['file_id']
                ]
            );


        if (
            !($fileInfo['ok'] ?? false)
        ) {

            sendText(
                $chatId,
                "❌ Telegram file download failed."
            );

            exit;
        }


        $filePath =
            $fileInfo['result']['file_path']
            ??
            '';


        $downloadUrl =
            'https://api.telegram.org/file/bot' .
            BOT_TOKEN .
            '/' .
            $filePath;


        $source =
            @file_get_contents(
                $downloadUrl
            );


        if ($source === false) {

            sendText(
                $chatId,
                "❌ Unable to download uploaded file."
            );

            exit;
        }


        /*
         * TOKEN
         */

        $token =
            detectToken($source);


        if (!$token) {

            sendText(
                $chatId,

                "❌ <b>BOT TOKEN NOT FOUND</b>\n\n" .
                "File ke andar valid Telegram bot token hona chahiye."
            );

            exit;
        }


        /*
         * VERIFY
         */

        $verified =
            verifyBot($token);


        if (
            !($verified['ok'] ?? false)
        ) {

            sendText(
                $chatId,

                "❌ <b>BOT VERIFICATION FAILED</b>\n\n" .
                "Detected Telegram token valid nahi hai."
            );

            exit;
        }


        /*
         * CREATE BOT
         */

        $id =
            botId();

        $dir =
            BOTS_DIR .
            '/' .
            $id;

        @mkdir(
            $dir,
            0755,
            true
        );


        $safeFilename =
            basename($filename);


        file_put_contents(
            $dir .
            '/' .
            $safeFilename,
            $source,
            LOCK_EX
        );


        $adminId =
            detectAdminId(
                $source
            );


        $bots =
            loadBots();


        $bots[$id] = [

            'id' =>
                $id,

            'filename' =>
                $safeFilename,

            'language' =>
                strtoupper(
                    $extension
                ),

            'token' =>
                $token,

            'admin_id' =>
                $adminId,

            'telegram_id' =>
                $verified['id']
                ??
                '',

            'username' =>
                '@' .
                (
                    $verified['username']
                    ??
                    'unknown'
                ),

            'name' =>
                $verified['name']
                ??
                '',

            'pid' =>
                0,

            'port' =>
                random_int(
                    12000,
                    50000
                ),

            'status' =>
                'STARTING',

            'created_at' =>
                date('c')

        ];


        /*
         * START IMMEDIATELY
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

            saveBots($bots);

            sendText(
                $chatId,

                "⚠️ <b>BOT VERIFIED</b>\n\n" .

                "🤖 " .
                h(
                    $bots[$id]['username']
                ) .
                "\n\n" .

                "❌ <b>START FAILED</b>\n\n" .

                "<pre>" .
                h(
                    $bots[$id]['error']
                ) .
                "</pre>"
            );

            exit;
        }


        /*
         * WEBHOOK
         */

        $webhook =
            setBotWebhook(
                $token,
                $id
            );


        $bots[$id]['webhook'] =
            BASE_URL .
            '/b/' .
            $id;

        $bots[$id]['webhook_ok'] =
            (bool)(
                $webhook['ok']
                ??
                false
            );

        $bots[$id]['status'] =
            'RUNNING';


        saveBots($bots);


        /*
         * SUCCESS
         */

        sendText(
            $chatId,

            "🎉 <b>BOT HOSTED SUCCESSFULLY</b>\n\n" .

            "🤖 Bot: <b>" .
            h(
                $bots[$id]['username']
            ) .
            "</b>\n" .

            "🆔 Telegram ID: <code>" .
            h(
                $bots[$id]['telegram_id']
            ) .
            "</code>\n" .

            "👤 Admin ID: <code>" .
            h(
                $bots[$id]['admin_id']
            ) .
            "</code>\n" .

            "💻 Type: <b>" .
            h($extension) .
            "</b>\n" .

            "🟢 Status: <b>RUNNING</b>\n\n" .

            "🔗 Webhook:\n" .
            BASE_URL .
            '/b/' .
            $id
        );

        exit;
    }


    /*
     * DEFAULT
     */

    sendText(
        $chatId,

        "🚀 <b>VICKY BOT HOSTING</b>\n\n" .

        "/start — Dashboard\n" .
        "/bots — Hosted bots\n" .
        "/status — Server status\n\n" .

        "📤 PHP/Python Telegram bot file upload karo."
    );

    exit;
}


/*
========================================================
 ROOT PAGE
========================================================
*/

header(
    'Content-Type: text/html; charset=utf-8'
);

echo '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vicky Bot Hosting</title>
<style>
body{
    background:#07111f;
    color:#fff;
    font-family:Arial,sans-serif;
    display:flex;
    align-items:center;
    justify-content:center;
    min-height:100vh;
    margin:0;
}
.card{
    padding:35px;
    border:1px solid #24344a;
    border-radius:20px;
    background:#0d1928;
    text-align:center;
    max-width:500px;
}
.ok{
    color:#45e68a;
    font-size:20px;
}
small{
    color:#9aa8b8;
}
</style>
</head>
<body>
<div class="card">
<div class="ok">● ONLINE</div>
<h1>VICKY BOT HOSTING</h1>
<p>Telegram Bot Hosting Manager</p>
<small>' .
h(BASE_URL) .
'</small>
</div>
</body>
</html>';
