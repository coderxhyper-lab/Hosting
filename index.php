<?php
declare(strict_types=1);

/*
========================================================
 VICKY BOT HOSTING MANAGER
 SINGLE FILE RAILWAY BUILD
========================================================
*/

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(0);

/* ================= CONFIG ================= */

const MANAGER_BOT_TOKEN = '7832316573:AAHuDnlgw1pUSFnWFrxe5flPpoKlBJ7nqYI';
const MANAGER_ADMIN_ID  = '8897821078';

const RAILWAY_DOMAIN = 'https://bot-hosting-production-7668.up.railway.app';

const DAILY_DEPLOY_LIMIT = 100;

/*
 * Change this if you want the /start response
 * for hosted bots.
 */
const START_OVERRIDE_MESSAGE =
    "😏 Oye, /start mil gaya! Bot use karo, drama mat karo 😂";

/* ================= PATHS ================= */

$BASE = __DIR__;

$DATA = $BASE . '/vicky_data';
$BOTS = $BASE . '/bots';
$ZIPS = $BASE . '/zip';

@mkdir($DATA, 0777, true);
@mkdir($BOTS, 0777, true);
@mkdir($ZIPS, 0777, true);

$DB_FILE = $DATA . '/bots.json';
$DEPLOY_FILE = $DATA . '/deploy_counter.json';
$ZIP_CONFIG = $DATA . '/zip_config.json';
$WEBHOOK_MARKER = $DATA . '/manager_webhook.txt';

if (!file_exists($DB_FILE)) {
    @file_put_contents($DB_FILE, '{}');
}

if (!file_exists($ZIP_CONFIG)) {
    @file_put_contents($ZIP_CONFIG, json_encode([
        'mode' => '100',
        'mb' => 100
    ], JSON_PRETTY_PRINT));
}

/* ================= HELPERS ================= */

function db(): array
{
    global $DB_FILE;

    $raw = @file_get_contents($DB_FILE);

    if (!$raw) {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function saveDb(array $data): void
{
    global $DB_FILE;

    @file_put_contents(
        $DB_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function tg(string $method, array $params = []): array
{
    $url = 'https://api.telegram.org/bot' . MANAGER_BOT_TOKEN . '/' . $method;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'ok' => false,
            'description' => $error
        ];
    }

    curl_close($ch);

    $json = json_decode($response, true);

    return is_array($json)
        ? $json
        : [
            'ok' => false,
            'description' => 'Invalid Telegram response'
        ];
}

function sendMsg(int|string $chat, string $text, ?array $keyboard = null): array
{
    $params = [
        'chat_id' => $chat,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {
        $params['reply_markup'] = json_encode($keyboard);
    }

    return tg('sendMessage', $params);
}

function editMsg(
    int|string $chat,
    int $messageId,
    string $text,
    ?array $keyboard = null
): array {
    $params = [
        'chat_id' => $chat,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {
        $params['reply_markup'] = json_encode($keyboard);
    }

    return tg('editMessageText', $params);
}

function answerCb(string $id, string $text = ''): void
{
    tg('answerCallbackQuery', [
        'callback_query_id' => $id,
        'text' => $text,
        'show_alert' => false
    ]);
}

function baseUrl(): string
{
    return rtrim(RAILWAY_DOMAIN, '/');
}

function botWebhookUrl(string $id): string
{
    return baseUrl() . '/?bot=' . rawurlencode($id);
}

function managerWebhookUrl(): string
{
    return baseUrl() . '/?manager=1';
}

function safeId(string $value): string
{
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $value);
}

function isAdmin(int|string $id): bool
{
    return (string)$id === MANAGER_ADMIN_ID;
}

/* ================= PROCESS ================= */

function pidRunning(?int $pid): bool
{
    if (!$pid || $pid < 1) {
        return false;
    }

    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    $out = [];
    @exec('ps -p ' . intval($pid) . ' -o pid=', $out);

    return !empty($out);
}

function stopProcess(?int $pid): bool
{
    if (!$pid || $pid < 1) {
        return true;
    }

    @exec('kill ' . intval($pid) . ' 2>/dev/null');

    sleep(1);

    if (pidRunning($pid)) {
        @exec('kill -9 ' . intval($pid) . ' 2>/dev/null');
    }

    return true;
}

function phpBinary(): ?string
{
    if (defined('PHP_BINARY') && PHP_BINARY) {
        return PHP_BINARY;
    }

    $paths = [
        '/usr/local/bin/php',
        '/usr/bin/php',
        '/app/.heroku/php/bin/php'
    ];

    foreach ($paths as $p) {
        if (is_executable($p)) {
            return $p;
        }
    }

    return null;
}

function pythonBinary(): ?string
{
    $out = [];

    @exec('command -v python3 2>/dev/null', $out);

    if (!empty($out[0])) {
        return trim($out[0]);
    }

    $out = [];

    @exec('command -v python 2>/dev/null', $out);

    if (!empty($out[0])) {
        return trim($out[0]);
    }

    return null;
}

/* ================= TOKEN DETECTION ================= */

function detectToken(string $code): ?string
{
    $patterns = [
        '/\b\d{8,12}:[A-Za-z0-9_-]{30,}\b/',
        '/["\'](\d{8,12}:[A-Za-z0-9_-]{30,})["\']/'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $code, $m)) {
            return $m[1] ?? $m[0];
        }
    }

    return null;
}

function detectAdminId(string $code): ?string
{
    $patterns = [
        '/(?:ADMIN_ID|ADMIN|OWNER_ID|OWNER)\s*[:=]\s*["\']?(\d{5,15})/i',
        '/(?:admin_id|owner_id)\s*[\'"]?\s*=>\s*[\'"]?(\d{5,15})/i'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $code, $m)) {
            return $m[1];
        }
    }

    return null;
}

function detectLanguage(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    return $ext === 'py' ? 'python' : 'php';
}

function verifyBotToken(string $token): ?array
{
    $url = 'https://api.telegram.org/bot' . $token . '/getMe';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);

    curl_close($ch);

    if (!$response) {
        return null;
    }

    $json = json_decode($response, true);

    if (!is_array($json) || empty($json['ok'])) {
        return null;
    }

    return $json['result'] ?? null;
}

/* ================= DEPLOY LIMIT ================= */

function deploymentAllowed(): bool
{
    global $DEPLOY_FILE;

    $today = date('Y-m-d');

    $data = [];

    if (file_exists($DEPLOY_FILE)) {
        $json = json_decode(
            @file_get_contents($DEPLOY_FILE),
            true
        );

        if (is_array($json)) {
            $data = $json;
        }
    }

    if (($data['date'] ?? '') !== $today) {
        $data = [
            'date' => $today,
            'count' => 0
        ];
    }

    return intval($data['count'] ?? 0) < DAILY_DEPLOY_LIMIT;
}

function countDeployment(): void
{
    global $DEPLOY_FILE;

    $today = date('Y-m-d');

    $data = [];

    if (file_exists($DEPLOY_FILE)) {
        $json = json_decode(
            @file_get_contents($DEPLOY_FILE),
            true
        );

        if (is_array($json)) {
            $data = $json;
        }
    }

    if (($data['date'] ?? '') !== $today) {
        $data = [
            'date' => $today,
            'count' => 0
        ];
    }

    $data['count'] = intval($data['count']) + 1;

    @file_put_contents(
        $DEPLOY_FILE,
        json_encode($data, JSON_PRETTY_PRINT)
    );
}

/* ================= WEBHOOK ================= */

function setBotWebhook(string $token, string $url): array
{
    $endpoint =
        'https://api.telegram.org/bot' .
        $token .
        '/setWebhook';

    $ch = curl_init($endpoint);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'url' => $url,
            'drop_pending_updates' => 'true'
        ],
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);

    curl_close($ch);

    $json = json_decode((string)$response, true);

    return is_array($json)
        ? $json
        : [
            'ok' => false,
            'description' => 'Webhook response invalid'
        ];
}

function deleteBotWebhook(string $token): void
{
    @tgBot($token, 'deleteWebhook');
}

function tgBot(string $token, string $method, array $params = []): array
{
    $url =
        'https://api.telegram.org/bot' .
        $token .
        '/' .
        $method;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);

    curl_close($ch);

    $json = json_decode((string)$response, true);

    return is_array($json)
        ? $json
        : ['ok' => false];
}

/* ================= MANAGER WEBHOOK ================= */

function ensureManagerWebhook(): void
{
    global $WEBHOOK_MARKER;

    $old = '';

    if (file_exists($WEBHOOK_MARKER)) {
        $old = trim((string)@file_get_contents($WEBHOOK_MARKER));
    }

    if ($old === managerWebhookUrl()) {
        return;
    }

    $url =
        'https://api.telegram.org/bot' .
        MANAGER_BOT_TOKEN .
        '/setWebhook';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'url' => managerWebhookUrl(),
            'drop_pending_updates' => 'true'
        ],
        CURLOPT_TIMEOUT => 15
    ]);

    @curl_exec($ch);

    curl_close($ch);

    @file_put_contents(
        $WEBHOOK_MARKER,
        managerWebhookUrl()
    );
}

/* ================= BOT DIRECTORY ================= */

function createBotDirectory(string $id): string
{
    global $BOTS;

    $dir = $BOTS . '/' . $id;

    @mkdir($dir, 0777, true);

    return $dir;
}

/* ================= PHP ROUTER ================= */

function createPhpRouter(
    string $dir,
    string $botFile
): string {
    $router = $dir . '/__router.php';

    $target = basename($botFile);

    $code = <<<'PHP'
<?php

/*
 * Internal webhook router.
 * Loads the uploaded Telegram bot on every HTTP request.
 */

$botFile = __DIR__ . '/__BOT_FILE__';

if (file_exists($botFile)) {
    require $botFile;
} else {
    http_response_code(404);
    echo "BOT FILE NOT FOUND";
}
PHP;

    $code = str_replace(
        '__BOT_FILE__',
        addslashes($target),
        $code
    );

    @file_put_contents($router, $code);

    return $router;
}

/* ================= START BOT ================= */

function sourceLooksPolling(string $code): bool
{
    return
        stripos($code, 'getUpdates') !== false ||
        stripos($code, 'getupdates') !== false;
}

function startPhpBot(array &$bot): array
{
    $dir = $bot['dir'];
    $file = $bot['file'];

    $php = phpBinary();

    if (!$php) {
        return [
            'ok' => false,
            'error' => 'PHP runtime not found'
        ];
    }

    $port = intval($bot['port']);

    if ($port < 10000) {
        $port = 20000 + random_int(1, 20000);
    }

    $bot['port'] = $port;

    $router = createPhpRouter($dir, $file);

    $log = $dir . '/runtime.log';

    $cmd =
        escapeshellarg($php) .
        ' -S 127.0.0.1:' .
        intval($port) .
        ' ' .
        escapeshellarg($router) .
        ' >> ' .
        escapeshellarg($log) .
        ' 2>&1 & echo $!';

    $output = [];

    @exec($cmd, $output);

    $pid = intval($output[0] ?? 0);

    if ($pid < 1) {
        return [
            'ok' => false,
            'error' => 'Unable to start PHP process'
        ];
    }

    $bot['pid'] = $pid;
    $bot['status'] = 'running';
    $bot['started_at'] = date('c');

    return [
        'ok' => true,
        'pid' => $pid,
        'port' => $port
    ];
}

function startPollingBot(array &$bot): array
{
    $dir = $bot['dir'];
    $file = $bot['file'];

    $php = phpBinary();

    if (!$php) {
        return [
            'ok' => false,
            'error' => 'PHP runtime not found'
        ];
    }

    $log = $dir . '/runtime.log';

    $cmd =
        escapeshellarg($php) .
        ' ' .
        escapeshellarg($file) .
        ' >> ' .
        escapeshellarg($log) .
        ' 2>&1 & echo $!';

    $output = [];

    @exec($cmd, $output);

    $pid = intval($output[0] ?? 0);

    if ($pid < 1) {
        return [
            'ok' => false,
            'error' => 'Unable to start polling bot'
        ];
    }

    $bot['pid'] = $pid;
    $bot['status'] = 'running';
    $bot['mode'] = 'polling';
    $bot['started_at'] = date('c');

    return [
        'ok' => true,
        'pid' => $pid
    ];
}

function startPythonBot(array &$bot): array
{
    $python = pythonBinary();

    if (!$python) {
        return [
            'ok' => false,
            'error' =>
                'Python runtime is not available in this Railway PHP environment'
        ];
    }

    $log = $bot['dir'] . '/runtime.log';

    $port = intval($bot['port']);

    if ($port < 10000) {
        $port = 30000 + random_int(1, 15000);
    }

    $bot['port'] = $port;

    $cmd =
        'PORT=' . intval($port) .
        ' BOT_TOKEN=' .
        escapeshellarg($bot['token']) .
        ' WEBHOOK_URL=' .
        escapeshellarg(botWebhookUrl($bot['id'])) .
        ' ' .
        escapeshellarg($python) .
        ' ' .
        escapeshellarg($bot['file']) .
        ' >> ' .
        escapeshellarg($log) .
        ' 2>&1 & echo $!';

    $output = [];

    @exec($cmd, $output);

    $pid = intval($output[0] ?? 0);

    if ($pid < 1) {
        return [
            'ok' => false,
            'error' => 'Unable to start Python bot'
        ];
    }

    $bot['pid'] = $pid;
    $bot['status'] = 'running';
    $bot['mode'] = 'python-http';
    $bot['started_at'] = date('c');

    return [
        'ok' => true,
        'pid' => $pid,
        'port' => $port
    ];
}

function stopBot(array &$bot): void
{
    stopProcess(
        intval($bot['pid'] ?? 0)
    );

    $bot['status'] = 'stopped';
    $bot['pid'] = 0;
    $bot['stopped_at'] = date('c');
}

/* ================= ZIP ================= */

function zipConfig(): array
{
    global $ZIP_CONFIG;

    $json = @json_decode(
        (string)@file_get_contents($ZIP_CONFIG),
        true
    );

    if (!is_array($json)) {
        return [
            'mode' => '100',
            'mb' => 100
        ];
    }

    return $json;
}

function setZipConfig(string $mode): array
{
    global $ZIP_CONFIG;

    if (strtolower($mode) === 'full') {
        $data = [
            'mode' => 'full',
            'mb' => 0
        ];
    } else {
        $mb = intval($mode);

        if ($mb < 1) {
            $mb = 100;
        }

        $data = [
            'mode' => (string)$mb,
            'mb' => $mb
        ];
    }

    @file_put_contents(
        $ZIP_CONFIG,
        json_encode($data, JSON_PRETTY_PRINT)
    );

    return $data;
}

function extractZipFile(
    string $zipFile,
    string $destination
): array {
    @mkdir($destination, 0777, true);

    /*
     * First try PHP ZipArchive.
     */

    if (class_exists('ZipArchive')) {

        $zip = new ZipArchive();

        if ($zip->open($zipFile) === true) {

            $count = $zip->numFiles;

            $ok = $zip->extractTo($destination);

            $zip->close();

            if ($ok) {

                return [
                    'ok' => true,
                    'method' => 'ZipArchive',
                    'files' => $count
                ];
            }
        }
    }

    /*
     * Fallback to system unzip.
     */

    $out = [];

    @exec(
        'unzip -oq ' .
        escapeshellarg($zipFile) .
        ' -d ' .
        escapeshellarg($destination) .
        ' 2>&1',
        $out,
        $code
    );

    if ($code === 0) {

        $files = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $destination,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files++;
            }
        }

        return [
            'ok' => true,
            'method' => 'unzip',
            'files' => $files
        ];
    }

    return [
        'ok' => false,
        'error' =>
            'ZIP extractor unavailable. ZipArchive/unzip not found.'
    ];
}

/* ================= LOG ================= */

function readLog(string $file, int $lines = 40): string
{
    if (!file_exists($file)) {
        return 'No logs available.';
    }

    $content = @file_get_contents($file);

    if (!$content) {
        return 'Log is empty.';
    }

    $arr = preg_split('/\R/', $content);

    $arr = array_slice(
        $arr,
        -$lines
    );

    return implode("\n", $arr);
}

/* ================= WEBHOOK PROXY ================= */

function proxyBotRequest(string $id): void
{
    $db = db();

    if (!isset($db[$id])) {
        http_response_code(404);
        echo 'BOT NOT FOUND';
        exit;
    }

    $bot = $db[$id];

    if (($bot['status'] ?? '') !== 'running') {
        http_response_code(503);
        echo 'BOT STOPPED';
        exit;
    }

    $port = intval($bot['port'] ?? 0);

    if ($port < 1) {
        http_response_code(503);
        echo 'BOT PORT NOT AVAILABLE';
        exit;
    }

    $body = file_get_contents('php://input');

    $target =
        'http://127.0.0.1:' .
        $port .
        '/';

    $ch = curl_init($target);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body)
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25
    ]);

    $response = curl_exec($ch);

    $httpCode = intval(
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        )
    );

    curl_close($ch);

    if ($response === false) {
        http_response_code(502);
        echo 'BOT PROCESS UNAVAILABLE';
        exit;
    }

    http_response_code(
        $httpCode > 0 ? $httpCode : 200
    );

    echo $response;
    exit;
}

/* ================= DASHBOARD ================= */

function dashboardText(): string
{
    $db = db();

    $total = count($db);

    $running = 0;
    $stopped = 0;

    foreach ($db as $bot) {

        if (
            ($bot['status'] ?? '') === 'running' &&
            pidRunning(
                intval($bot['pid'] ?? 0)
            )
        ) {
            $running++;
        } else {
            $stopped++;
        }
    }

    return
        "🤖 <b>VICKY BOT HOSTING</b>\n\n" .
        "📦 Total Bots: <b>{$total}</b>\n" .
        "🟢 Running: <b>{$running}</b>\n" .
        "🔴 Stopped: <b>{$stopped}</b>\n" .
        "🚀 Daily Limit: <b>" .
        DAILY_DEPLOY_LIMIT .
        "</b>\n\n" .
        "🌐 Railway Domain:\n" .
        "<code>" .
        htmlspecialchars(baseUrl()) .
        "</code>\n\n" .
        "⚡ Auto Verify + Auto Deploy enabled.";
}

function mainKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [
                [
                    'text' => '📊 Dashboard',
                    'callback_data' => 'dashboard'
                ]
            ],
            [
                [
                    'text' => '🤖 My Bots',
                    'callback_data' => 'bots'
                ]
            ],
            [
                [
                    'text' => '📦 ZIP Extractor',
                    'callback_data' => 'zipinfo'
                ]
            ],
            [
                [
                    'text' => '⚙️ System Health',
                    'callback_data' => 'health'
                ]
            ]
        ]
    ];
}

/* ================= BOT LIST ================= */

function botKeyboard(string $id): array
{
    return [
        'inline_keyboard' => [
            [
                [
                    'text' => '▶️ START',
                    'callback_data' => 'start:' . $id
                ],
                [
                    'text' => '⏹ STOP',
                    'callback_data' => 'stop:' . $id
                ]
            ],
            [
                [
                    'text' => '🔄 RESTART',
                    'callback_data' => 'restart:' . $id
                ],
                [
                    'text' => '📜 LOGS',
                    'callback_data' => 'logs:' . $id
                ]
            ],
            [
                [
                    'text' => 'ℹ️ INFO',
                    'callback_data' => 'info:' . $id
                ]
            ],
            [
                [
                    'text' => '🗑 DELETE',
                    'callback_data' => 'delete:' . $id
                ]
            ]
        ]
    ];
}

function botsListText(): string
{
    $db = db();

    if (!$db) {
        return "🤖 <b>My Bots</b>\n\nNo bots deployed yet.";
    }

    $text = "🤖 <b>MY BOTS</b>\n\n";

    foreach ($db as $id => $bot) {

        $running =
            ($bot['status'] ?? '') === 'running' &&
            pidRunning(
                intval($bot['pid'] ?? 0)
            );

        $status = $running
            ? '🟢 RUNNING'
            : '🔴 STOPPED';

        $name =
            $bot['username'] ??
            $bot['first_name'] ??
            'Unknown Bot';

        $text .=
            "• <b>" .
            htmlspecialchars($name) .
            "</b>\n" .
            "ID: <code>" .
            htmlspecialchars($id) .
            "</code>\n" .
            "Status: {$status}\n\n";
    }

    return $text;
}

/* ================= TELEGRAM UPDATE ================= */

function handleTelegramUpdate(array $update): void
{
    if (isset($update['callback_query'])) {

        $cb = $update['callback_query'];

        $from = $cb['from']['id'] ?? 0;

        if (!isAdmin($from)) {
            answerCb(
                $cb['id'],
                '❌ Admin only'
            );
            return;
        }

        $data = $cb['data'] ?? '';

        answerCb($cb['id']);

        handleCallback(
            $from,
            intval(
                $cb['message']['message_id'] ?? 0
            ),
            $data
        );

        return;
    }

    if (!isset($update['message'])) {
        return;
    }

    $message = $update['message'];

    $chat = $message['chat']['id'] ?? 0;
    $from = $message['from']['id'] ?? 0;

    if (!isAdmin($from)) {
        sendMsg(
            $chat,
            "❌ <b>Access Denied</b>"
        );
        return;
    }

    /*
     * ZIP batch configuration.
     */

    $text = trim(
        $message['text'] ?? ''
    );

    if (
        preg_match(
            '/^\/zip\s+(100|full)$/i',
            $text,
            $m
        )
    ) {

        $config = setZipConfig(
            strtolower($m[1]) === 'full'
                ? 'full'
                : $m[1]
        );

        sendMsg(
            $chat,
            "📦 ZIP mode updated\n\n" .
            "Mode: <b>" .
            htmlspecialchars(
                $config['mode']
            ) .
            "</b>"
        );

        return;
    }

    if ($text === '/start' || $text === '/menu') {

        sendMsg(
            $chat,
            dashboardText(),
            mainKeyboard()
        );

        return;
    }

    if ($text === '/health') {

        sendMsg(
            $chat,
            healthText()
        );

        return;
    }

    /*
     * DOCUMENT UPLOAD
     */

    if (isset($message['document'])) {

        $doc = $message['document'];

        $filename =
            $doc['file_name'] ??
            'upload';

        $ext =
            strtolower(
                pathinfo(
                    $filename,
                    PATHINFO_EXTENSION
                )
            );

        /*
         * ZIP
         */

        if ($ext === 'zip') {

            handleZipUpload(
                $chat,
                $doc
            );

            return;
        }

        /*
         * PHP / PYTHON
         */

        if (!in_array(
            $ext,
            ['php', 'py'],
            true
        )) {

            sendMsg(
                $chat,
                "❌ Only <b>.php</b>, <b>.py</b> and <b>.zip</b> files are supported."
            );

            return;
        }

        deployUploadedBot(
            $chat,
            $doc
        );

        return;
    }

    sendMsg(
        $chat,
        dashboardText(),
        mainKeyboard()
    );
}

/* ================= FILE DOWNLOAD ================= */

function downloadTelegramFile(
    string $fileId,
    string $destination
): bool {

    $result = tg(
        'getFile',
        [
            'file_id' => $fileId
        ]
    );

    if (
        empty($result['ok']) ||
        empty(
            $result['result']['file_path']
        )
    ) {
        return false;
    }

    $path =
        $result['result']['file_path'];

    $url =
        'https://api.telegram.org/file/bot' .
        MANAGER_BOT_TOKEN .
        '/' .
        $path;

    $data = @file_get_contents($url);

    if ($data === false) {
        return false;
    }

    return
        @file_put_contents(
            $destination,
            $data
        ) !== false;
}

/* ================= DEPLOY ================= */

function deployUploadedBot(
    int|string $chat,
    array $doc
): void {

    if (!deploymentAllowed()) {

        sendMsg(
            $chat,
            "❌ <b>Daily deployment limit reached.</b>\n\n" .
            "Limit: " .
            DAILY_DEPLOY_LIMIT .
            " deployments/day."
        );

        return;
    }

    $filename =
        $doc['file_name'] ??
        'bot.php';

    $ext =
        strtolower(
            pathinfo(
                $filename,
                PATHINFO_EXTENSION
            )
        );

    $temp =
        sys_get_temp_dir() .
        '/bot_' .
        bin2hex(random_bytes(6)) .
        '.' .
        $ext;

    sendMsg(
        $chat,
        "⏳ <b>Uploading & analyzing bot...</b>"
    );

    if (!downloadTelegramFile(
        $doc['file_id'],
        $temp
    )) {

        sendMsg(
            $chat,
            "❌ Telegram file download failed.\n\n" .
            "Bot API file-download size limits may apply."
        );

        return;
    }

    $code =
        @file_get_contents($temp);

    if (!$code) {

        @unlink($temp);

        sendMsg(
            $chat,
            "❌ Could not read uploaded file."
        );

        return;
    }

    $token =
        detectToken($code);

    if (!$token) {

        @unlink($temp);

        sendMsg(
            $chat,
            "❌ <b>Bot token not detected.</b>\n\n" .
            "Upload a Telegram bot source containing the bot token."
        );

        return;
    }

    sendMsg(
        $chat,
        "🔎 Token detected.\n\n" .
        "🔐 Verifying with Telegram..."
    );

    $me =
        verifyBotToken($token);

    if (!$me) {

        @unlink($temp);

        sendMsg(
            $chat,
            "❌ <b>BOT TOKEN INVALID</b>\n\n" .
            "Telegram getMe verification failed."
        );

        return;
    }

    $botId =
        'bot_' .
        date('Ymd_His') .
        '_' .
        substr(
            bin2hex(
                random_bytes(5)
            ),
            0,
            8
        );

    $dir =
        createBotDirectory(
            $botId
        );

    $safeFilename =
        preg_replace(
            '/[^a-zA-Z0-9._-]/',
            '_',
            $filename
        );

    $botFile =
        $dir .
        '/' .
        $safeFilename;

    @rename(
        $temp,
        $botFile
    );

    if (!file_exists($botFile)) {

        @copy(
            $temp,
            $botFile
        );
    }

    $adminId =
        detectAdminId($code);

    $db = db();

    $bot = [
        'id' => $botId,
        'username' =>
            isset($me['username'])
                ? '@' . $me['username']
                : '',
        'first_name' =>
            $me['first_name'] ?? '',
        'telegram_id' =>
            (string)(
                $me['id'] ?? ''
            ),
        'admin_id' =>
            $adminId,
        'token' =>
            $token,
        'token_masked' =>
            substr($token, 0, 8) .
            '••••••••',
        'language' =>
            $ext === 'py'
                ? 'Python'
                : 'PHP',
        'file' =>
            $botFile,
        'dir' =>
            $dir,
        'status' =>
            'stopped',
        'pid' =>
            0,
        'port' =>
            20000 +
            random_int(
                1,
                10000
            ),
        'created_at' =>
            date('c')
    ];

    $polling =
        sourceLooksPolling(
            $code
        );

    $bot['mode'] =
        $polling
            ? 'polling'
            : 'webhook';

    /*
     * Save first.
     */

    $db[$botId] = $bot;

    saveDb($db);

    /*
     * Start process.
     */

    $result = [];

    if ($ext === 'php') {

        if ($polling) {

            $result =
                startPollingBot(
                    $bot
                );

        } else {

            $result =
                startPhpBot(
                    $bot
                );
        }

    } else {

        $result =
            startPythonBot(
                $bot
            );
    }

    if (empty($result['ok'])) {

        $bot['status'] = 'error';
        $bot['error'] =
            $result['error'] ??
            'Unknown start error';

        $db[$botId] = $bot;

        saveDb($db);

        sendMsg(
            $chat,
            "❌ <b>BOT START FAILED</b>\n\n" .
            htmlspecialchars(
                $bot['error']
            ) .
            "\n\n" .
            "Bot: " .
            htmlspecialchars(
                $bot['username']
            )
        );

        return;
    }

    /*
     * Webhook only for webhook mode.
     */

    $webhookResult = [
        'ok' => true
    ];

    if (!$polling) {

        $webhookResult =
            setBotWebhook(
                $token,
                botWebhookUrl(
                    $botId
                )
            );

        if (empty(
            $webhookResult['ok']
        )) {

            stopBot($bot);

            $bot['status'] =
                'webhook_error';

            $bot['error'] =
                $webhookResult[
                    'description'
                ] ??
                'Webhook failed';

            $db[$botId] = $bot;

            saveDb($db);

            sendMsg(
                $chat,
                "❌ <b>Webhook setup failed</b>\n\n" .
                htmlspecialchars(
                    $bot['error']
                )
            );

            return;
        }
    }

    /*
     * Save updated runtime.
     */

    $db[$botId] = $bot;

    saveDb($db);

    countDeployment();

    $username =
        $bot['username'] ?: 'Unknown';

    $webhook =
        !$polling
            ? botWebhookUrl($botId)
            : 'Polling mode';

    $adminStatus =
        $adminId
            ? (
                (string)$adminId === MANAGER_ADMIN_ID
                    ? "✅ Manager admin ID"
                    : "Detected: " . $adminId
              )
            : "Not detected";

    sendMsg(
        $chat,
        "🎉 <b>BOT HOSTED SUCCESSFULLY</b>\n\n" .

        "🤖 Bot: <b>" .
        htmlspecialchars(
            $username
        ) .
        "</b>\n" .

        "🆔 Bot ID: <code>" .
        htmlspecialchars(
            (string)$me['id']
        ) .
        "</code>\n" .

        "👤 Admin ID: <code>" .
        htmlspecialchars(
            $adminStatus
        ) .
        "</code>\n" .

        "💻 Runtime: <b>" .
        htmlspecialchars(
            $bot['language']
        ) .
        "</b>\n" .

        "⚙️ Mode: <b>" .
        htmlspecialchars(
            $bot['mode']
        ) .
        "</b>\n" .

        "🟢 Status: <b>RUNNING</b>\n\n" .

        "🌐 Railway Domain:\n" .
        "<code>" .
        htmlspecialchars(
            baseUrl()
        ) .
        "</code>\n\n" .

        "🔗 Webhook:\n" .
        "<code>" .
        htmlspecialchars(
            $webhook
        ) .
        "</code>\n\n" .

        "🔐 Token:\n" .
        "<code>" .
        htmlspecialchars(
            $bot['token_masked']
        ) .
        "</code>",
        botKeyboard($botId)
    );
}

/* ================= ZIP UPLOAD ================= */

function handleZipUpload(
    int|string $chat,
    array $doc
): void {

    global $ZIPS;

    $config =
        zipConfig();

    $name =
        $doc['file_name'] ??
        'upload.zip';

    $zipPath =
        $ZIPS .
        '/' .
        date('Ymd_His') .
        '_' .
        preg_replace(
            '/[^a-zA-Z0-9._-]/',
            '_',
            $name
        );

    sendMsg(
        $chat,
        "📦 <b>ZIP received</b>\n\n" .
        "Mode: <b>" .
        htmlspecialchars(
            $config['mode']
        ) .
        "</b>\n\n" .
        "⏳ Downloading..."
    );

    if (!downloadTelegramFile(
        $doc['file_id'],
        $zipPath
    )) {

        sendMsg(
            $chat,
            "❌ ZIP download failed.\n\n" .
            "Telegram Bot API has file-download limits."
        );

        return;
    }

    $extractDir =
        $ZIPS .
        '/extract_' .
        date('Ymd_His') .
        '_' .
        bin2hex(
            random_bytes(3)
        );

    sendMsg(
        $chat,
        "📦 ZIP downloaded.\n\n" .
        "⏳ Extracting files..."
    );

    $result =
        extractZipFile(
            $zipPath,
            $extractDir
        );

    if (empty($result['ok'])) {

        sendMsg(
            $chat,
            "❌ <b>Extraction failed</b>\n\n" .
            htmlspecialchars(
                $result['error'] ??
                'Unknown error'
            )
        );

        return;
    }

    $php = 0;
    $py = 0;
    $other = 0;
    $totalBytes = 0;

    $iterator =
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $extractDir,
                FilesystemIterator::SKIP_DOTS
            )
        );

    foreach ($iterator as $file) {

        if (!$file->isFile()) {
            continue;
        }

        $size =
            $file->getSize();

        $totalBytes += $size;

        $ext =
            strtolower(
                $file->getExtension()
            );

        if ($ext === 'php') {
            $php++;
        } elseif ($ext === 'py') {
            $py++;
        } else {
            $other++;
        }
    }

    $mb =
        round(
            $totalBytes /
            1024 /
            1024,
            2
        );

    sendMsg(
        $chat,
        "✅ <b>ZIP EXTRACTION COMPLETE</b>\n\n" .
        "📁 Files: <b>" .
        intval(
            $result['files']
        ) .
        "</b>\n" .
        "🐘 PHP: <b>{$php}</b>\n" .
        "🐍 Python: <b>{$py}</b>\n" .
        "📄 Other: <b>{$other}</b>\n" .
        "💾 Size: <b>{$mb} MB</b>\n\n" .
        "📦 Batch mode: <b>" .
        htmlspecialchars(
            $config['mode']
        ) .
        "</b>\n\n" .
        "📍 Extracted to:\n" .
        "<code>" .
        htmlspecialchars(
            $extractDir
        ) .
        "</code>"
    );
}

/* ================= CALLBACKS ================= */

function handleCallback(
    int|string $chat,
    int $messageId,
    string $data
): void {

    if ($data === 'dashboard') {

        editMsg(
            $chat,
            $messageId,
            dashboardText(),
            mainKeyboard()
        );

        return;
    }

    if ($data === 'bots') {

        editMsg(
            $chat,
            $messageId,
            botsListText(),
            mainKeyboard()
        );

        return;
    }

    if ($data === 'zipinfo') {

        $config =
            zipConfig();

        editMsg(
            $chat,
            $messageId,
            "📦 <b>ZIP EXTRACTOR</b>\n\n" .
            "Current mode: <b>" .
            htmlspecialchars(
                $config['mode']
            ) .
            "</b>\n\n" .
            "Send:\n" .
            "<code>/zip 100</code>\n" .
            "or\n" .
            "<code>/zip full</code>\n\n" .
            "Then upload your ZIP.",
            mainKeyboard()
        );

        return;
    }

    if ($data === 'health') {

        editMsg(
            $chat,
            $messageId,
            healthText(),
            mainKeyboard()
        );

        return;
    }

    if (!str_contains($data, ':')) {
        return;
    }

    [$action, $id] =
        explode(
            ':',
            $data,
            2
        );

    $db = db();

    if (!isset($db[$id])) {

        editMsg(
            $chat,
            $messageId,
            "❌ Bot not found.",
            mainKeyboard()
        );

        return;
    }

    $bot =
        $db[$id];

    if ($action === 'start') {

        if (
            ($bot['status'] ?? '') === 'running' &&
            pidRunning(
                intval($bot['pid'] ?? 0)
            )
        ) {

            editMsg(
                $chat,
                $messageId,
                "🟢 <b>Bot is already running.</b>",
                botKeyboard($id)
            );

            return;
        }

        $code =
            @file_get_contents(
                $bot['file']
            );

        if (
            $bot['language'] === 'PHP' &&
            sourceLooksPolling(
                (string)$code
            )
        ) {
            $result =
                startPollingBot(
                    $bot
                );

            if (!empty($result['ok'])) {
                tgBot(
                    $bot['token'],
                    'deleteWebhook'
                );
            }

        } elseif (
            $bot['language'] === 'PHP'
        ) {

            $result =
                startPhpBot(
                    $bot
                );

            if (!empty($result['ok'])) {
                setBotWebhook(
                    $bot['token'],
                    botWebhookUrl($id)
                );
            }

        } else {

            $result =
                startPythonBot(
                    $bot
                );

            if (!empty($result['ok'])) {
                setBotWebhook(
                    $bot['token'],
                    botWebhookUrl($id)
                );
            }
        }

        if (empty($result['ok'])) {

            $bot['status'] = 'error';
            $bot['error'] =
                $result['error'] ??
                'Start failed';

        } else {

            $bot['status'] = 'running';
        }

        $db[$id] = $bot;

        saveDb($db);

        editMsg(
            $chat,
            $messageId,
            !empty($result['ok'])
                ? "▶️ <b>Bot started successfully.</b>\n\n" .
                  htmlspecialchars(
                      $bot['username']
                  )
                : "❌ <b>Start failed</b>\n\n" .
                  htmlspecialchars(
                      $bot['error']
                  ),
            botKeyboard($id)
        );

        return;
    }

    if ($action === 'stop') {

        stopBot($bot);

        tgBot(
            $bot['token'],
            'deleteWebhook'
        );

        $db[$id] = $bot;

        saveDb($db);

        editMsg(
            $chat,
            $messageId,
            "⏹ <b>Bot stopped.</b>",
            botKeyboard($id)
        );

        return;
    }

    if ($action === 'restart') {

        stopBot($bot);

        sleep(1);

        $code =
            @file_get_contents(
                $bot['file']
            );

        if (
            $bot['language'] === 'PHP' &&
            sourceLooksPolling(
                (string)$code
            )
        ) {

            $result =
                startPollingBot(
                    $bot
                );

            tgBot(
                $bot['token'],
                'deleteWebhook'
            );

        } elseif (
            $bot['language'] === 'PHP'
        ) {

            $result =
                startPhpBot(
                    $bot
                );

            setBotWebhook(
                $bot['token'],
                botWebhookUrl($id)
            );

        } else {

            $result =
                startPythonBot(
                    $bot
                );

            if (!empty($result['ok'])) {
                setBotWebhook(
                    $bot['token'],
                    botWebhookUrl($id)
                );
            }
        }

        if (!empty($result['ok'])) {
            $bot['status'] = 'running';
        } else {
            $bot['status'] = 'error';
        }

        $db[$id] = $bot;

        saveDb($db);

        editMsg(
            $chat,
            $messageId,
            !empty($result['ok'])
                ? "🔄 <b>Bot restarted successfully.</b>"
                : "❌ <b>Restart failed.</b>",
            botKeyboard($id)
        );

        return;
    }

    if ($action === 'logs') {

        $log =
            readLog(
                $bot['dir'] .
                '/runtime.log'
            );

        editMsg(
            $chat,
            $messageId,
            "📜 <b>BOT LOGS</b>\n\n" .
            "<pre>" .
            htmlspecialchars(
                $log
            ) .
            "</pre>",
            botKeyboard($id)
        );

        return;
    }

    if ($action === 'info') {

        $running =
            pidRunning(
                intval(
                    $bot['pid'] ?? 0
                )
            );

        editMsg(
            $chat,
            $messageId,
            "🤖 <b>BOT INFORMATION</b>\n\n" .
            "Name: <b>" .
            htmlspecialchars(
                $bot['username']
            ) .
            "</b>\n" .
            "Bot ID: <code>" .
            htmlspecialchars(
                $id
            ) .
            "</code>\n" .
            "Telegram ID: <code>" .
            htmlspecialchars(
                $bot['telegram_id']
            ) .
            "</code>\n" .
            "Language: <b>" .
            htmlspecialchars(
                $bot['language']
            ) .
            "</b>\n" .
            "Mode: <b>" .
            htmlspecialchars(
                $bot['mode']
            ) .
            "</b>\n" .
            "Process: <b>" .
            ($running
                ? 'RUNNING'
                : 'STOPPED') .
            "</b>\n" .
            "PID: <code>" .
            intval(
                $bot['pid']
            ) .
            "</code>\n" .
            "Port: <code>" .
            intval(
                $bot['port']
            ) .
            "</code>\n\n" .
            "Webhook:\n" .
            "<code>" .
            htmlspecialchars(
                botWebhookUrl($id)
            ) .
            "</code>",
            botKeyboard($id)
        );

        return;
    }

    if ($action === 'delete') {

        stopBot($bot);

        tgBot(
            $bot['token'],
            'deleteWebhook'
        );

        if (
            isset($bot['dir']) &&
            is_dir($bot['dir'])
        ) {
            deleteDirectory(
                $bot['dir']
            );
        }

        unset(
            $db[$id]
        );

        saveDb($db);

        editMsg(
            $chat,
            $messageId,
            "🗑 <b>Bot deleted successfully.</b>",
            mainKeyboard()
        );

        return;
    }
}

/* ================= DELETE DIRECTORY ================= */

function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items =
        scandir($dir);

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
            deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

/* ================= HEALTH ================= */

function healthText(): string
{
    $db = db();

    $php =
        phpBinary()
            ? '✅'
            : '❌';

    $python =
        pythonBinary()
            ? '✅'
            : '❌';

    $curl =
        function_exists('curl_init')
            ? '✅'
            : '❌';

    $zip =
        class_exists('ZipArchive')
            ? '✅'
            : '⚠️';

    $exec =
        function_exists('exec')
            ? '✅'
            : '❌';

    return
        "⚙️ <b>SYSTEM HEALTH</b>\n\n" .
        "PHP: {$php}\n" .
        "Python: {$python}\n" .
        "cURL: {$curl}\n" .
        "ZipArchive: {$zip}\n" .
        "Process Control: {$exec}\n\n" .
        "Bots: <b>" .
        count($db) .
        "</b>\n\n" .
        "🌐 Domain:\n" .
        "<code>" .
        htmlspecialchars(
            baseUrl()
        ) .
        "</code>";
}

/* ================= ROUTING ================= */

/*
 * Health endpoint
 */

if (isset($_GET['health'])) {

    header(
        'Content-Type: text/plain; charset=utf-8'
    );

    echo healthText();

    exit;
}

/*
 * Manager webhook
 */

if (
    isset($_GET['manager']) &&
    $_GET['manager'] === '1'
) {

    $raw =
        file_get_contents(
            'php://input'
        );

    $update =
        json_decode(
            (string)$raw,
            true
        );

    if (is_array($update)) {
        handleTelegramUpdate(
            $update
        );
    }

    http_response_code(200);
    echo 'OK';

    exit;
}

/*
 * Hosted bot webhook
 */

if (
    isset($_GET['bot']) &&
    $_GET['bot'] !== ''
) {

    proxyBotRequest(
        safeId(
            (string)$_GET['bot']
        )
    );

    exit;
}

/*
 * Root request.
 *
 * Also automatically attempts to configure
 * the manager webhook.
 */

ensureManagerWebhook();

/* ================= WEB DASHBOARD ================= */

header(
    'Content-Type: text/html; charset=utf-8'
);

$db = db();

$total = count($db);

$running = 0;

foreach ($db as $bot) {

    if (
        ($bot['status'] ?? '') === 'running' &&
        pidRunning(
            intval($bot['pid'] ?? 0)
        )
    ) {
        $running++;
    }
}

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Vicky Bot Hosting</title>

<style>

*{
    box-sizing:border-box;
}

body{
    margin:0;
    min-height:100vh;
    font-family:Arial,sans-serif;
    background:
        radial-gradient(
            circle at top,
            #12264c,
            #050912 55%,
            #02040a
        );
    color:#fff;
}

.wrap{
    width:min(1100px,94%);
    margin:40px auto;
}

.header{
    padding:28px;
    border:1px solid rgba(255,255,255,.1);
    border-radius:24px;
    background:rgba(255,255,255,.06);
    backdrop-filter:blur(20px);
}

h1{
    margin:0 0 8px;
}

.muted{
    color:#9ba9c2;
}

.grid{
    display:grid;
    grid-template-columns:
        repeat(auto-fit,minmax(200px,1fr));
    gap:16px;
    margin-top:20px;
}

.card{
    padding:22px;
    border-radius:20px;
    background:rgba(255,255,255,.06);
    border:1px solid rgba(255,255,255,.08);
}

.number{
    font-size:32px;
    font-weight:800;
}

.domain{
    margin-top:20px;
    padding:18px;
    border-radius:16px;
    background:#07111f;
    word-break:break-all;
}

.bot{
    margin-top:15px;
    padding:18px;
    border-radius:18px;
    background:rgba(255,255,255,.05);
}

.green{
    color:#4ade80;
}

.red{
    color:#fb7185;
}

code{
    color:#8ec5ff;
}

</style>

</head>

<body>

<div class="wrap">

<div class="header">

<h1>🤖 VICKY BOT HOSTING</h1>

<div class="muted">
Single Railway Bot Hosting Manager
</div>

<div class="grid">

<div class="card">
<div class="muted">Total Bots</div>
<div class="number">
<?=intval($total)?>
</div>
</div>

<div class="card">
<div class="muted">Running</div>
<div class="number green">
<?=intval($running)?>
</div>
</div>

<div class="card">
<div class="muted">Stopped</div>
<div class="number red">
<?=intval($total-$running)?>
</div>
</div>

<div class="card">
<div class="muted">Daily Deploy Limit</div>
<div class="number">
<?=DAILY_DEPLOY_LIMIT?>
</div>
</div>

</div>

<div class="domain">

🌐 <b>Railway Domain</b><br><br>

<code>
<?=htmlspecialchars(baseUrl())?>
</code>

</div>

</div>

<?php foreach ($db as $id => $bot): ?>

<div class="bot">

<b>
<?=htmlspecialchars(
    $bot['username'] ??
    'Unknown Bot'
)?>
</b>

<br><br>

ID:
<code>
<?=htmlspecialchars($id)?>
</code>

<br>

Status:

<?php

$isRunning =
    ($bot['status'] ?? '') === 'running' &&
    pidRunning(
        intval($bot['pid'] ?? 0)
    );

?>

<?php if ($isRunning): ?>

<span class="green">
● RUNNING
</span>

<?php else: ?>

<span class="red">
● STOPPED
</span>

<?php endif; ?>

<br><br>

Webhook:

<code>
<?=htmlspecialchars(
    botWebhookUrl($id)
)?>
</code>

</div>

<?php endforeach; ?>

</div>

</body>
</html>

<?php
/*
========================================================
END
========================================================
*/
?>
