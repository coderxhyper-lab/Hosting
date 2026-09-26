<?php
declare(strict_types=1);

/*
=========================================================
 NOVA MUSIC TELEGRAM BOT - SINGLE FILE index.php
 Features:
 - Telegram Stars access gate
 - Music API search
 - Search result cards
 - Telegram Mini App player (plays source URL inside Telegram)
 - MP3 conversion/download through FFmpeg
 - Save / Queue
 - Admin commands
 - JSON file storage
=========================================================

IMPORTANT:
1. Replace BOT_TOKEN with a NEW token from @BotFather.
2. Set WEBAPP_URL to your public HTTPS URL.
3. FFmpeg is required only for the MP3 conversion button.
4. The current music API does not return thumbnail/image data.
*/

const BOT_TOKEN       = '8817347840:AAFpsNeTkzHqjnlqkV_18AjMEgIX-FXHmQo';
const ADMIN_ID        = '8897821078';

const MUSIC_API       = 'https://music-search-api-frnb.vercel.app/search?song=';
const REQUIRED_STARS  = 5;

// Example:
// https://your-domain.com/index.php
const WEBAPP_URL      = 'hosting-production-aacd.up.railway.app/index.php';

const DATA_DIR        = __DIR__ . '/data';
const TEMP_DIR        = __DIR__ . '/tmp';

const USERS_FILE      = DATA_DIR . '/users.json';
const RESULTS_FILE    = DATA_DIR . '/results.json';
const PLAYER_FILE     = DATA_DIR . '/players.json';
const QUEUE_FILE      = DATA_DIR . '/queues.json';

date_default_timezone_set('UTC');

bootstrap();

/*
|--------------------------------------------------------------------------
| MINI APP PLAYER
|--------------------------------------------------------------------------
| Must run before Telegram webhook processing.
|
| URL:
|   index.php?player=1&t=PLAYER_TOKEN
|--------------------------------------------------------------------------
*/
if (isset($_GET['player']) && $_GET['player'] === '1') {
    renderPlayer();
    exit;
}

/*
|--------------------------------------------------------------------------
| TELEGRAM WEBHOOK
|--------------------------------------------------------------------------
*/
$rawUpdate = file_get_contents('php://input');
$update = json_decode($rawUpdate ?: '', true);

if (!is_array($update)) {
    // If opened directly in browser, show a small setup message.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Nova Music Bot is running.";
    }
    exit;
}

handleUpdate($update);
exit;


/* =========================================================
   BOOTSTRAP
   ========================================================= */

function bootstrap(): void
{
    foreach ([DATA_DIR, TEMP_DIR] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    foreach ([
        USERS_FILE   => [],
        RESULTS_FILE => [],
        PLAYER_FILE  => [],
        QUEUE_FILE   => []
    ] as $file => $default) {
        if (!file_exists($file)) {
            @file_put_contents(
                $file,
                json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        }
    }
}


/* =========================================================
   TELEGRAM API
   ========================================================= */

function telegram(string $method, array $params = []): array
{
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $error) {
        return [
            'ok' => false,
            'description' => $error ?: 'Telegram request failed'
        ];
    }

    $decoded = json_decode($response, true);

    return is_array($decoded)
        ? $decoded
        : [
            'ok' => false,
            'description' => 'Invalid Telegram response'
        ];
}


function answerCallback(string $callbackId, string $text = '', bool $alert = false): void
{
    telegram('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => $text,
        'show_alert'        => $alert ? 'true' : 'false'
    ]);
}


function editMessageText(
    int|string $chatId,
    int $messageId,
    string $text,
    ?array $keyboard = null,
    string $parseMode = 'HTML'
): void {
    $params = [
        'chat_id'    => $chatId,
        'message_id' => $messageId,
        'text'       => $text,
        'parse_mode' => $parseMode
    ];

    if ($keyboard !== null) {
        $params['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }

    telegram('editMessageText', $params);
}


function sendMessage(
    int|string $chatId,
    string $text,
    ?array $keyboard = null,
    string $parseMode = 'HTML'
): array {
    $params = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => 'true'
    ];

    if ($keyboard !== null) {
        $params['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }

    return telegram('sendMessage', $params);
}


/* =========================================================
   JSON STORAGE
   ========================================================= */

function readJson(string $file, array $default = []): array
{
    if (!file_exists($file)) {
        return $default;
    }

    $raw = @file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : $default;
}


function writeJson(string $file, array $data): bool
{
    $tmp = $file . '.tmp';

    $ok = @file_put_contents(
        $tmp,
        json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        ),
        LOCK_EX
    );

    if ($ok === false) {
        return false;
    }

    return @rename($tmp, $file);
}


/* =========================================================
   USER / ACCESS
   ========================================================= */

function saveUser(array $from): void
{
    if (!isset($from['id'])) {
        return;
    }

    $users = readJson(USERS_FILE);

    $id = (string)$from['id'];

    if (!isset($users[$id])) {
        $users[$id] = [
            'id'           => $id,
            'first_name'   => $from['first_name'] ?? '',
            'last_name'    => $from['last_name'] ?? '',
            'username'     => $from['username'] ?? '',
            'access'       => false,
            'access_until' => null,
            'joined_at'    => time(),
            'last_seen'    => time()
        ];
    } else {
        $users[$id]['first_name'] = $from['first_name'] ?? ($users[$id]['first_name'] ?? '');
        $users[$id]['last_name']  = $from['last_name'] ?? ($users[$id]['last_name'] ?? '');
        $users[$id]['username']   = $from['username'] ?? ($users[$id]['username'] ?? '');
        $users[$id]['last_seen']  = time();
    }

    writeJson(USERS_FILE, $users);
}


function hasAccess(int|string $userId): bool
{
    if ((string)$userId === (string)ADMIN_ID) {
        return true;
    }

    $users = readJson(USERS_FILE);

    $id = (string)$userId;

    if (!isset($users[$id])) {
        return false;
    }

    if (!empty($users[$id]['access']) && empty($users[$id]['access_until'])) {
        return true;
    }

    $until = (int)($users[$id]['access_until'] ?? 0);

    return $until > time();
}


function grantAccess(int|string $userId, ?int $days = null): void
{
    $users = readJson(USERS_FILE);

    $id = (string)$userId;

    if (!isset($users[$id])) {
        $users[$id] = [
            'id' => $id,
            'access' => false,
            'access_until' => null
        ];
    }

    $users[$id]['access'] = true;

    if ($days === null) {
        $users[$id]['access_until'] = null;
    } else {
        $users[$id]['access_until'] = time() + ($days * 86400);
    }

    writeJson(USERS_FILE, $users);
}


/* =========================================================
   STARS ACCESS
   ========================================================= */

function sendAccessInvoice(int|string $chatId): void
{
    telegram('sendInvoice', [
        'chat_id'              => $chatId,
        'title'                => 'Nova Music Premium',
        'description'          => 'Unlock Nova Music search, playback and downloads.',
        'payload'              => 'nova_access_' . $chatId . '_' . time(),
        'currency'             => 'XTR',
        'prices'               => json_encode([
            [
                'label'  => 'Premium Access',
                'amount' => REQUIRED_STARS
            ]
        ]),
        'provider_token'       => ''
    ]);
}


function accessMessage(int|string $chatId): void
{
    $keyboard = [
        'inline_keyboard' => [
            [
                [
                    'text' => '⭐ Unlock for ' . REQUIRED_STARS . ' Stars',
                    'callback_data' => 'buy_access'
                ]
            ],
            [
                [
                    'text' => '🔄 Check Access',
                    'callback_data' => 'check_access'
                ]
            ]
        ]
    ];

    sendMessage(
        $chatId,
        "🎵 <b>Nova Music</b>\n\n" .
        "Premium access is required to use music search and playback.\n\n" .
        "⭐ Price: <b>" . REQUIRED_STARS . " Telegram Stars</b>",
        $keyboard
    );
}


/* =========================================================
   MUSIC API
   ========================================================= */

function searchMusic(string $query): array
{
    $query = trim($query);

    if ($query === '') {
        return [];
    }

    $url = MUSIC_API . urlencode($query);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'NovaMusicBot/1.0'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return [];
    }

    $json = json_decode($response, true);

    if (!is_array($json) || empty($json['results']) || !is_array($json['results'])) {
        return [];
    }

    $results = [];

    foreach ($json['results'] as $item) {
        if (!is_array($item)) {
            continue;
        }

        $title = trim((string)($item['title'] ?? ''));
        $artists = trim((string)($item['artists'] ?? ''));
        $album = trim((string)($item['album'] ?? ''));
        $duration = trim((string)($item['duration'] ?? ''));
        $downloadUrl = trim((string)($item['download_url'] ?? ''));

        if ($title === '' || $downloadUrl === '') {
            continue;
        }

        $results[] = [
            'title'        => $title,
            'artists'      => $artists !== '' ? $artists : 'Unknown Artist',
            'album'        => $album !== '' ? $album : 'Unknown Album',
            'duration'     => $duration !== '' ? $duration : '--:--',
            'download_url' => $downloadUrl,
            'thumbnail'    => ''
        ];
    }

    return array_slice($results, 0, 10);
}


/* =========================================================
   RESULT CACHE
   ========================================================= */

function saveResults(int|string $chatId, array $results): void
{
    $all = readJson(RESULTS_FILE);

    $all[(string)$chatId] = [
        'saved_at' => time(),
        'results'  => $results
    ];

    writeJson(RESULTS_FILE, $all);
}


function getSong(int|string $chatId, int $index): ?array
{
    $all = readJson(RESULTS_FILE);

    $id = (string)$chatId;

    if (!isset($all[$id]['results'][$index])) {
        return null;
    }

    return is_array($all[$id]['results'][$index])
        ? $all[$id]['results'][$index]
        : null;
}


/* =========================================================
   MINI APP PLAYER TOKEN
   ========================================================= */

function createPlayerToken(array $song): string
{
    $token = bin2hex(random_bytes(16));

    $players = readJson(PLAYER_FILE);

    $players[$token] = [
        'song'       => $song,
        'created_at' => time(),
        'expires_at' => time() + 3600
    ];

    // Keep file from growing forever.
    foreach ($players as $key => $value) {
        if ((int)($value['expires_at'] ?? 0) < time()) {
            unset($players[$key]);
        }
    }

    writeJson(PLAYER_FILE, $players);

    return $token;
}


function getPlayerSong(string $token): ?array
{
    $players = readJson(PLAYER_FILE);

    if (!isset($players[$token]) || !is_array($players[$token])) {
        return null;
    }

    $item = $players[$token];

    if ((int)($item['expires_at'] ?? 0) < time()) {
        unset($players[$token]);
        writeJson(PLAYER_FILE, $players);
        return null;
    }

    return isset($item['song']) && is_array($item['song'])
        ? $item['song']
        : null;
}


/* =========================================================
   KEYBOARDS
   ========================================================= */

function resultKeyboard(int|string $chatId, array $results): array
{
    $rows = [];

    foreach ($results as $i => $song) {
        $token = createPlayerToken($song);

        $playerUrl =
            rtrim(WEBAPP_URL, '?&') .
            '?player=1&t=' .
            urlencode($token);

        $rows[] = [
            [
                'text' => '▶️ Play ' . ($i + 1),
                'web_app' => [
                    'url' => $playerUrl
                ]
            ],
            [
                'text' => '📥 MP3',
                'callback_data' => 'mp3:' . $i
            ]
        ];

        $rows[] = [
            [
                'text' => '💾 Save',
                'callback_data' => 'save:' . $i
            ],
            [
                'text' => '➕ Queue',
                'callback_data' => 'queue:' . $i
            ]
        ];
    }

    return [
        'inline_keyboard' => $rows
    ];
}


/* =========================================================
   SEARCH MESSAGE
   ========================================================= */

function showSearchResults(int|string $chatId, string $query): void
{
    sendMessage(
        $chatId,
        "🔎 <b>Searching...</b>\n<code>" . e($query) . "</code>"
    );

    $results = searchMusic($query);

    if (!$results) {
        sendMessage(
            $chatId,
            "❌ No results found.\n\nTry another song or artist name."
        );
        return;
    }

    saveResults($chatId, $results);

    $text = "🎵 <b>Nova Music Results</b>\n\n";

    foreach ($results as $i => $song) {
        $text .=
            "<b>" . ($i + 1) . ". " . e($song['title']) . "</b>\n" .
            "👤 " . e($song['artists']) . "\n" .
            "💿 " . e($song['album']) . "\n" .
            "⏱ " . e($song['duration']) . "\n\n";
    }

    $text .= "Tap <b>▶️ Play</b> to open the in-Telegram player.";

    sendMessage(
        $chatId,
        $text,
        resultKeyboard($chatId, $results)
    );
}


/* =========================================================
   MP3 DOWNLOAD
   ========================================================= */

function findFFmpeg(): ?string
{
    $candidates = [
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        '/opt/bin/ffmpeg',
        '/bin/ffmpeg'
    ];

    foreach ($candidates as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }

    $which = @shell_exec('command -v ffmpeg 2>/dev/null');

    if (is_string($which)) {
        $which = trim($which);

        if ($which !== '' && is_executable($which)) {
            return $which;
        }
    }

    return null;
}


function downloadRemoteFile(string $url, string $destination): bool
{
    $fp = @fopen($destination, 'wb');

    if (!$fp) {
        return false;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'NovaMusicBot/1.0'
    ]);

    $ok = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    fclose($fp);

    return $ok !== false && $http >= 200 && $http < 400 && filesize($destination) > 1024;
}


function convertToMP3(string $input, string $output, string $ffmpeg): bool
{
    /*
     * The API URL may be an .mp4 container containing AAC.
     * FFmpeg converts the actual audio stream to MP3.
     */
    $cmd =
        escapeshellarg($ffmpeg) .
        ' -y -hide_banner -loglevel error ' .
        '-i ' . escapeshellarg($input) . ' ' .
        '-vn -codec:a libmp3lame -b:a 128k ' .
        '-map_metadata -1 ' .
        escapeshellarg($output) .
        ' 2>&1';

    @exec($cmd, $outputLines, $exitCode);

    return $exitCode === 0 &&
           file_exists($output) &&
           filesize($output) > 1024;
}


function sendMP3(int|string $chatId, array $song): void
{
    if (empty($song['download_url'])) {
        sendMessage($chatId, "❌ Audio source is unavailable.");
        return;
    }

    $ffmpeg = findFFmpeg();

    if ($ffmpeg === null) {
        sendMessage(
            $chatId,
            "❌ FFmpeg is not installed on the server.\n\n" .
            "Install FFmpeg first, then try 📥 MP3 again."
        );
        return;
    }

    sendMessage(
        $chatId,
        "⏳ <b>Preparing MP3...</b>\n" .
        e($song['title'])
    );

    $base = 'nova_' . bin2hex(random_bytes(8));

    $input = TEMP_DIR . '/' . $base . '.source';
    $output = TEMP_DIR . '/' . $base . '.mp3';

    try {
        if (!downloadRemoteFile($song['download_url'], $input)) {
            sendMessage($chatId, "❌ Could not download the source audio.");
            return;
        }

        if (!convertToMP3($input, $output, $ffmpeg)) {
            sendMessage(
                $chatId,
                "❌ MP3 conversion failed.\n\n" .
                "Check FFmpeg and the source URL."
            );
            return;
        }

        $filename = safeFilename(
            $song['title'] . ' - ' . $song['artists'] . '.mp3'
        );

        $result = telegram('sendAudio', [
            'chat_id'   => $chatId,
            'audio'     => new CURLFile(
                $output,
                'audio/mpeg',
                $filename
            ),
            'title'     => $song['title'],
            'performer' => $song['artists'],
            'caption'   => '🎵 ' . $song['title'] . "\nNova Music"
        ]);

        if (empty($result['ok'])) {
            sendMessage(
                $chatId,
                "❌ Telegram upload failed.\n\n" .
                e((string)($result['description'] ?? 'Unknown error'))
            );
        }
    } finally {
        @unlink($input);
        @unlink($output);
    }
}


/* =========================================================
   SAVE / QUEUE
   ========================================================= */

function saveSong(int|string $chatId, array $song): void
{
    $users = readJson(USERS_FILE);

    $id = (string)$chatId;

    if (!isset($users[$id])) {
        $users[$id] = [];
    }

    if (!isset($users[$id]['saved']) || !is_array($users[$id]['saved'])) {
        $users[$id]['saved'] = [];
    }

    $users[$id]['saved'][] = $song;

    // Keep latest 50.
    $users[$id]['saved'] = array_slice($users[$id]['saved'], -50);

    writeJson(USERS_FILE, $users);
}


function queueSong(int|string $chatId, array $song): void
{
    $queues = readJson(QUEUE_FILE);

    $id = (string)$chatId;

    if (!isset($queues[$id]) || !is_array($queues[$id])) {
        $queues[$id] = [];
    }

    $queues[$id][] = $song;
    $queues[$id] = array_slice($queues[$id], -50);

    writeJson(QUEUE_FILE, $queues);
}


/* =========================================================
   UPDATE ROUTER
   ========================================================= */

function handleUpdate(array $update): void
{
    // Save normal users.
    if (isset($update['message']['from'])) {
        saveUser($update['message']['from']);
    }

    if (isset($update['callback_query']['from'])) {
        saveUser($update['callback_query']['from']);
    }

    // Pre-checkout for Telegram Stars.
    if (isset($update['pre_checkout_query'])) {
        $query = $update['pre_checkout_query'];

        telegram('answerPreCheckoutQuery', [
            'pre_checkout_query_id' => $query['id'],
            'ok' => 'true'
        ]);

        return;
    }

    // Successful Stars payment.
    if (isset($update['message']['successful_payment'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'];

        grantAccess($chatId);

        sendMessage(
            $chatId,
            "✅ <b>Payment successful!</b>\n\n" .
            "Nova Music Premium is now unlocked.\n\n" .
            "🎵 Send a song name or artist to search."
        );

        return;
    }

    // Callback buttons.
    if (isset($update['callback_query'])) {
        handleCallback($update['callback_query']);
        return;
    }

    // Normal messages.
    if (isset($update['message'])) {
        handleMessage($update['message']);
        return;
    }
}


/* =========================================================
   MESSAGE HANDLER
   ========================================================= */

function handleMessage(array $message): void
{
    $chatId = $message['chat']['id'];
    $text = trim((string)($message['text'] ?? ''));

    if ($text === '') {
        return;
    }

    if ($text === '/start') {
        if (hasAccess($chatId)) {
            sendMessage(
                $chatId,
                "🎵 <b>Welcome to Nova Music</b>\n\n" .
                "Send a song name, artist or album to search.\n\n" .
                "Example:\n" .
                "<code>Chandni</code>\n" .
                "<code>Arijit Singh</code>"
            );
        } else {
            accessMessage($chatId);
        }

        return;
    }

    if ($text === '/help') {
        sendMessage(
            $chatId,
            "🎵 <b>Nova Music Help</b>\n\n" .
            "• Send a song name to search\n" .
            "• ▶️ Play opens the Telegram Mini App player\n" .
            "• 📥 MP3 converts the source to MP3 using FFmpeg\n" .
            "• 💾 Save stores a song for the user\n" .
            "• ➕ Queue adds a song to the queue\n\n" .
            "Use /start to return to Nova Music."
        );

        return;
    }

    if ($text === '/admin') {
        if ((string)$chatId !== (string)ADMIN_ID) {
            sendMessage($chatId, "⛔ Admin only.");
            return;
        }

        $users = readJson(USERS_FILE);

        $total = count($users);
        $active = 0;

        foreach ($users as $user) {
            if (!empty($user['access'])) {
                $until = $user['access_until'] ?? null;

                if ($until === null || (int)$until > time()) {
                    $active++;
                }
            }
        }

        sendMessage(
            $chatId,
            "🛠 <b>Nova Admin</b>\n\n" .
            "👥 Users: <b>" . $total . "</b>\n" .
            "⭐ Active access: <b>" . $active . "</b>"
        );

        return;
    }

    if (!hasAccess($chatId)) {
        accessMessage($chatId);
        return;
    }

    showSearchResults($chatId, $text);
}


/* =========================================================
   CALLBACK HANDLER
   ========================================================= */

function handleCallback(array $callback): void
{
    $callbackId = $callback['id'];
    $chatId = $callback['message']['chat']['id'] ?? null;

    if ($chatId === null) {
        answerCallback($callbackId, 'Chat unavailable.', true);
        return;
    }

    $data = (string)($callback['data'] ?? '');

    if ($data === 'buy_access') {
        answerCallback($callbackId, 'Opening Stars payment...');
        sendAccessInvoice($chatId);
        return;
    }

    if ($data === 'check_access') {
        if (hasAccess($chatId)) {
            answerCallback($callbackId, 'Access is active.');
            sendMessage(
                $chatId,
                "✅ <b>Access active.</b>\n\nSend a song name to search."
            );
        } else {
            answerCallback($callbackId, 'Premium access is not active.', true);
        }

        return;
    }

    if (!hasAccess($chatId)) {
        answerCallback($callbackId, 'Premium access required.', true);
        return;
    }

    if (preg_match('/^(mp3|save|queue):(\d+)$/', $data, $m)) {
        $action = $m[1];
        $index = (int)$m[2];

        $song = getSong($chatId, $index);

        if (!$song) {
            answerCallback($callbackId, 'This result has expired. Search again.', true);
            return;
        }

        if ($action === 'mp3') {
            answerCallback($callbackId, 'Preparing MP3...');
            sendMP3($chatId, $song);
            return;
        }

        if ($action === 'save') {
            saveSong($chatId, $song);
            answerCallback($callbackId, 'Saved to your Nova library.');
            return;
        }

        if ($action === 'queue') {
            queueSong($chatId, $song);
            answerCallback($callbackId, 'Added to queue.');
            return;
        }
    }

    answerCallback($callbackId, 'Unknown action.');
}


/* =========================================================
   MINI APP HTML
   ========================================================= */

function renderPlayer(): void
{
    $token = trim((string)($_GET['t'] ?? ''));
    $song = $token !== '' ? getPlayerSong($token) : null;

    header('Content-Type: text/html; charset=utf-8');

    if (!$song || empty($song['download_url'])) {
        echo playerErrorPage();
        return;
    }

    $title = e((string)($song['title'] ?? 'Unknown Title'));
    $artists = e((string)($song['artists'] ?? 'Unknown Artist'));
    $album = e((string)($song['album'] ?? 'Unknown Album'));
    $duration = e((string)($song['duration'] ?? '--:--'));
    $audio = e((string)$song['download_url']);

    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
<meta name="theme-color" content="#08090d">
<title>Nova Music</title>

<script src="https://telegram.org/js/telegram-web-app.js"></script>

<style>
:root{
    --bg:#08090d;
    --card:#11131a;
    --card2:#171922;
    --text:#ffffff;
    --muted:#9298a8;
    --line:rgba(255,255,255,.08);
    --accent:#ffffff;
}

*{
    box-sizing:border-box;
    -webkit-tap-highlight-color:transparent;
}

html,body{
    margin:0;
    width:100%;
    min-height:100%;
    background:var(--bg);
    color:var(--text);
    font-family:
      -apple-system,
      BlinkMacSystemFont,
      "SF Pro Display",
      "SF Pro Text",
      Inter,
      Arial,
      sans-serif;
}

body{
    min-height:100vh;
    overflow:hidden;
}

.app{
    min-height:100vh;
    display:flex;
    flex-direction:column;
    padding:
      calc(env(safe-area-inset-top) + 20px)
      18px
      calc(env(safe-area-inset-bottom) + 18px);
}

.top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:20px;
}

.brand{
    font-size:17px;
    font-weight:800;
    letter-spacing:-.4px;
}

.status{
    display:flex;
    align-items:center;
    gap:7px;
    color:var(--muted);
    font-size:11px;
}

.dot{
    width:7px;
    height:7px;
    border-radius:50%;
    background:#56e39f;
    box-shadow:0 0 12px rgba(86,227,159,.55);
}

.cover{
    width:min(78vw,330px);
    aspect-ratio:1/1;
    margin:20px auto 28px;
    border-radius:30px;
    background:
      radial-gradient(circle at 28% 22%,rgba(255,255,255,.15),transparent 26%),
      radial-gradient(circle at 70% 78%,rgba(255,255,255,.08),transparent 28%),
      linear-gradient(145deg,#292d3a,#0e1016);
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:
      0 30px 70px rgba(0,0,0,.45),
      inset 0 1px 0 rgba(255,255,255,.08);
    animation:float 4s ease-in-out infinite;
}

.coverIcon{
    font-size:78px;
    filter:drop-shadow(0 12px 24px rgba(0,0,0,.35));
}

@keyframes float{
    0%,100%{transform:translateY(0) scale(1)}
    50%{transform:translateY(-5px) scale(1.008)}
}

.meta{
    text-align:left;
    margin-bottom:20px;
}

.title{
    font-size:25px;
    line-height:1.12;
    font-weight:850;
    letter-spacing:-.8px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.artist{
    margin-top:8px;
    color:#c3c7d2;
    font-size:15px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.album{
    margin-top:5px;
    color:var(--muted);
    font-size:12px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.progressWrap{
    margin-top:7px;
}

input[type=range]{
    width:100%;
    height:4px;
    appearance:none;
    -webkit-appearance:none;
    background:#333640;
    border-radius:999px;
    outline:none;
}

input[type=range]::-webkit-slider-thumb{
    appearance:none;
    -webkit-appearance:none;
    width:13px;
    height:13px;
    border-radius:50%;
    background:#fff;
    box-shadow:0 2px 10px rgba(0,0,0,.3);
}

.times{
    display:flex;
    justify-content:space-between;
    color:var(--muted);
    font-size:10px;
    margin-top:8px;
}

.controls{
    margin-top:22px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:22px;
}

.ctrl{
    border:0;
    background:transparent;
    color:#fff;
    width:46px;
    height:46px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:21px;
    transition:transform .12s ease,background .12s ease;
}

.ctrl:active{
    transform:scale(.88);
    background:rgba(255,255,255,.08);
}

.play{
    width:64px;
    height:64px;
    background:#fff;
    color:#08090d;
    font-size:25px;
    box-shadow:0 14px 35px rgba(0,0,0,.35);
}

.bottom{
    margin-top:auto;
    padding-top:18px;
    text-align:center;
    color:var(--muted);
    font-size:10px;
}

.error{
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
    text-align:center;
}

.errorBox{
    width:100%;
    max-width:380px;
    padding:28px;
    border-radius:24px;
    background:var(--card);
    border:1px solid var(--line);
}

.errorTitle{
    font-size:22px;
    font-weight:800;
    margin-bottom:10px;
}

.errorText{
    color:var(--muted);
    line-height:1.5;
}
</style>
</head>

<body>
<div class="app">

    <div class="top">
        <div class="brand">Nova</div>
        <div class="status">
            <span class="dot"></span>
            <span>PLAYER</span>
        </div>
    </div>

    <div class="cover">
        <div class="coverIcon">🎵</div>
    </div>

    <div class="meta">
        <div class="title" id="title">{$title}</div>
        <div class="artist">{$artists}</div>
        <div class="album">{$album}</div>
    </div>

    <div class="progressWrap">
        <input id="seek"
               type="range"
               min="0"
               max="100"
               value="0"
               step="0.1">
        <div class="times">
            <span id="current">0:00</span>
            <span id="total">{$duration}</span>
        </div>
    </div>

    <div class="controls">
        <button class="ctrl" id="prev" aria-label="Previous">⏮</button>
        <button class="ctrl play" id="play" aria-label="Play">▶</button>
        <button class="ctrl" id="next" aria-label="Next">⏭</button>
    </div>

    <div class="bottom">
        Tap the player to pause or resume
    </div>

</div>

<audio id="audio"
       preload="auto"
       playsinline
       src="{$audio}"></audio>

<script>
(function(){
    const tg = window.Telegram && window.Telegram.WebApp
        ? window.Telegram.WebApp
        : null;

    if(tg){
        try{
            tg.ready();
            tg.expand();
            if(tg.setHeaderColor) tg.setHeaderColor('#08090d');
            if(tg.setBackgroundColor) tg.setBackgroundColor('#08090d');
        }catch(e){}
    }

    const audio = document.getElementById('audio');
    const play = document.getElementById('play');
    const seek = document.getElementById('seek');
    const current = document.getElementById('current');
    const total = document.getElementById('total');

    let userStarted = false;

    function formatTime(seconds){
        if(!Number.isFinite(seconds)) return '0:00';
        const s = Math.max(0, Math.floor(seconds));
        const m = Math.floor(s / 60);
        const r = String(s % 60).padStart(2,'0');
        return m + ':' + r;
    }

    function setPlayIcon(){
        play.textContent = audio.paused ? '▶' : 'Ⅱ';
    }

    async function startPlayback(){
        try{
            await audio.play();
            userStarted = true;
            setPlayIcon();
        }catch(err){
            // Browser / Telegram WebView autoplay policy can block autoplay.
            setPlayIcon();
        }
    }

    play.addEventListener('click', async function(){
        if(audio.paused){
            await startPlayback();
        }else{
            audio.pause();
            setPlayIcon();
        }
    });

    audio.addEventListener('loadedmetadata', function(){
        if(Number.isFinite(audio.duration)){
            seek.max = String(audio.duration);
            total.textContent = formatTime(audio.duration);
        }

        // Best-effort autoplay. Telegram/WebView may require a user tap.
        startPlayback();
    });

    audio.addEventListener('timeupdate', function(){
        if(Number.isFinite(audio.duration)){
            seek.max = String(audio.duration);
            seek.value = String(audio.currentTime);
        }

        current.textContent = formatTime(audio.currentTime);
    });

    seek.addEventListener('input', function(){
        audio.currentTime = Number(seek.value || 0);
    });

    audio.addEventListener('play', setPlayIcon);
    audio.addEventListener('pause', setPlayIcon);

    audio.addEventListener('ended', function(){
        setPlayIcon();
        // The current API result page is one-song playback.
        // Previous/next can be connected to a playlist endpoint later.
    });

    document.getElementById('prev').addEventListener('click', function(){
        if(tg && tg.HapticFeedback){
            try{ tg.HapticFeedback.impactOccurred('light'); }catch(e){}
        }
    });

    document.getElementById('next').addEventListener('click', function(){
        if(tg && tg.HapticFeedback){
            try{ tg.HapticFeedback.impactOccurred('light'); }catch(e){}
        }
    });
})();
</script>
</body>
</html>
HTML;
}


function playerErrorPage(): string
{
    return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#08090d">
<title>Nova Music</title>
<style>
body{
    margin:0;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#08090d;
    color:#fff;
    font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;
}
.box{
    max-width:360px;
    margin:20px;
    padding:28px;
    border-radius:24px;
    background:#11131a;
    text-align:center;
}
h2{margin:0 0 10px}
p{color:#9298a8;line-height:1.5}
</style>
</head>
<body>
<div class="box">
    <h2>🎵 Player unavailable</h2>
    <p>This player link has expired or the song session is no longer available. Search the song again in Nova Music.</p>
</div>
</body>
</html>
HTML;
}


/* =========================================================
   HELPERS
   ========================================================= */

function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


function safeFilename(string $name): string
{
    $name = preg_replace('/[^\p{L}\p{N}\-_. ]+/u', '', $name);
    $name = trim((string)$name);

    if ($name === '') {
        $name = 'Nova Music';
    }

    return mb_substr($name, 0, 120) . '.mp3';
}
?>
