<?php

/*
|--------------------------------------------------------------------------
| TELEGRAM MUSIC BOT - SINGLE FILE
| PHP 8.2+
|--------------------------------------------------------------------------
*/

const BOT_TOKEN = '8817347840:AAFpsNeTkzHqjnlqkV_18AjMEgIX-FXHmQo';

const ADMIN_ID = 8897821078;

const MUSIC_API =
    'https://music-search-api-frnb.vercel.app/search';

const REQUIRED_STARS = 5;

const DATA_DIR = __DIR__ . '/music_data';


// ============================================================================
// STORAGE
// ============================================================================

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

function jsonFile(string $name): string
{
    return DATA_DIR . '/' . $name . '.json';
}

function readData(string $name): array
{
    $file = jsonFile($name);

    if (!file_exists($file)) {
        return [];
    }

    $data = json_decode(
        file_get_contents($file),
        true
    );

    return is_array($data) ? $data : [];
}

function writeData(
    string $name,
    array $data
): void {

    file_put_contents(
        jsonFile($name),
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
// TELEGRAM
// ============================================================================

function tg(
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
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);

    curl_close($ch);

    return json_decode(
        $response ?: '',
        true
    ) ?: [];
}


function sendMessage(
    int|string $chatId,
    string $text,
    ?array $keyboard = null
): array {

    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];

    if ($keyboard) {
        $data['reply_markup'] =
            json_encode([
                'inline_keyboard' => $keyboard
            ]);
    }

    return tg(
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

    if ($keyboard) {
        $data['reply_markup'] =
            json_encode([
                'inline_keyboard' => $keyboard
            ]);
    }

    return tg(
        'sendPhoto',
        $data
    );
}


function sendAudio(
    int|string $chatId,
    string $audio,
    string $title,
    string $artist,
    int $duration = 0
): array {

    $data = [
        'chat_id' => $chatId,
        'audio' => $audio,
        'title' => $title,
        'performer' => $artist
    ];

    if ($duration > 0) {
        $data['duration'] = $duration;
    }

    return tg(
        'sendAudio',
        $data
    );
}


// ============================================================================
// USER ACCESS
// ============================================================================

function isAdmin(
    int|string $id
): bool {

    return (string)$id ===
           (string)ADMIN_ID;
}


function hasAccess(
    int|string $id
): bool {

    if (isAdmin($id)) {
        return true;
    }

    $users =
        readData('users');

    return
        !empty(
            $users[(string)$id]['paid']
        );
}


function saveUser(
    array $user
): void {

    $users =
        readData('users');

    $id =
        (string)$user['id'];

    if (!isset($users[$id])) {

        $users[$id] = [
            'id' => $user['id'],
            'username' =>
                $user['username'] ?? '',
            'first_name' =>
                $user['first_name'] ?? '',
            'paid' => false,
            'created_at' => time()
        ];
    }

    $users[$id]['last_seen'] =
        time();

    writeData(
        'users',
        $users
    );
}


function grantAccess(
    int|string $id
): void {

    $users =
        readData('users');

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

    writeData(
        'users',
        $users
    );
}


// ============================================================================
// TELEGRAM STARS
// ============================================================================

function sendPremiumInvoice(
    int|string $chatId
): void {

    tg(
        'sendInvoice',
        [
            'chat_id' => $chatId,

            'title' =>
                'Music Bot Premium',

            'description' =>
                'Unlock music search, playback and MP3 downloads.',

            'payload' =>
                'premium_' .
                $chatId .
                '_' .
                time(),

            'provider_token' => '',

            'currency' => 'XTR',

            'prices' => json_encode([
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

    tg(
        'answerPreCheckoutQuery',
        [
            'pre_checkout_query_id' =>
                $query['id'],

            'ok' => true
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
        "✅ <b>Payment successful!</b>\n\n" .
        "⭐ Premium unlocked.\n\n" .
        "Now send any song name."
    );
}


// ============================================================================
// MUSIC API
// ============================================================================

function musicApi(
    string $query
): array {

    $url =
        MUSIC_API .
        '?song=' .
        urlencode($query);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,

        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);

    $response =
        curl_exec($ch);

    curl_close($ch);

    if (!$response) {
        return [];
    }

    $data =
        json_decode(
            $response,
            true
        );

    return is_array($data)
        ? $data
        : [];
}


// ============================================================================
// SONG NORMALIZATION
// ============================================================================

function normalizeSong(
    array $s
): array {

    return [

        'title' =>
            (string)(
                $s['title']
                ?? $s['name']
                ?? $s['song']
                ?? 'Unknown Song'
            ),

        'artist' =>
            (string)(
                $s['artist']
                ?? $s['author']
                ?? $s['performer']
                ?? 'Unknown Artist'
            ),

        'thumbnail' =>
            (string)(
                $s['thumbnail']
                ?? $s['image']
                ?? $s['thumb']
                ?? $s['cover']
                ?? ''
            ),

        'audio' =>
            (string)(
                $s['audio_url']
                ?? $s['audio']
                ?? $s['download_url']
                ?? $s['download']
                ?? $s['url']
                ?? ''
            ),

        'duration' =>
            durationSeconds(
                $s['duration']
                ?? $s['length']
                ?? 0
            )
    ];
}


function durationSeconds(
    mixed $value
): int {

    if (is_numeric($value)) {
        return (int)$value;
    }

    if (
        is_string($value) &&
        preg_match(
            '/^(\d+):(\d{1,2})$/',
            $value,
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
// RESULTS EXTRACTION
// ============================================================================

function resultsFromApi(
    array $data
): array {

    foreach (
        ['results', 'data', 'songs', 'items']
        as $key
    ) {

        if (
            isset($data[$key]) &&
            is_array($data[$key])
        ) {
            return $data[$key];
        }
    }

    return array_is_list($data)
        ? $data
        : [];
}


// ============================================================================
// SEARCH CACHE
// ============================================================================

function saveResults(
    int|string $chatId,
    array $songs
): void {

    $cache =
        readData('results');

    $cache[(string)$chatId] =
        $songs;

    writeData(
        'results',
        $cache
    );
}


function getSong(
    int|string $chatId,
    int $index
): ?array {

    $cache =
        readData('results');

    return
        $cache[(string)$chatId][$index]
        ?? null;
}


// ============================================================================
// SEARCH
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

    $api =
        musicApi($query);

    $raw =
        resultsFromApi($api);

    if (!$raw) {

        sendMessage(
            $chatId,
            "❌ No results found."
        );

        return;
    }

    $songs = [];

    foreach ($raw as $item) {

        if (!is_array($item)) {
            continue;
        }

        $song =
            normalizeSong($item);

        $songs[] =
            $song;

        if (count($songs) >= 10) {
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

            "👤 " .
            htmlspecialchars(
                $song['artist']
            );

        if ($song['duration'] > 0) {

            $caption .=
                "\n⏱️ " .
                gmdate(
                    'i:s',
                    $song['duration']
                );
        }

        /*
         * Android companion APK can use
         * this audio URL for mini-player
         * streaming.
         */

        $playData =
            'play:' . $i;

        $mp3Data =
            'mp3:' . $i;

        $keyboard = [

            [
                [
                    'text' =>
                        '▶️ Play',
                    'callback_data' =>
                        $playData
                ],

                [
                    'text' =>
                        '📥 MP3',
                    'callback_data' =>
                        $mp3Data
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

        if (
            $song['thumbnail'] !== ''
        ) {

            sendPhoto(
                $chatId,
                $song['thumbnail'],
                $caption,
                $keyboard
            );

        } else {

            sendMessage(
                $chatId,
                $caption,
                $keyboard
            );
        }
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
            "❌ Search result expired."
        );

        return;
    }

    if (
        empty($song['audio'])
    ) {

        sendMessage(
            $chatId,
            "❌ Audio URL is missing from API."
        );

        return;
    }

    /*
     * Telegram audio playback.
     */

    $result =
        sendAudio(
            $chatId,
            $song['audio'],
            $song['title'],
            $song['artist'],
            $song['duration']
        );

    if (
        empty($result['ok'])
    ) {

        sendMessage(
            $chatId,
            "❌ Telegram could not fetch this audio."
        );
    }
}


// ============================================================================
// MP3
// ============================================================================

function downloadMp3(
    int|string $chatId,
    int $index
): void {

    /*
     * Same audio URL can be delivered
     * as Telegram Audio.
     *
     * Filename/title is derived from song
     * metadata by Telegram.
     */

    playSong(
        $chatId,
        $index
    );
}


// ============================================================================
// CALLBACKS
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

    tg(
        'answerCallbackQuery',
        [
            'callback_query_id' =>
                $callback['id']
        ]
    );

    if ($data === 'pay') {

        sendPremiumInvoice(
            $chatId
        );

        return;
    }

    if (
        !hasAccess($chatId)
    ) {

        sendPremiumInvoice(
            $chatId
        );

        return;
    }

    if (
        preg_match(
            '/^(play|mp3):(\d+)$/',
            $data,
            $m
        )
    ) {

        $action =
            $m[1];

        $index =
            (int)$m[2];

        if ($action === 'play') {

            playSong(
                $chatId,
                $index
            );

        } else {

            downloadMp3(
                $chatId,
                $index
            );
        }
    }
}


// ============================================================================
// MESSAGE HANDLER
// ============================================================================

function messageHandler(
    array $message
): void {

    $chatId =
        $message['chat']['id'];

    $user =
        $message['from']
        ?? [];

    saveUser(
        $user
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

    if ($text === '') {
        return;
    }

    if ($text === '/start') {

        if (
            !hasAccess($chatId)
        ) {

            sendPhoto(
                $chatId,

                'https://placehold.co/800x500/jpg?text=😂+Meme',

                "😂 <b>Wait...</b>\n\n" .
                "Music search unlock karne ke liye " .
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

        } else {

            sendMessage(
                $chatId,
                "🎵 <b>Music Bot</b>\n\n" .
                "Song name bhejo."
            );
        }

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
            "🎨 Thumbnail\n" .
            "👤 Artist\n" .
            "⏭️ Queue"
        );

        return;
    }

    if (
        $text === '/admin' &&
        isAdmin($chatId)
    ) {

        $users =
            readData('users');

        sendMessage(
            $chatId,
            "👑 <b>Admin</b>\n\n" .
            "Users: " .
            count($users)
        );

        return;
    }

    if (
        !hasAccess($chatId)
    ) {

        sendPremiumInvoice(
            $chatId
        );

        return;
    }

    searchMusic(
        $chatId,
        $text
    );
}


// ============================================================================
// UPDATE
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

    if (is_array($update)) {

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
        'Telegram Music Bot Online';
}
