<?php
declare(strict_types=1);

/*
===========================================================
 VICKY BOT HOSTING
 SINGLE FILE RAILWAY VERSION
 PHP WEBHOOK BOTS
===========================================================
*/

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(60);

/* =========================
   CONFIG
========================= */

const ADMIN_ID = '8897821078';

const MANAGER_TOKEN =
'7832316573:AAHuDnlgw1pUSFnWFrxe5flPpoKlBJ7nqYI';

const RAILWAY_URL =
'https://bot-hosting-production-7668.up.railway.app';

/* =========================
   STORAGE
========================= */

$ROOT = __DIR__;

$DATA = $ROOT . '/data';
$BOT_DIR = $ROOT . '/hosted_bots';
$ZIP_DIR = $ROOT . '/zip_files';

if (!is_dir($DATA)) {
    @mkdir($DATA, 0777, true);
}

if (!is_dir($BOT_DIR)) {
    @mkdir($BOT_DIR, 0777, true);
}

if (!is_dir($ZIP_DIR)) {
    @mkdir($ZIP_DIR, 0777, true);
}

$DB = $DATA . '/bots.json';

if (!file_exists($DB)) {
    @file_put_contents($DB, '{}');
}

/* =========================
   DATABASE
========================= */

function getBots(): array
{
    global $DB;

    $x = @file_get_contents($DB);

    if (!$x) {
        return [];
    }

    $data = json_decode($x, true);

    return is_array($data) ? $data : [];
}

function saveBots(array $data): void
{
    global $DB;

    @file_put_contents(
        $DB,
        json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES
        ),
        LOCK_EX
    );
}

/* =========================
   TELEGRAM API
========================= */

function telegram(
    string $token,
    string $method,
    array $params = []
): array {

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
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);

    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'description' => 'cURL request failed'
        ];
    }

    $json = json_decode(
        $response,
        true
    );

    return is_array($json)
        ? $json
        : [
            'ok' => false,
            'description' => 'Invalid Telegram response'
        ];
}

/* =========================
   MESSAGE
========================= */

function sendMessage(
    int|string $chat,
    string $text,
    ?array $keyboard = null
): void {

    $params = [
        'chat_id' => $chat,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {
        $params['reply_markup'] =
            json_encode($keyboard);
    }

    telegram(
        MANAGER_TOKEN,
        'sendMessage',
        $params
    );
}

/* =========================
   TOKEN CHECK
========================= */

function findBotToken(string $code): ?string
{
    if (
        preg_match(
            '/\b\d{8,12}:[A-Za-z0-9_-]{30,}\b/',
            $code,
            $m
        )
    ) {
        return $m[0];
    }

    return null;
}

function verifyBot(string $token): ?array
{
    $result = telegram(
        $token,
        'getMe'
    );

    if (
        empty($result['ok']) ||
        empty($result['result'])
    ) {
        return null;
    }

    return $result['result'];
}

/* =========================
   WEBHOOK
========================= */

function botWebhook(string $id): string
{
    return
        rtrim(RAILWAY_URL, '/') .
        '/?bot=' .
        urlencode($id);
}

function setBotWebhook(
    string $token,
    string $id
): array {

    return telegram(
        $token,
        'setWebhook',
        [
            'url' => botWebhook($id),
            'drop_pending_updates' => true,
            'allowed_updates' => json_encode([
                'message',
                'edited_message',
                'callback_query',
                'inline_query',
                'chat_member',
                'my_chat_member'
            ])
        ]
    );
}

/* =========================
   MANAGER WEBHOOK
========================= */

function setManagerWebhook(): array
{
    return telegram(
        MANAGER_TOKEN,
        'setWebhook',
        [
            'url' =>
                rtrim(RAILWAY_URL, '/') .
                '/?manager=1',
            'drop_pending_updates' => true
        ]
    );
}

/* =========================
   DOWNLOAD TELEGRAM FILE
========================= */

function downloadTelegramFile(
    string $fileId,
    string $destination
): bool {

    $result = telegram(
        MANAGER_TOKEN,
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

    $filePath =
        $result['result']['file_path'];

    $url =
        'https://api.telegram.org/file/bot' .
        MANAGER_TOKEN .
        '/' .
        $filePath;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true
    ]);

    $data = curl_exec($ch);

    curl_close($ch);

    if ($data === false) {
        return false;
    }

    return
        @file_put_contents(
            $destination,
            $data
        ) !== false;
}

/* =========================
   SAFE ID
========================= */

function safeId(string $id): string
{
    return preg_replace(
        '/[^a-zA-Z0-9_-]/',
        '',
        $id
    );
}

/* =========================
   POLLING DETECTION
========================= */

function isPollingBot(string $code): bool
{
    return
        stripos(
            $code,
            'getUpdates'
        ) !== false;
}

/* =========================
   BOT DEPLOY
========================= */

function deployBot(
    int|string $chat,
    array $document
): void {

    $filename =
        $document['file_name'] ??
        'bot.php';

    $extension =
        strtolower(
            pathinfo(
                $filename,
                PATHINFO_EXTENSION
            )
        );

    if ($extension !== 'php') {

        sendMessage(
            $chat,
            "❌ <b>Only PHP bots are supported in this one-file Railway version.</b>\n\n" .
            "Python bots require a Python runtime."
        );

        return;
    }

    /*
     * Telegram Bot API normal file download limit.
     */

    $size =
        intval(
            $document['file_size'] ?? 0
        );

    if ($size > 20 * 1024 * 1024) {

        sendMessage(
            $chat,
            "❌ File is larger than Telegram Bot API's normal 20 MB download limit."
        );

        return;
    }

    sendMessage(
        $chat,
        "⏳ <b>Bot received.</b>\n\n" .
        "🔎 Reading source...\n" .
        "🔐 Detecting token..."
    );

    $temporary =
        sys_get_temp_dir() .
        '/vicky_' .
        bin2hex(
            random_bytes(8)
        ) .
        '.php';

    if (
        !downloadTelegramFile(
            $document['file_id'],
            $temporary
        )
    ) {

        sendMessage(
            $chat,
            "❌ <b>File download failed.</b>"
        );

        return;
    }

    $source =
        @file_get_contents(
            $temporary
        );

    if (!$source) {

        @unlink($temporary);

        sendMessage(
            $chat,
            "❌ <b>Could not read PHP file.</b>"
        );

        return;
    }

    /*
     * This architecture is for webhook PHP bots.
     */

    if (isPollingBot($source)) {

        @unlink($temporary);

        sendMessage(
            $chat,
            "⚠️ <b>This PHP bot uses getUpdates polling.</b>\n\n" .
            "This single-file Railway version runs PHP bots through Telegram Webhooks.\n\n" .
            "Please convert the bot to webhook mode."
        );

        return;
    }

    $token =
        findBotToken($source);

    if (!$token) {

        @unlink($temporary);

        sendMessage(
            $chat,
            "❌ <b>Telegram bot token not found.</b>\n\n" .
            "Make sure the PHP file contains the bot token."
        );

        return;
    }

    sendMessage(
        $chat,
        "🔐 <b>Token detected.</b>\n\n" .
        "Checking token with Telegram..."
    );

    $me =
        verifyBot($token);

    if (!$me) {

        @unlink($temporary);

        sendMessage(
            $chat,
            "❌ <b>BOT TOKEN INVALID</b>\n\n" .
            "Telegram getMe verification failed."
        );

        return;
    }

    /*
     * Create unique ID.
     */

    $id =
        'bot_' .
        date('Ymd_His') .
        '_' .
        substr(
            bin2hex(
                random_bytes(6)
            ),
            0,
            8
        );

    global $BOT_DIR;

    $directory =
        $BOT_DIR .
        '/' .
        $id;

    @mkdir(
        $directory,
        0777,
        true
    );

    /*
     * Keep original filename.
     */

    $safeFilename =
        preg_replace(
            '/[^a-zA-Z0-9._-]/',
            '_',
            $filename
        );

    $botFile =
        $directory .
        '/' .
        $safeFilename;

    if (
        !@rename(
            $temporary,
            $botFile
        )
    ) {

        @copy(
            $temporary,
            $botFile
        );

        @unlink($temporary);
    }

    /*
     * Save bot.
     */

    $bots =
        getBots();

    $bots[$id] = [
        'id' => $id,
        'file' => $botFile,
        'directory' => $directory,
        'token' => $token,
        'username' =>
            !empty($me['username'])
                ? '@' . $me['username']
                : '',
        'telegram_id' =>
            (string)(
                $me['id'] ?? ''
            ),
        'first_name' =>
            $me['first_name'] ?? '',
        'created' =>
            date('c'),
        'status' =>
            'running'
    ];

    saveBots($bots);

    /*
     * Set webhook.
     */

    sendMessage(
        $chat,
        "✅ <b>BOT VERIFIED</b>\n\n" .
        "🤖 " .
        htmlspecialchars(
            $bots[$id]['username'] ?: 'Unknown'
        ) .
        "\n" .
        "🆔 " .
        htmlspecialchars(
            $bots[$id]['telegram_id']
        ) .
        "\n\n" .
        "🌐 Setting webhook..."
    );

    $webhook =
        setBotWebhook(
            $token,
            $id
        );

    if (empty($webhook['ok'])) {

        unset(
            $bots[$id]
        );

        saveBots($bots);

        deleteFolder(
            $directory
        );

        sendMessage(
            $chat,
            "❌ <b>Webhook setup failed.</b>\n\n" .
            htmlspecialchars(
                $webhook['description'] ??
                'Unknown Telegram error'
            )
        );

        return;
    }

    sendMessage(
        $chat,
        "🎉 <b>BOT HOSTED SUCCESSFULLY</b>\n\n" .

        "🤖 Bot: <b>" .
        htmlspecialchars(
            $bots[$id]['username'] ?: 'Unknown'
        ) .
        "</b>\n" .

        "🆔 Telegram ID: <code>" .
        htmlspecialchars(
            $bots[$id]['telegram_id']
        ) .
        "</code>\n" .

        "🟢 Status: <b>RUNNING</b>\n\n" .

        "🌐 Railway:\n" .
        "<code>" .
        htmlspecialchars(
            RAILWAY_URL
        ) .
        "</code>\n\n" .

        "🔗 Webhook:\n" .
        "<code>" .
        htmlspecialchars(
            botWebhook($id)
        ) .
        "</code>"
    );
}

/* =========================
   DELETE DIRECTORY
========================= */

function deleteFolder(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items =
        @scandir($directory);

    if (!$items) {
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
            $directory .
            '/' .
            $item;

        if (is_dir($path)) {
            deleteFolder($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

/* =========================
   EXECUTE HOSTED PHP BOT
========================= */

function executeHostedBot(
    string $id
): void {

    $id =
        safeId($id);

    $bots =
        getBots();

    if (!isset($bots[$id])) {

        http_response_code(404);

        echo 'BOT NOT FOUND';

        return;
    }

    $bot =
        $bots[$id];

    $file =
        $bot['file'] ?? '';

    if (
        !$file ||
        !file_exists($file)
    ) {

        http_response_code(500);

        echo 'BOT FILE NOT FOUND';

        return;
    }

    /*
     * Bot receives the original Telegram
     * webhook body through php://input.
     */

    $_SERVER['BOT_ID'] =
        $id;

    $_SERVER['BOT_TOKEN'] =
        $bot['token'];

    $_SERVER['WEBHOOK_URL'] =
        botWebhook($id);

    putenv(
        'BOT_ID=' .
        $id
    );

    putenv(
        'BOT_TOKEN=' .
        $bot['token']
    );

    putenv(
        'WEBHOOK_URL=' .
        botWebhook($id)
    );

    /*
     * Execute uploaded bot.
     */

    try {

        require $file;

    } catch (
        Throwable $e
    ) {

        http_response_code(500);

        echo 'BOT ERROR';

        /*
         * Save error for admin.
         */

        $errorFile =
            dirname($file) .
            '/error.log';

        @file_put_contents(
            $errorFile,
            date('c') .
            ' ' .
            $e->getMessage() .
            PHP_EOL,
            FILE_APPEND
        );
    }
}

/* =========================
   MANAGER COMMANDS
========================= */

function handleManagerUpdate(
    array $update
): void {

    if (
        isset(
            $update['callback_query']
        )
    ) {

        $cb =
            $update['callback_query'];

        $userId =
            (string)(
                $cb['from']['id'] ?? ''
            );

        if (
            $userId !== ADMIN_ID
        ) {

            telegram(
                MANAGER_TOKEN,
                'answerCallbackQuery',
                [
                    'callback_query_id' =>
                        $cb['id'],
                    'text' =>
                        'Admin only'
                ]
            );

            return;
        }

        $chat =
            $cb['message']['chat']['id']
            ?? 0;

        $messageId =
            $cb['message']['message_id']
            ?? 0;

        $action =
            $cb['data'] ?? '';

        telegram(
            MANAGER_TOKEN,
            'answerCallbackQuery',
            [
                'callback_query_id' =>
                    $cb['id']
            ]
        );

        managerAction(
            $chat,
            $messageId,
            $action
        );

        return;
    }

    if (
        !isset(
            $update['message']
        )
    ) {
        return;
    }

    $message =
        $update['message'];

    $chat =
        $message['chat']['id']
        ?? 0;

    $userId =
        (string)(
            $message['from']['id']
            ?? ''
        );

    if (
        $userId !== ADMIN_ID
    ) {

        sendMessage(
            $chat,
            "❌ <b>Access Denied</b>"
        );

        return;
    }

    /*
     * Document upload.
     */

    if (
        isset(
            $message['document']
        )
    ) {

        deployBot(
            $chat,
            $message['document']
        );

        return;
    }

    $text =
        trim(
            $message['text'] ?? ''
        );

    if (
        $text === '/start' ||
        $text === '/menu'
    ) {

        sendMessage(
            $chat,
            dashboardText(),
            keyboard()
        );

        return;
    }

    if (
        $text === '/setup'
    ) {

        $result =
            setManagerWebhook();

        sendMessage(
            $chat,
            !empty($result['ok'])
                ? "✅ <b>Manager webhook set successfully.</b>\n\n" .
                  htmlspecialchars(
                      rtrim(
                          RAILWAY_URL,
                          '/'
                      ) .
                      '/?manager=1'
                  )
                : "❌ <b>Webhook failed</b>\n\n" .
                  htmlspecialchars(
                      $result['description']
                      ?? 'Unknown error'
                  )
        );

        return;
    }

    if (
        $text === '/bots'
    ) {

        sendMessage(
            $chat,
            botList(),
            keyboard()
        );

        return;
    }

    if (
        $text === '/health'
    ) {

        sendMessage(
            $chat,
            health()
        );

        return;
    }

    sendMessage(
        $chat,
        dashboardText(),
        keyboard()
    );
}

/* =========================
   DASHBOARD
========================= */

function dashboardText(): string
{
    $bots =
        getBots();

    $total =
        count($bots);

    $running =
        0;

    foreach ($bots as $bot) {
        if (
            ($bot['status'] ?? '') ===
            'running'
        ) {
            $running++;
        }
    }

    return
        "🤖 <b>VICKY BOT HOSTING</b>\n\n" .

        "📦 Total Bots: <b>" .
        $total .
        "</b>\n" .

        "🟢 Running: <b>" .
        $running .
        "</b>\n" .

        "🔴 Stopped: <b>" .
        ($total - $running) .
        "</b>\n\n" .

        "🌐 Railway:\n" .
        "<code>" .
        htmlspecialchars(
            RAILWAY_URL
        ) .
        "</code>";
}

function keyboard(): array
{
    return [
        'inline_keyboard' => [
            [
                [
                    'text' =>
                        '📊 Dashboard',
                    'callback_data' =>
                        'dashboard'
                ]
            ],
            [
                [
                    'text' =>
                        '🤖 Bots',
                    'callback_data' =>
                        'bots'
                ]
            ],
            [
                [
                    'text' =>
                        '⚙️ Health',
                    'callback_data' =>
                        'health'
                ]
            ],
            [
                [
                    'text' =>
                        '🔗 Set Manager Webhook',
                    'callback_data' =>
                        'setup'
                ]
            ]
        ]
    ];
}

/* =========================
   BOT LIST
========================= */

function botList(): string
{
    $bots =
        getBots();

    if (!$bots) {
        return
            "🤖 <b>BOT LIST</b>\n\n" .
            "No bots hosted.";
    }

    $text =
        "🤖 <b>HOSTED BOTS</b>\n\n";

    foreach ($bots as $id => $bot) {

        $text .=
            "🤖 <b>" .
            htmlspecialchars(
                $bot['username'] ??
                'Unknown'
            ) .
            "</b>\n" .

            "ID: <code>" .
            htmlspecialchars(
                $id
            ) .
            "</code>\n" .

            "Status: 🟢 " .
            htmlspecialchars(
                $bot['status'] ??
                'unknown'
            ) .
            "\n\n";
    }

    return $text;
}

/* =========================
   CALLBACK ACTIONS
========================= */

function managerAction(
    int|string $chat,
    int $messageId,
    string $action
): void {

    if (
        $action === 'dashboard'
    ) {

        telegram(
            MANAGER_TOKEN,
            'editMessageText',
            [
                'chat_id' =>
                    $chat,
                'message_id' =>
                    $messageId,
                'text' =>
                    dashboardText(),
                'parse_mode' =>
                    'HTML',
                'reply_markup' =>
                    json_encode(
                        keyboard()
                    )
            ]
        );

        return;
    }

    if (
        $action === 'bots'
    ) {

        telegram(
            MANAGER_TOKEN,
            'editMessageText',
            [
                'chat_id' =>
                    $chat,
                'message_id' =>
                    $messageId,
                'text' =>
                    botList(),
                'parse_mode' =>
                    'HTML',
                'reply_markup' =>
                    json_encode(
                        keyboard()
                    )
            ]
        );

        return;
    }

    if (
        $action === 'health'
    ) {

        telegram(
            MANAGER_TOKEN,
            'editMessageText',
            [
                'chat_id' =>
                    $chat,
                'message_id' =>
                    $messageId,
                'text' =>
                    health(),
                'parse_mode' =>
                    'HTML',
                'reply_markup' =>
                    json_encode(
                        keyboard()
                    )
            ]
        );

        return;
    }

    if (
        $action === 'setup'
    ) {

        $result =
            setManagerWebhook();

        telegram(
            MANAGER_TOKEN,
            'sendMessage',
            [
                'chat_id' =>
                    $chat,
                'text' =>
                    !empty(
                        $result['ok']
                    )
                    ? "✅ Manager webhook set."
                    : "❌ Webhook failed: " .
                      (
                          $result['description']
                          ?? 'Unknown'
                      )
            ]
        );

        return;
    }
}

/* =========================
   HEALTH
========================= */

function health(): string
{
    $curl =
        function_exists(
            'curl_init'
        )
        ? '✅'
        : '❌';

    $zip =
        class_exists(
            'ZipArchive'
        )
        ? '✅'
        : '⚠️';

    return
        "⚙️ <b>SYSTEM HEALTH</b>\n\n" .

        "PHP: ✅\n" .
        "cURL: {$curl}\n" .
        "ZIP: {$zip}\n" .
        "Webhook Engine: ✅\n" .
        "Railway Mode: ✅\n\n" .

        "🌐 " .
        htmlspecialchars(
            RAILWAY_URL
        );
}

/* =========================
   ROUTER
========================= */

/*
 * 1. Hosted bot webhook
 */

if (
    isset($_GET['bot']) &&
    $_GET['bot'] !== ''
) {

    executeHostedBot(
        (string)$_GET['bot']
    );

    exit;
}

/*
 * 2. Manager webhook
 */

if (
    isset($_GET['manager']) &&
    $_GET['manager'] === '1'
) {

    $body =
        file_get_contents(
            'php://input'
        );

    $update =
        json_decode(
            (string)$body,
            true
        );

    if (is_array($update)) {
        handleManagerUpdate(
            $update
        );
    }

    http_response_code(200);

    echo 'OK';

    exit;
}

/*
 * 3. Health
 */

if (
    isset($_GET['health'])
) {

    header(
        'Content-Type: text/plain'
    );

    echo health();

    exit;
}

/*
 * 4. Normal browser request.
 *
 * IMPORTANT:
 * Open the Railway URL once after deployment.
 * This installs the manager webhook.
 */

$result =
    setManagerWebhook();

/* =========================
   WEB PAGE
========================= */

$bots =
    getBots();

?>
<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">

<meta name="viewport"
content="width=device-width,initial-scale=1">

<title>Vicky Bot Hosting</title>

<style>

*{
    box-sizing:border-box;
}

body{
    margin:0;
    min-height:100vh;
    background:
        radial-gradient(
            circle at top,
            #12346b,
            #050912 55%,
            #020409
        );
    color:white;
    font-family:
        Arial,
        sans-serif;
}

.container{
    width:min(1000px,94%);
    margin:40px auto;
}

.header{
    padding:30px;
    border-radius:24px;
    background:
        rgba(255,255,255,.06);
    border:
        1px solid
        rgba(255,255,255,.1);
}

h1{
    margin-top:0;
}

.grid{
    display:grid;
    grid-template-columns:
        repeat(
            auto-fit,
            minmax(180px,1fr)
        );
    gap:15px;
    margin-top:20px;
}

.card{
    padding:22px;
    border-radius:18px;
    background:
        rgba(255,255,255,.06);
    border:
        1px solid
        rgba(255,255,255,.08);
}

.num{
    font-size:30px;
    font-weight:bold;
}

.domain{
    margin-top:20px;
    padding:18px;
    border-radius:15px;
    background:#07101d;
    word-break:break-all;
}

.bot{
    margin-top:15px;
    padding:20px;
    border-radius:18px;
    background:
        rgba(255,255,255,.05);
}

.green{
    color:#4ade80;
}

</style>

</head>

<body>

<div class="container">

<div class="header">

<h1>
🤖 VICKY BOT HOSTING
</h1>

<p>
Single-file Railway Telegram Bot Hosting
</p>

<div class="grid">

<div class="card">
Total Bots
<div class="num">
<?=count($bots)?>
</div>
</div>

<div class="card">
Running
<div class="num green">
<?php

$running = 0;

foreach($bots as $b){

    if(
        ($b['status'] ?? '') ===
        'running'
    ){
        $running++;
    }
}

echo $running;

?>
</div>
</div>

<div class="card">
Stopped
<div class="num">
<?=count($bots)-$running?>
</div>
</div>

</div>

<div class="domain">

<b>Railway Domain</b>

<br><br>

<code>
<?=htmlspecialchars(
    RAILWAY_URL
)?>
</code>

</div>

<br>

<b>Manager Webhook:</b>

<br><br>

<code>
<?=htmlspecialchars(
    RAILWAY_URL .
    '/?manager=1'
)?>
</code>

</div>

<?php foreach($bots as $id=>$bot): ?>

<div class="bot">

<b>
<?=htmlspecialchars(
    $bot['username'] ??
    'Unknown'
)?>
</b>

<br><br>

Bot ID:
<code>
<?=htmlspecialchars($id)?>
</code>

<br><br>

Status:
<span class="green">
<?=htmlspecialchars(
    $bot['status'] ??
    'unknown'
)?>
</span>

<br><br>

Webhook:
<br>

<code>
<?=htmlspecialchars(
    botWebhook($id)
)?>
</code>

</div>

<?php endforeach; ?>

</div>

</body>
</html>
