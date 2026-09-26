<?php
/**
 * Telegram Music Bot
 * Single-file PHP 8.2+
 *
 * IMPORTANT:
 * Put your NEW BotFather token here after revoking
 * the token that was exposed in chat.
 */

// ======================================================
// CONFIG
// ======================================================

const BOT_TOKEN = '8817347840:AAFpsNeTkzHqjnlqkV_18AjMEgIX-FXHmQo';

const ADMIN_ID = 8897821078; // <-- Your Telegram numeric ID

const MUSIC_API =
    'https://music-search-api-frnb.vercel.app/search';

const REQUIRED_STARS = 5;

// Funny image shown to unpaid users.
// Replace with your own HTTPS JPG/PNG if desired.
const MEME_IMAGE =
    'https://placehold.co/800x500/jpg?text=Pay+5+Stars';


// ======================================================
// BASIC STORAGE
// ======================================================

function dataDir(): string
{
    $dir = __DIR__ . '/bot_data';

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function usersFile(): string
{
    return dataDir() . '/users.json';
}

function searchesFile(): string
{
    return dataDir() . '/searches.json';
}

function favoritesFile(): string
{
    return dataDir() . '/favorites.json';
}

function queuesFile(): string
{
    return dataDir() . '/queues.json';
}

function loadJson(string $file, array $default = []): array
{
    if (!file_exists($file)) {
        return $default;
    }

    $data = json_decode(
        file_get_contents($file),
        true
    );

    return is_array($data) ? $data : $default;
}

function saveJson(string $file, array $data): void
{
    file_put_contents(
        $file,
        json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ),
        LOCK_EX
    );
}


// ======================================================
// USER ACCESS
// ======================================================

function isAdmin(int|string $userId): bool
{
    return (string)$userId === (string)ADMIN_ID;
}

function userHasAccess(int|string $userId): bool
{
    if (isAdmin($userId)) {
        return true;
    }

    $users = loadJson(usersFile());

    return !empty($users[(string)$userId]['paid']);
}

function saveUser(array $user): void
{
    $users = loadJson(usersFile());

    $id = (string)$user['id'];

    if (!isset($users[$id])) {
        $users[$id] = [
            'id' => $user['id'],
            'username' => $user['username'] ?? '',
            'first_name' => $user['first_name'] ?? '',
            'paid' => false,
            'created_at' => time()
        ];
    }

    $users[$id]['username'] =
        $user['username'] ?? $users[$id]['username'];

    $users[$id]['first_name'] =
        $user['first_name'] ?? $users[$id]['first_name'];

    $users[$id]['last_seen'] = time();

    saveJson(usersFile(), $users);
}

function grantAccess(int|string $userId): void
{
    $users = loadJson(usersFile());

    $id = (string)$userId;

    if (!isset($users[$id])) {
        $users[$id] = [
            'id' => $userId
        ];
    }

    $users[$id]['paid'] = true;
    $users[$id]['paid_at'] = time();

    saveJson(usersFile(), $users);
}


// ======================================================
// TELEGRAM API
// ======================================================

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
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 30,
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


// ======================================================
// MESSAGES
// ======================================================

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

    if ($keyboard !== null) {
        $data['reply_markup'] = json_encode([
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
    string $caption = '',
    ?array $keyboard = null
): array {

    $data = [
        'chat_id' => $chatId,
        'photo' => $photo,
        'caption' => $caption,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] = json_encode([
            'inline_keyboard' => $keyboard
        ]);
    }

    return telegram(
        'sendPhoto',
        $data
    );
}

function sendAudio(
    int|string $chatId,
    string $audio,
    string $title = '',
    string $artist = '',
    int $duration = 0,
    string $thumbnail = ''
): array {

    $data = [
        'chat_id' => $chatId,
        'audio' => $audio,
        'title' => $title,
        'performer' => $artist,
        'parse_mode' => 'HTML'
    ];

    if ($duration > 0) {
        $data['duration'] = $duration;
    }

    if ($thumbnail !== '') {
        $data['thumbnail'] = $thumbnail;
    }

    return telegram(
        'sendAudio',
        $data
    );
}

function answerCallback(
    string $callbackId,
    string $text = '',
    bool $alert = false
): void {

    telegram(
        'answerCallbackQuery',
        [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $alert
        ]
    );
}


// ======================================================
// TELEGRAM STARS PAYMENT
// ======================================================

function sendStarsInvoice(
    int|string $chatId
): void {

    telegram(
        'sendInvoice',
        [
            'chat_id' => $chatId,

            'title' =>
                'Music Bot Premium Access',

            'description' =>
                'Unlock music search, playback and MP3 features.',

            'payload' =>
                'music_access_' . $chatId . '_' . time(),

            'provider_token' => '',

            'currency' => 'XTR',

            'prices' => json_encode([
                [
                    'label' => 'Premium Access',
                    'amount' => REQUIRED_STARS
                ]
            ]),

            'start_parameter' =>
                'music-premium'
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

            'ok' => true
        ]
    );
}

function handleSuccessfulPayment(
    array $message
): void {

    $chatId =
        $message['chat']['id'];

    $payment =
        $message['successful_payment'];

    $chargeId =
        $payment['telegram_payment_charge_id']
        ?? '';

    grantAccess($chatId);

    sendMessage(
        $chatId,
        "✅ <b>Payment Successful</b>\n\n" .
        "⭐ Stars received: <b>" .
        REQUIRED_STARS .
        "</b>\n\n" .
        "🎵 Your Music Bot access is now unlocked.\n\n" .
        "Send a song name to search."
    );
}


// ======================================================
// MUSIC API
// ======================================================

function apiRequest(
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

    $response = curl_exec($ch);

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

    $json = json_decode(
        $response,
        true
    );

    return is_array($json)
        ? $json
        : [];
}


// ======================================================
// NORMALIZE API RESULT
// ======================================================

function normalizeSong(
    array $song
): array {

    $title =
        $song['title']
        ?? $song['name']
        ?? $song['song']
        ?? $song['track']
        ?? 'Unknown Song';

    $artist =
        $song['artist']
        ?? $song['author']
        ?? $song['performer']
        ?? $song['artists']
        ?? 'Unknown Artist';

    $thumbnail =
        $song['thumbnail']
        ?? $song['thumb']
        ?? $song['image']
        ?? $song['cover']
        ?? $song['artwork']
        ?? '';

    $audio =
        $song['audio_url']
        ?? $song['audio']
        ?? $song['download_url']
        ?? $song['download']
        ?? $song['url']
        ?? '';

    $duration =
        $song['duration']
        ?? $song['length']
        ?? 0;

    return [
        'title' => (string)$title,
        'artist' => is_array($artist)
            ? implode(', ', $artist)
            : (string)$artist,
        'thumbnail' => (string)$thumbnail,
        'audio' => (string)$audio,
        'duration' => parseDuration($duration)
    ];
}

function parseDuration(
    mixed $duration
): int {

    if (is_numeric($duration)) {
        return (int)$duration;
    }

    if (
        is_string($duration) &&
        preg_match(
            '/^(\d+):(\d{1,2})$/',
            $duration,
            $m
        )
    ) {
        return
            ((int)$m[1] * 60) +
            (int)$m[2];
    }

    return 0;
}


// ======================================================
// EXTRACT RESULTS
// ======================================================

function extractResults(
    array $response
): array {

    $possible = [
        $response['results'] ?? null,
        $response['data'] ?? null,
        $response['songs'] ?? null,
        $response['items'] ?? null
    ];

    foreach ($possible as $list) {

        if (is_array($list)) {
            return $list;
        }
    }

    if (array_is_list($response)) {
        return $response;
    }

    return [];
}


// ======================================================
// SAVE SEARCH RESULTS
// ======================================================

function saveSearch(
    int|string $userId,
    array $songs
): void {

    $searches =
        loadJson(searchesFile());

    $searches[(string)$userId] =
        $songs;

    saveJson(
        searchesFile(),
        $searches
    );
}

function getSearchSong(
    int|string $userId,
    int $index
): ?array {

    $searches =
        loadJson(searchesFile());

    $songs =
        $searches[(string)$userId]
        ?? [];

    return $songs[$index] ?? null;
}


// ======================================================
// SEARCH
// ======================================================

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
        apiRequest($query);

    $rawResults =
        extractResults($response);

    if (!$rawResults) {

        sendMessage(
            $chatId,
            "❌ <b>No songs found.</b>\n\n" .
            "Try another song or artist."
        );

        return;
    }

    $songs = [];

    foreach ($rawResults as $rawSong) {

        if (!is_array($rawSong)) {
            continue;
        }

        $song =
            normalizeSong($rawSong);

        $songs[] =
            $song;

        if (count($songs) >= 10) {
            break;
        }
    }

    if (!$songs) {
        sendMessage(
            $chatId,
            "❌ API returned no usable songs."
        );

        return;
    }

    saveSearch(
        $chatId,
        $songs
    );

    foreach ($songs as $i => $song) {

        $keyboard = [
            [
                [
                    'text' => '▶️ Play',
                    'callback_data' =>
                        'play:' . $i
                ],
                [
                    'text' => '📥 MP3',
                    'callback_data' =>
                        'mp3:' . $i
                ]
            ],
            [
                [
                    'text' => '❤️ Save',
                    'callback_data' =>
                        'fav:' . $i
                ],
                [
                    'text' => '➕ Queue',
                    'callback_data' =>
                        'queue:' . $i
                ]
            ]
        ];

        $caption =
            "🎵 <b>" .
            htmlspecialchars($song['title']) .
            "</b>\n\n" .

            "👤 <b>Artist:</b> " .
            htmlspecialchars($song['artist']);

        if ($song['duration'] > 0) {

            $caption .=
                "\n⏱️ <b>Duration:</b> " .
                gmdate(
                    'i:s',
                    $song['duration']
                );
        }

        if ($song['thumbnail'] !== '') {

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


// ======================================================
// PLAY / MP3
// ======================================================

function playSong(
    int|string $chatId,
    int $index
): void {

    $song =
        getSearchSong(
            $chatId,
            $index
        );

    if (!$song) {

        sendMessage(
            $chatId,
            "❌ Search result expired. Search again."
        );

        return;
    }

    if ($song['audio'] === '') {

        sendMessage(
            $chatId,
            "❌ This API result does not contain " .
            "a playable audio URL."
        );

        return;
    }

    sendMessage(
        $chatId,
        "⏳ <b>Loading:</b> " .
        htmlspecialchars($song['title'])
    );

    $result =
        sendAudio(
            $chatId,
            $song['audio'],
            $song['title'],
            $song['artist'],
            $song['duration'],
            $song['thumbnail']
        );

    if (empty($result['ok'])) {

        sendMessage(
            $chatId,
            "❌ Telegram could not fetch the audio.\n\n" .
            "Check that the API audio URL is publicly " .
            "accessible and returns MP3/M4A."
        );
    }
}

function downloadMp3(
    int|string $chatId,
    int $index
): void {

    $song =
        getSearchSong(
            $chatId,
            $index
        );

    if (!$song) {

        sendMessage(
            $chatId,
            "❌ Search result expired. Search again."
        );

        return;
    }

    if ($song['audio'] === '') {

        sendMessage(
            $chatId,
            "❌ MP3 URL unavailable for this result."
        );

        return;
    }

    sendMessage(
        $chatId,
        "📥 <b>Preparing MP3...</b>\n\n" .
        htmlspecialchars($song['title'])
    );

    $result =
        sendAudio(
            $chatId,
            $song['audio'],
            $song['title'],
            $song['artist'],
            $song['duration'],
            $song['thumbnail']
        );

    if (empty($result['ok'])) {

        sendMessage(
            $chatId,
            "❌ MP3 could not be delivered."
        );
    }
}


// ======================================================
// FAVORITES
// ======================================================

function addFavorite(
    int|string $chatId,
    int $index
): void {

    $song =
        getSearchSong(
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

    $favorites =
        loadJson(favoritesFile());

    $id =
        (string)$chatId;

    if (!isset($favorites[$id])) {
        $favorites[$id] = [];
    }

    $favorites[$id][] =
        $song;

    saveJson(
        favoritesFile(),
        $favorites
    );

    sendMessage(
        $chatId,
        "❤️ <b>Added to Favorites</b>\n\n" .
        htmlspecialchars($song['title'])
    );
}


// ======================================================
// QUEUE
// ======================================================

function addQueue(
    int|string $chatId,
    int $index
): void {

    $song =
        getSearchSong(
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

    $queues =
        loadJson(queuesFile());

    $id =
        (string)$chatId;

    if (!isset($queues[$id])) {
        $queues[$id] = [];
    }

    $queues[$id][] =
        $song;

    saveJson(
        queuesFile(),
        $queues
    );

    sendMessage(
        $chatId,
        "➕ <b>Added to Queue</b>\n\n" .
        htmlspecialchars($song['title'])
    );
}


// ======================================================
// PREMIUM GATE
// ======================================================

function requirePremium(
    int|string $chatId
): bool {

    if (userHasAccess($chatId)) {
        return true;
    }

    $keyboard = [
        [
            [
                'text' =>
                    '⭐ Unlock for 5 Stars',
                'callback_data' =>
                    'pay'
            ]
        ]
    ];

    sendPhoto(
        $chatId,
        MEME_IMAGE,
        "😂 <b>Ruko zara...</b>\n\n" .
        "Music search use karne ke liye " .
        "<b>5 Telegram Stars</b> required hain.\n\n" .
        "⭐ Pay inside Telegram and unlock access.",
        $keyboard
    );

    return false;
}


// ======================================================
// START
// ======================================================

function handleStart(
    array $message
): void {

    $chatId =
        $message['chat']['id'];

    $user =
        $message['from']
        ?? [];

    saveUser($user);

    if (!userHasAccess($chatId)) {

        requirePremium($chatId);

        return;
    }

    sendMessage(
        $chatId,
        "🎵 <b>Music Bot</b>\n\n" .
        "Send any song or artist name.\n\n" .
        "Example:\n" .
        "<code>Chandni Arijit Singh</code>"
    );
}


// ======================================================
// CALLBACK HANDLER
// ======================================================

function handleCallback(
    array $callback
): void {

    $chatId =
        $callback['message']['chat']['id']
        ?? 0;

    $data =
        $callback['data']
        ?? '';

    answerCallback(
        $callback['id']
    );

    if ($data === 'pay') {

        sendStarsInvoice(
            $chatId
        );

        return;
    }

    if (!requirePremium($chatId)) {
        return;
    }

    if (preg_match(
        '/^(play|mp3|fav|queue):(\d+)$/',
        $data,
        $m
    )) {

        $action =
            $m[1];

        $index =
            (int)$m[2];

        switch ($action) {

            case 'play':
                playSong(
                    $chatId,
                    $index
                );
                break;

            case 'mp3':
                downloadMp3(
                    $chatId,
                    $index
                );
                break;

            case 'fav':
                addFavorite(
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

        return;
    }
}


// ======================================================
// MESSAGE HANDLER
// ======================================================

function handleMessage(
    array $message
): void {

    $chatId =
        $message['chat']['id'];

    $user =
        $message['from']
        ?? [];

    saveUser($user);

    // Successful Telegram Stars payment

    if (
        isset(
            $message['successful_payment']
        )
    ) {

        handleSuccessfulPayment(
            $message
        );

        return;
    }

    // Commands / text

    $text =
        trim(
            $message['text']
            ?? ''
        );

    if ($text === '') {
        return;
    }

    if ($text === '/start') {

        handleStart(
            $message
        );

        return;
    }

    if ($text === '/help') {

        if (!requirePremium($chatId)) {
            return;
        }

        sendMessage(
            $chatId,
            "🎵 <b>Music Bot</b>\n\n" .
            "Send a song name to search.\n\n" .
            "Features:\n" .
            "▶️ Play\n" .
            "📥 MP3\n" .
            "❤️ Favorites\n" .
            "➕ Queue"
        );

        return;
    }

    if (
        $text === '/admin' &&
        isAdmin($chatId)
    ) {

        $users =
            loadJson(usersFile());

        sendMessage(
            $chatId,
            "👑 <b>Admin Panel</b>\n\n" .
            "Users: <b>" .
            count($users) .
            "</b>"
        );

        return;
    }

    // Search

    if (
        !requirePremium($chatId)
    ) {
        return;
    }

    searchMusic(
        $chatId,
        $text
    );
}


// ======================================================
// UPDATE ROUTER
// ======================================================

function handleUpdate(
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

        handleMessage(
            $update['message']
        );

        return;
    }

    if (
        isset(
            $update['callback_query']
        )
    ) {

        handleCallback(
            $update['callback_query']
        );

        return;
    }
}


// ======================================================
// WEBHOOK ENTRY
// ======================================================

$input =
    file_get_contents(
        'php://input'
    );

if ($input !== '') {

    $update =
        json_decode(
            $input,
            true
        );

    if (is_array($update)) {

        handleUpdate(
            $update
        );
    }
}


// ======================================================
// HEALTH CHECK
// ======================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
) {

    echo
        '🎵 Telegram Music Bot is running.';
}
