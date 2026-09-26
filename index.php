<?php

/*
|--------------------------------------------------------------------------
| TELEGRAM MUSIC BOT
| Single index.php
| PHP 8.2+
| Requires: cURL + FFmpeg
|--------------------------------------------------------------------------
*/

const BOT_TOKEN = '8817347840:AAFpsNeTkzHqjnlqkV_18AjMEgIX-FXHmQo';

const ADMIN_ID = 8897821078;

const MUSIC_API =
    'https://music-search-api-frnb.vercel.app/search';

const REQUIRED_STARS = 5;

const FFMPEG = '/usr/bin/ffmpeg';

const TEMP_DIR = __DIR__ . '/music_temp';


// ============================================================================
// INIT
// ============================================================================

if (!is_dir(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0755, true);
}


// ============================================================================
// JSON STORAGE
// ============================================================================

function storage(string $name): string
{
    return __DIR__ . '/music_' . $name . '.json';
}

function readStore(string $name): array
{
    $file = storage($name);

    if (!file_exists($file)) {
        return [];
    }

    $data = json_decode(
        file_get_contents($file),
        true
    );

    return is_array($data) ? $data : [];
}

function writeStore(
    string $name,
    array $data
): void {

    file_put_contents(
        storage($name),
        json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ),
        LOCK_EX
    );
}


// ============================================================================
// TELEGRAM API
// ============================================================================

function telegram(
    string $method,
    array $data = []
): array {

    $url =
        'https://api.telegram.org/bot' .
        BOT_TOKEN .
        '/' .
        $method;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);

    curl_close($ch);

    $json = json_decode(
        $response ?: '',
        true
    );

    return is_array($json)
        ? $json
        : [];
}


// ============================================================================
// TELEGRAM MESSAGE
// ============================================================================

function sendMessage(
    int|string $chatId,
    string $text,
    ?array $keyboard = null
): array {

    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {

        $data['reply_markup'] =
            json_encode([
                'inline_keyboard' => $keyboard
            ]);
    }

    return telegram(
        'sendMessage',
        $data
    );
}


function sendPhoto(
    int|string $chatId,
    string $photo,
    string $caption,
    ?array $keyboard = null
): array {

    $data = [
        'chat_id' => $chatId,
        'photo' => $photo,
        'caption' => $caption,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {

        $data['reply_markup'] =
            json_encode([
                'inline_keyboard' => $keyboard
            ]);
    }

    return telegram(
        'sendPhoto',
        $data
    );
}


// ============================================================================
// USER ACCESS
// ============================================================================

function isAdmin(
    int|string $id
): bool {

    return
        (string)$id ===
        (string)ADMIN_ID;
}


function hasAccess(
    int|string $id
): bool {

    if (isAdmin($id)) {
        return true;
    }

    $users =
        readStore('users');

    return
        !empty(
            $users[(string)$id]['paid']
        );
}


function saveUser(
    array $user
): void {

    $users =
        readStore('users');

    $id =
        (string)$user['id'];

    if (!isset($users[$id])) {

        $users[$id] = [
            'id' =>
                $user['id'],

            'username' =>
                $user['username'] ?? '',

            'first_name' =>
                $user['first_name'] ?? '',

            'paid' =>
                false,

            'created_at' =>
                time()
        ];
    }

    $users[$id]['last_seen'] =
        time();

    writeStore(
        'users',
        $users
    );
}


function grantAccess(
    int|string $id
): void {

    $users =
        readStore('users');

    $key =
        (string)$id;

    if (!isset($users[$key])) {

        $users[$key] = [
            'id' => $id
        ];
    }

    $users[$key]['paid'] =
        true;

    $users[$key]['paid_at'] =
        time();

    writeStore(
        'users',
        $users
    );
}


// ============================================================================
// TELEGRAM STARS
// ============================================================================

function sendStarsInvoice(
    int|string $chatId
): void {

    telegram(
        'sendInvoice',
        [

            'chat_id' =>
                $chatId,

            'title' =>
                'Music Bot Premium',

            'description' =>
                'Unlock music search, playback and MP3 access.',

            'payload' =>
                'music_premium_' .
                $chatId .
                '_' .
                time(),

            'provider_token' =>
                '',

            'currency' =>
                'XTR',

            'prices' =>
                json_encode([
                    [
                        'label' =>
                            'Premium Access',

                        'amount' =>
                            REQUIRED_STARS
                    ]
                ])
        ]
    );
}


function handlePreCheckout(
    array $query
): void {

    telegram(
        'answerPreCheckoutQuery',
        [

            'pre_checkout_query_id' =>
                $query['id'],

            'ok' =>
                true
        ]
    );
}


function handlePayment(
    array $message
): void {

    $chatId =
        $message['chat']['id'];

    grantAccess(
        $chatId
    );

    sendMessage(
        $chatId,

        "✅ <b>Payment Successful</b>\n\n" .
        "⭐ Premium access unlocked.\n\n" .
        "Send a song name."
    );
}


// ============================================================================
// MUSIC API
// ============================================================================

function searchAPI(
    string $query
): array {

    $url =
        MUSIC_API .
        '?song=' .
        urlencode($query);

    $ch = curl_init();

    curl_setopt_array($ch, [

        CURLOPT_URL =>
            $url,

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_FOLLOWLOCATION =>
            true,

        CURLOPT_TIMEOUT =>
            15,

        CURLOPT_CONNECTTIMEOUT =>
            8,

        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);

    $response =
        curl_exec($ch);

    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);

    if (
        !$response ||
        $httpCode < 200 ||
        $httpCode >= 300
    ) {
        return [];
    }

    $json =
        json_decode(
            $response,
            true
        );

    return
        is_array($json)
            ? $json
            : [];
}


// ============================================================================
// RESULT NORMALIZER
// ============================================================================

function normalizeSong(
    array $song
): array {

    /*
     * IMPORTANT:
     * API uses "artists", not "artist".
     */

    $title =
        $song['title']
        ?? 'Unknown Song';

    $artists =
        $song['artists']
        ?? 'Unknown Artist';

    $album =
        $song['album']
        ?? '';

    $duration =
        $song['duration']
        ?? '00:00';

    $downloadURL =
        $song['download_url']
        ?? '';

    return [

        'title' =>
            trim(
                html_entity_decode(
                    (string)$title,
                    ENT_QUOTES |
                    ENT_HTML5,
                    'UTF-8'
                )
            ),

        'artist' =>
            trim(
                html_entity_decode(
                    (string)$artists,
                    ENT_QUOTES |
                    ENT_HTML5,
                    'UTF-8'
                )
            ),

        'album' =>
            trim(
                html_entity_decode(
                    (string)$album,
                    ENT_QUOTES |
                    ENT_HTML5,
                    'UTF-8'
                )
            ),

        'duration' =>
            (string)$duration,

        'download_url' =>
            (string)$downloadURL,

        /*
         * Your current API does NOT provide
         * thumbnail/artwork.
         */
        'thumbnail' =>
            ''
    ];
}


// ============================================================================
// GET SONG RESULTS
// ============================================================================

function extractResults(
    array $response
): array {

    if (
        isset($response['results']) &&
        is_array($response['results'])
    ) {
        return $response['results'];
    }

    return [];
}


// ============================================================================
// CACHE
// ============================================================================

function saveResults(
    int|string $chatId,
    array $songs
): void {

    $cache =
        readStore('results');

    $cache[(string)$chatId] =
        $songs;

    writeStore(
        'results',
        $cache
    );
}


function getSong(
    int|string $chatId,
    int $index
): ?array {

    $cache =
        readStore('results');

    return
        $cache[(string)$chatId][$index]
        ?? null;
}


// ============================================================================
// DURATION
// ============================================================================

function durationSeconds(
    string $duration
): int {

    if (
        preg_match(
            '/^(\d+):(\d{1,2})$/',
            trim($duration),
            $m
        )
    ) {

        return
            ((int)$m[1] * 60) +
            (int)$m[2];
    }

    return 0;
}


// ============================================================================
// SAFE FILE NAME
// ============================================================================

function safeFileName(
    string $name
): string {

    $name =
        preg_replace(
            '/[\\\\\/:*?"<>|]+/',
            '',
            $name
        );

    $name =
        preg_replace(
            '/\s+/',
            ' ',
            $name
        );

    return trim($name);
}


// ============================================================================
// DOWNLOAD SOURCE
// ============================================================================

function downloadSource(
    string $url,
    string $file
): bool {

    $fp =
        fopen(
            $file,
            'wb'
        );

    if (!$fp) {
        return false;
    }

    $ch =
        curl_init($url);

    curl_setopt_array($ch, [

        CURLOPT_FILE =>
            $fp,

        CURLOPT_FOLLOWLOCATION =>
            true,

        CURLOPT_TIMEOUT =>
            120,

        CURLOPT_CONNECTTIMEOUT =>
            15,

        CURLOPT_SSL_VERIFYPEER =>
            true,

        CURLOPT_USERAGENT =>
            'Mozilla/5.0'
    ]);

    $ok =
        curl_exec($ch);

    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

    curl_close($ch);

    fclose($fp);

    if (
        !$ok ||
        $httpCode < 200 ||
        $httpCode >= 300
    ) {

        @unlink($file);

        return false;
    }

    return
        file_exists($file) &&
        filesize($file) > 0;
}


// ============================================================================
// CONVERT TO MP3
// ============================================================================

function convertToMP3(
    string $source,
    string $output
): bool {

    /*
     * 128 kbps MP3
     *
     * This is actual transcoding.
     * It does NOT merely rename .mp4 to .mp3.
     */

    $command =
        escapeshellarg(FFMPEG) .
        ' -y' .
        ' -i ' .
        escapeshellarg($source) .
        ' -vn' .
        ' -codec:a libmp3lame' .
        ' -b:a 128k' .
        ' -map_metadata -1' .
        ' ' .
        escapeshellarg($output) .
        ' 2>&1';

    exec(
        $command,
        $outputLines,
        $exitCode
    );

    return
        $exitCode === 0 &&
        file_exists($output) &&
        filesize($output) > 0;
}


// ============================================================================
// CREATE MP3
// ============================================================================

function prepareMP3(
    array $song
): ?string {

    if (
        empty($song['download_url'])
    ) {
        return null;
    }

    $id =
        bin2hex(
            random_bytes(8)
        );

    $source =
        TEMP_DIR .
        '/' .
        $id .
        '.mp4';

    $mp3 =
        TEMP_DIR .
        '/' .
        $id .
        '.mp3';

    /*
     * Download source.
     */

    if (
        !downloadSource(
            $song['download_url'],
            $source
        )
    ) {
        return null;
    }

    /*
     * Convert to actual MP3.
     */

    $converted =
        convertToMP3(
            $source,
            $mp3
        );

    @unlink($source);

    if (!$converted) {
        @unlink($mp3);
        return null;
    }

    return $mp3;
}


// ============================================================================
// SEND ACTUAL MP3
// ============================================================================

function sendMP3(
    int|string $chatId,
    array $song
): void {

    sendMessage(
        $chatId,
        "⏳ <b>Preparing MP3...</b>\n\n" .
        htmlspecialchars(
            $song['title']
        )
    );

    $mp3 =
        prepareMP3(
            $song
        );

    if (!$mp3) {

        sendMessage(
            $chatId,
            "❌ MP3 conversion failed.\n\n" .
            "Check FFmpeg and source URL."
        );

        return;
    }

    $fileName =
        safeFileName(
            $song['title'] .
            ' - ' .
            $song['artist'] .
            '.mp3'
        );

    $duration =
        durationSeconds(
            $song['duration']
        );

    $curlFile =
        new CURLFile(
            $mp3,
            'audio/mpeg',
            $fileName
        );

    $data = [

        'chat_id' =>
            $chatId,

        'audio' =>
            $curlFile,

        'title' =>
            $song['title'],

        'performer' =>
            $song['artist'],

        'caption' =>
            "🎵 " .
            $song['title'] .
            "\n👤 " .
            $song['artist']
    ];

    if ($duration > 0) {

        $data['duration'] =
            $duration;
    }

    $result =
        telegram(
            'sendAudio',
            $data
        );

    /*
     * Delete temporary MP3
     * after Telegram receives it.
     */

    @unlink($mp3);

    if (
        empty($result['ok'])
    ) {

        sendMessage(
            $chatId,
            "❌ Telegram could not upload the MP3."
        );
    }
}


// ============================================================================
// PLAY
// ============================================================================

function playSong(
    int|string $chatId,
    int $index
): void {

    $song =
        getSong(
            $chatId,
            $index
        );

    if (!$song) {

        sendMessage(
            $chatId,
            "❌ Result expired. Search again."
        );

        return;
    }

    /*
     * We convert first so Telegram receives
     * a real MP3 with proper filename.
     */

    sendMP3(
        $chatId,
        $song
    );
}


// ============================================================================
// SEARCH UI
// ============================================================================

function searchMusic(
    int|string $chatId,
    string $query
): void {

    sendMessage(
        $chatId,

        "🔎 Searching <b>" .
        htmlspecialchars($query) .
        "</b>..."
    );

    $response =
        searchAPI($query);

    $raw =
        extractResults(
            $response
        );

    if (!$raw) {

        sendMessage(
            $chatId,
            "❌ <b>No results found.</b>"
        );

        return;
    }

    $songs = [];

    foreach (
        $raw as $item
    ) {

        if (!is_array($item)) {
            continue;
        }

        $song =
            normalizeSong(
                $item
            );

        if (
            $song['download_url'] === ''
        ) {
            continue;
        }

        $songs[] =
            $song;

        if (
            count($songs) >= 10
        ) {
            break;
        }
    }

    saveResults(
        $chatId,
        $songs
    );

    foreach (
        $songs as $i => $song
    ) {

        $caption =
            "🎵 <b>" .
            htmlspecialchars(
                $song['title']
            ) .
            "</b>\n\n" .

            "👤 <b>" .
            htmlspecialchars(
                $song['artist']
            ) .
            "</b>\n" .

            "💿 " .
            htmlspecialchars(
                $song['album']
            ) .
            "\n" .

            "⏱️ " .
            htmlspecialchars(
                $song['duration']
            );

        $keyboard = [

            [
                [
                    'text' =>
                        '▶️ Play',
                    'callback_data' =>
                        'play:' . $i
                ],

                [
                    'text' =>
                        '📥 MP3',
                    'callback_data' =>
                        'mp3:' . $i
                ]
            ],

            [
                [
                    'text' =>
                        '❤️ Save',
                    'callback_data' =>
                        'fav:' . $i
                ],

                [
                    'text' =>
                        '➕ Queue',
                    'callback_data' =>
                        'queue:' . $i
                ]
            ]
        ];

        /*
         * Current API has no thumbnail.
         * Therefore do NOT send a fake image.
         */

        sendMessage(
            $chatId,
            $caption,
            $keyboard
        );
    }
}


// ============================================================================
// FAVORITE
// ============================================================================

function saveFavorite(
    int|string $chatId,
    int $index
): void {

    $song =
        getSong(
            $chatId,
            $index
        );

    if (!$song) {
        return;
    }

    $favorites =
        readStore('favorites');

    $id =
        (string)$chatId;

    if (
        !isset(
            $favorites[$id]
        )
    ) {
        $favorites[$id] = [];
    }

    $favorites[$id][] =
        $song;

    writeStore(
        'favorites',
        $favorites
    );

    sendMessage(
        $chatId,
        "❤️ <b>Saved</b>\n\n" .
        htmlspecialchars(
            $song['title']
        )
    );
}


// ============================================================================
// QUEUE
// ============================================================================

function addQueue(
    int|string $chatId,
    int $index
): void {

    $song =
        getSong(
            $chatId,
            $index
        );

    if (!$song) {
        return;
    }

    $queues =
        readStore('queues');

    $id =
        (string)$chatId;

    if (
        !isset($queues[$id])
    ) {
        $queues[$id] = [];
    }

    $queues[$id][] =
        $song;

    writeStore(
        'queues',
        $queues
    );

    sendMessage(
        $chatId,
        "➕ <b>Added to Queue</b>\n\n" .
        htmlspecialchars(
            $song['title']
        )
    );
}


// ============================================================================
// PREMIUM
// ============================================================================

function premiumGate(
    int|string $chatId
): bool {

    if (
        hasAccess($chatId)
    ) {
        return true;
    }

    sendMessage(
        $chatId,

        "😂 <b>Premium Required</b>\n\n" .
        "Music search ke liye " .
        "<b>5 Telegram Stars</b> required hain.",

        [
            [
                [
                    'text' =>
                        '⭐ Pay 5 Stars',
                    'callback_data' =>
                        'pay'
                ]
            ]
        ]
    );

    return false;
}


// ============================================================================
// CALLBACK
// ============================================================================

function callbackHandler(
    array $callback
): void {

    $chatId =
        $callback['message']['chat']['id']
        ?? 0;

    $data =
        $callback['data']
        ?? '';

    telegram(
        'answerCallbackQuery',
        [
            'callback_query_id' =>
                $callback['id']
        ]
    );

    if (
        $data === 'pay'
    ) {

        sendStarsInvoice(
            $chatId
        );

        return;
    }

    if (
        !premiumGate($chatId)
    ) {
        return;
    }

    if (
        preg_match(
            '/^(play|mp3|fav|queue):(\d+)$/',
            $data,
            $match
        )
    ) {

        $action =
            $match[1];

        $index =
            (int)$match[2];

        switch ($action) {

            case 'play':

                playSong(
                    $chatId,
                    $index
                );

                break;

            case 'mp3':

                sendMP3(
                    $chatId,
                    getSong(
                        $chatId,
                        $index
                    ) ?? []
                );

                break;

            case 'fav':

                saveFavorite(
                    $chatId,
                    $index
                );

                break;

            case 'queue':

                addQueue(
                    $chatId,
                    $index
                );

                break;
        }
    }
}


// ============================================================================
// MESSAGE
// ============================================================================

function messageHandler(
    array $message
): void {

    $chatId =
        $message['chat']['id'];

    saveUser(
        $message['from']
        ?? []
    );

    if (
        isset(
            $message['successful_payment']
        )
    ) {

        handlePayment(
            $message
        );

        return;
    }

    $text =
        trim(
            $message['text']
            ?? ''
        );

    if (
        $text === '/start'
    ) {

        if (
            !premiumGate($chatId)
        ) {
            return;
        }

        sendMessage(
            $chatId,

            "🎵 <b>Music Bot</b>\n\n" .
            "Song name bhejo.\n\n" .
            "Example:\n" .
            "<code>Chandni</code>"
        );

        return;
    }

    if (
        $text === '/help'
    ) {

        sendMessage(
            $chatId,

            "🎵 <b>Music Bot</b>\n\n" .
            "🔎 Search\n" .
            "▶️ Play\n" .
            "📥 MP3\n" .
            "❤️ Save\n" .
            "➕ Queue"
        );

        return;
    }

    if (
        $text === '/admin' &&
        isAdmin($chatId)
    ) {

        $users =
            readStore('users');

        sendMessage(
            $chatId,

            "👑 <b>Admin Panel</b>\n\n" .
            "Users: " .
            count($users)
        );

        return;
    }

    if (
        !premiumGate($chatId)
    ) {
        return;
    }

    searchMusic(
        $chatId,
        $text
    );
}


// ============================================================================
// UPDATE ROUTER
// ============================================================================

function processUpdate(
    array $update
): void {

    if (
        isset(
            $update['pre_checkout_query']
        )
    ) {

        handlePreCheckout(
            $update['pre_checkout_query']
        );

        return;
    }

    if (
        isset(
            $update['message']
        )
    ) {

        messageHandler(
            $update['message']
        );

        return;
    }

    if (
        isset(
            $update['callback_query']
        )
    ) {

        callbackHandler(
            $update['callback_query']
        );
    }
}


// ============================================================================
// WEBHOOK
// ============================================================================

$input =
    file_get_contents(
        'php://input'
    );

if ($input) {

    $update =
        json_decode(
            $input,
            true
        );

    if (
        is_array($update)
    ) {

        processUpdate(
            $update
        );
    }
}


// ============================================================================
// HEALTH CHECK
// ============================================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
) {

    echo
        'Telegram Music Bot is Online';
}
