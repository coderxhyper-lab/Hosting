<?php
/**
 * Telegram Music Bot - Single File PHP
 * PHP 8.2+
 */

// ==============================
// CONFIG
// ==============================

const BOT_TOKEN = '8817347840:AAFpsNeTkzHqjnlqkV_18AjMEgIX-FXHmQo';

// Example:
// https://your-api.com/search
const MUSIC_API = 'https://music-search-api-frnb.vercel.app/search?song=chandani';

const API_TIMEOUT = 15;


// ==============================
// TELEGRAM API
// ==============================

function telegram(string $method, array $data = []): array
{
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;

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

    $json = json_decode($response ?: '', true);

    return is_array($json) ? $json : [];
}


// ==============================
// SEND MESSAGE
// ==============================

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

    return telegram('sendMessage', $data);
}


// ==============================
// EDIT MESSAGE
// ==============================

function editMessage(
    int|string $chatId,
    int $messageId,
    string $text,
    ?array $keyboard = null
): array {

    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] = json_encode([
            'inline_keyboard' => $keyboard
        ]);
    }

    return telegram('editMessageText', $data);
}


// ==============================
// ANSWER CALLBACK
// ==============================

function answerCallback(string $callbackId, string $text = ''): void
{
    telegram('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text
    ]);
}


// ==============================
// MUSIC API
// ==============================

function searchMusic(string $query): array
{
    $url = MUSIC_API . '?q=' . urlencode($query);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => API_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);

    curl_close($ch);

    if (!$response) {
        return [];
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return [];
    }

    /*
     * Common API formats:
     *
     * {
     *   "results": [...]
     * }
     *
     * OR
     *
     * [...]
     */

    if (isset($data['results']) && is_array($data['results'])) {
        return $data['results'];
    }

    if (isset($data['data']) && is_array($data['data'])) {
        return $data['data'];
    }

    return array_is_list($data) ? $data : [];
}


// ==============================
// START MESSAGE
// ==============================

function showStart(int|string $chatId): void
{
    $keyboard = [
        [
            [
                'text' => '🔎 Search Music',
                'callback_data' => 'search'
            ]
        ],
        [
            [
                'text' => '❤️ Favorites',
                'callback_data' => 'favorites'
            ],
            [
                'text' => '❓ Help',
                'callback_data' => 'help'
            ]
        ]
    ];

    sendMessage(
        $chatId,
        "🎵 <b>Music Bot</b>\n\n" .
        "Search your favorite songs, artists and albums.\n\n" .
        "Just send me a song name.",
        $keyboard
    );
}


// ==============================
// SEARCH RESULTS
// ==============================

function showSearchResults(
    int|string $chatId,
    string $query
): void {

    sendMessage(
        $chatId,
        "🔎 Searching for:\n" .
        "<b>" . htmlspecialchars($query) . "</b>\n\n" .
        "⏳ Please wait..."
    );

    $results = searchMusic($query);

    if (!$results) {

        sendMessage(
            $chatId,
            "❌ <b>No results found.</b>\n\n" .
            "Try another song or artist."
        );

        return;
    }

    $keyboard = [];

    /*
     * Store results temporarily in callback_data
     * only by index.
     */

    foreach ($results as $index => $song) {

        if ($index >= 10) {
            break;
        }

        $title =
            $song['title']
            ?? $song['name']
            ?? $song['song']
            ?? 'Unknown Song';

        $artist =
            $song['artist']
            ?? $song['author']
            ?? '';

        $label = '🎵 ' . $title;

        if ($artist) {
            $label .= ' — ' . $artist;
        }

        $keyboard[] = [
            [
                'text' => mb_substr($label, 0, 60),
                'callback_data' => 'song:' . $index
            ]
        ];
    }

    /*
     * Back button
     */

    $keyboard[] = [
        [
            'text' => '🏠 Home',
            'callback_data' => 'home'
        ]
    ];

    sendMessage(
        $chatId,
        "🎧 <b>Search Results</b>\n\n" .
        "Query: <code>" .
        htmlspecialchars($query) .
        "</code>\n\n" .
        "Select a song:",
        $keyboard
    );
}


// ==============================
// CALLBACK HANDLER
// ==============================

function handleCallback(array $callback): void
{
    $callbackId = $callback['id'];

    $chatId =
        $callback['message']['chat']['id']
        ?? null;

    $messageId =
        $callback['message']['message_id']
        ?? null;

    $data = $callback['data'] ?? '';

    answerCallback($callbackId);

    if (!$chatId) {
        return;
    }


    // HOME

    if ($data === 'home') {

        showStart($chatId);

        return;
    }


    // SEARCH

    if ($data === 'search') {

        sendMessage(
            $chatId,
            "🔎 <b>Search Music</b>\n\n" .
            "Send me the song or artist name.\n\n" .
            "Example:\n" .
            "<code>Arijit Singh</code>"
        );

        return;
    }


    // HELP

    if ($data === 'help') {

        sendMessage(
            $chatId,
            "❓ <b>Help</b>\n\n" .
            "• Send a song name to search\n" .
            "• Select a result\n" .
            "• Play/download support depends on your API\n\n" .
            "Commands:\n" .
            "/start\n" .
            "/help"
        );

        return;
    }


    // FAVORITES

    if ($data === 'favorites') {

        sendMessage(
            $chatId,
            "❤️ <b>Favorites</b>\n\n" .
            "Your saved songs will appear here."
        );

        return;
    }


    // SONG

    if (str_starts_with($data, 'song:')) {

        $index = (int)substr($data, 5);

        /*
         * At this point we need the actual API result.
         *
         * For a production bot, store search results
         * in Redis/database/cache instead of trusting
         * callback data.
         */

        sendMessage(
            $chatId,
            "🎵 <b>Song selected</b>\n\n" .
            "Result #" . ($index + 1) . "\n\n" .
            "⏳ Processing..."
        );

        /*
         * Actual audio playback/download will be
         * connected here once the API response
         * structure is known.
         */

        return;
    }
}


// ==============================
// MESSAGE HANDLER
// ==============================

function handleMessage(array $message): void
{
    $chatId = $message['chat']['id'];

    $text = trim($message['text'] ?? '');

    if ($text === '') {
        return;
    }


    // START

    if ($text === '/start') {

        showStart($chatId);

        return;
    }


    // HELP

    if ($text === '/help') {

        sendMessage(
            $chatId,
            "❓ <b>Music Bot Help</b>\n\n" .
            "/start — Open bot\n" .
            "/help — Show help\n\n" .
            "Send any song name to search."
        );

        return;
    }


    // SEARCH

    showSearchResults(
        $chatId,
        $text
    );
}


// ==============================
// UPDATE HANDLER
// ==============================

function handleUpdate(array $update): void
{
    if (isset($update['message'])) {

        handleMessage(
            $update['message']
        );

        return;
    }


    if (isset($update['callback_query'])) {

        handleCallback(
            $update['callback_query']
        );

        return;
    }
}


// ==============================
// WEBHOOK
// ==============================

$input = file_get_contents('php://input');

if ($input) {

    $update = json_decode(
        $input,
        true
    );

    if (is_array($update)) {

        handleUpdate($update);
    }
}


// ==============================
// OPTIONAL HEALTH CHECK
// ==============================

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
) {

    echo 'Telegram Music Bot is running.';
}
