<?php
declare(strict_types=1);

/*
===========================================================
                    MAYAMUSIC BOT
===========================================================

Single-file PHP Telegram Music Bot

FEATURES
--------
✓ Telegram Bot
✓ Music Search API
✓ Mini App Music Player
✓ Thumbnail lookup
✓ Lyrics
✓ Previous / Next
✓ Auto Next
✓ Queue
✓ ₹49 / 30 Days Premium
✓ UPI Payment
✓ UTR Submission
✓ Admin Approve / Decline
✓ Account Status
✓ Redeem Keys
✓ Custom Key Expiry
✓ Admin Mini App
✓ User Mini App
✓ Support
✓ Channel / Group FREE access
✓ Webhook
✓ Admin Commands
✓ Payment Records
✓ User Records
✓ Queue Records

IMPORTANT
---------
Telegram Bot API / PHP alone cannot join a Telegram Voice Chat
as an audio participant. The /playcc control layer is included,
but real VC audio requires an external MTProto/voice engine.

===========================================================
*/


/* =========================================================
   CONFIGURATION
========================================================= */

const BOT_TOKEN = '8817347840:AAFpsNeTkzHqjnlqkV_18AjMEgIX-FXHmQo';

const ADMIN_ID = 8897821078;

const BOT_NAME = 'MAYAMUSIC';

const SUPPORT_USERNAME = 'hyperxvicky';

const MUSIC_API =
    'https://music-search-api-frnb.vercel.app/search?song=';

const ITUNES_API =
    'https://itunes.apple.com/search';

const LRCLIB_API =
    'https://lrclib.net/api/search';

const UPI_ID =
    'vickybanna8674@ybl';

const UPI_NAME =
    'MAYAMUSIC';

const PREMIUM_PRICE = 49;

const PREMIUM_DAYS = 30;


/*
IMPORTANT:

Replace this with your real HTTPS URL.

Example:

https://example.com/index.php

Do NOT leave YOUR-DOMAIN.COM.
*/
const WEBAPP_URL =
    'https://YOUR-DOMAIN.COM/index.php';


/* =========================================================
   FILE STORAGE
========================================================= */

const DATA_DIR =
    __DIR__ . '/data';

const USERS_FILE =
    DATA_DIR . '/users.json';

const PAYMENTS_FILE =
    DATA_DIR . '/payments.json';

const KEYS_FILE =
    DATA_DIR . '/redeem_keys.json';

const QUEUES_FILE =
    DATA_DIR . '/queues.json';

const PLAYERS_FILE =
    DATA_DIR . '/players.json';

const SETTINGS_FILE =
    DATA_DIR . '/settings.json';


if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0755, true);
}


/* =========================================================
   JSON STORAGE
========================================================= */

function read_json(string $file, array $default = []): array
{
    if (!file_exists($file)) {
        @file_put_contents(
            $file,
            json_encode(
                $default,
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
        );

        return $default;
    }

    $content = @file_get_contents($file);

    if ($content === false || trim($content) === '') {
        return $default;
    }

    $decoded = json_decode($content, true);

    return is_array($decoded)
        ? $decoded
        : $default;
}


function write_json(string $file, array $data): bool
{
    return @file_put_contents(
        $file,
        json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ),
        LOCK_EX
    ) !== false;
}


function get_users(): array
{
    return read_json(USERS_FILE);
}


function get_payments(): array
{
    return read_json(PAYMENTS_FILE);
}


function get_keys(): array
{
    return read_json(KEYS_FILE);
}


function get_queues(): array
{
    return read_json(QUEUES_FILE);
}


function get_players(): array
{
    return read_json(PLAYERS_FILE);
}


function get_settings(): array
{
    return read_json(
        SETTINGS_FILE,
        [
            'created' => time(),
            'total_searches' => 0,
            'total_plays' => 0
        ]
    );
}


/* =========================================================
   GENERAL HELPERS
========================================================= */

function h(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


function now(): int
{
    return time();
}


function random_id(int $length = 12): string
{
    $characters =
        'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    $result = '';

    for ($i = 0; $i < $length; $i++) {
        $result .=
            $characters[random_int(
                0,
                strlen($characters) - 1
            )];
    }

    return $result;
}


function money(int|float $amount): string
{
    return '₹' . number_format(
        $amount,
        2,
        '.',
        ','
    );
}


function format_date(?int $timestamp): string
{
    if (!$timestamp) {
        return 'N/A';
    }

    return date(
        'd M Y, h:i A',
        $timestamp
    );
}


function remaining_days(?int $timestamp): int
{
    if (!$timestamp || $timestamp <= now()) {
        return 0;
    }

    return (int)ceil(
        ($timestamp - now()) / 86400
    );
}


/* =========================================================
   TELEGRAM API
========================================================= */

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

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]
    );

    $response = curl_exec($ch);

    $error = curl_error($ch);

    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'description' => $error
        ];
    }

    $decoded = json_decode(
        $response,
        true
    );

    return is_array($decoded)
        ? $decoded
        : [
            'ok' => false,
            'description' => 'Invalid Telegram response'
        ];
}


function send_message(
    int|string $chatId,
    string $text,
    ?array $keyboard = null,
    array $extra = []
): array {

    $data = array_merge(
        [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ],
        $extra
    );

    if ($keyboard !== null) {
        $data['reply_markup'] =
            json_encode(
                $keyboard,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );
    }

    return telegram(
        'sendMessage',
        $data
    );
}


function edit_message(
    int|string $chatId,
    int $messageId,
    string $text,
    ?array $keyboard = null
): array {

    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] =
            json_encode(
                $keyboard,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );
    }

    return telegram(
        'editMessageText',
        $data
    );
}


function answer_callback(
    string $callbackId,
    string $text = '',
    bool $alert = false
): array {

    return telegram(
        'answerCallbackQuery',
        [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $alert
        ]
    );
}


/* =========================================================
   USER SYSTEM
========================================================= */

function ensure_user(array $tgUser): array
{
    $users = get_users();

    $id = (string)($tgUser['id'] ?? '');

    if ($id === '') {
        return [];
    }

    if (!isset($users[$id])) {

        $users[$id] = [
            'id' => (int)$id,
            'first_name' =>
                (string)($tgUser['first_name'] ?? ''),
            'last_name' =>
                (string)($tgUser['last_name'] ?? ''),
            'username' =>
                (string)($tgUser['username'] ?? ''),
            'premium_until' => 0,
            'created_at' => now(),
            'last_seen' => now(),
            'searches' => 0,
            'plays' => 0
        ];

    } else {

        $users[$id]['first_name'] =
            (string)($tgUser['first_name'] ?? '');

        $users[$id]['last_name'] =
            (string)($tgUser['last_name'] ?? '');

        $users[$id]['username'] =
            (string)($tgUser['username'] ?? '');

        $users[$id]['last_seen'] =
            now();
    }

    write_json(
        USERS_FILE,
        $users
    );

    return $users[$id];
}


function get_user(int|string $id): ?array
{
    $users = get_users();

    return $users[(string)$id] ?? null;
}


function update_user(
    int|string $id,
    array $changes
): bool {

    $users = get_users();

    $key = (string)$id;

    if (!isset($users[$key])) {
        return false;
    }

    foreach ($changes as $k => $v) {
        $users[$key][$k] = $v;
    }

    return write_json(
        USERS_FILE,
        $users
    );
}


function is_admin(int|string $id): bool
{
    return (int)$id === ADMIN_ID;
}


function is_premium(int|string $id): bool
{
    if (is_admin($id)) {
        return true;
    }

    $user = get_user($id);

    if (!$user) {
        return false;
    }

    return
        !empty($user['premium_until']) &&
        (int)$user['premium_until'] > now();
}


function premium_required(
    int|string $chatId
): bool {

    return !is_premium($chatId);
}


/* =========================================================
   CHAT TYPE
========================================================= */

function chat_is_free_area(array $chat): bool
{
    $type =
        (string)($chat['type'] ?? '');

    return in_array(
        $type,
        [
            'group',
            'supergroup',
            'channel'
        ],
        true
    );
}


/* =========================================================
   MUSIC API
========================================================= */

function http_get_json(
    string $url,
    int $timeout = 20
): ?array {

    $ch = curl_init($url);

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT =>
                'MAYAMUSIC/1.0'
        ]
    );

    $response = curl_exec($ch);

    curl_close($ch);

    if ($response === false) {
        return null;
    }

    $decoded = json_decode(
        $response,
        true
    );

    return is_array($decoded)
        ? $decoded
        : null;
}


function search_music(
    string $query
): array {

    $query = trim($query);

    if ($query === '') {
        return [];
    }

    $url =
        MUSIC_API .
        rawurlencode($query);

    $data = http_get_json(
        $url,
        25
    );

    if (!$data) {
        return [];
    }

    if (
        isset($data['results']) &&
        is_array($data['results'])
    ) {
        return array_values(
            array_filter(
                $data['results'],
                function ($song) {

                    return
                        is_array($song) &&
                        !empty($song['download_url']) &&
                        !empty($song['title']);
                }
            )
        );
    }

    return [];
}


function normalize_song(array $song): array
{
    return [
        'title' =>
            trim((string)(
                $song['title'] ?? 'Unknown'
            )),

        'artists' =>
            trim((string)(
                $song['artists']
                ?? $song['artist']
                ?? 'Unknown Artist'
            )),

        'album' =>
            trim((string)(
                $song['album'] ?? ''
            )),

        'duration' =>
            trim((string)(
                $song['duration'] ?? ''
            )),

        'download_url' =>
            trim((string)(
                $song['download_url'] ?? ''
            )),

        'thumbnail' =>
            trim((string)(
                $song['thumbnail'] ?? ''
            ))
    ];
}


/* =========================================================
   ARTWORK
========================================================= */

function get_artwork(
    string $title,
    string $artist = ''
): string {

    $term = trim(
        $title . ' ' . $artist
    );

    $url =
        ITUNES_API .
        '?term=' .
        rawurlencode($term) .
        '&media=music&entity=song&limit=1';

    $data = http_get_json(
        $url,
        12
    );

    if (
        $data &&
        !empty($data['results'][0])
    ) {

        $image =
            (string)(
                $data['results'][0]
                    ['artworkUrl100']
                    ?? ''
            );

        if ($image !== '') {

            return str_replace(
                '100x100',
                '600x600',
                $image
            );
        }
    }

    return '';
}


/* =========================================================
   LYRICS
========================================================= */

function get_lyrics(
    string $title,
    string $artist = ''
): array {

    $query = trim(
        $title . ' ' . $artist
    );

    $url =
        LRCLIB_API .
        '?q=' .
        rawurlencode($query);

    $data = http_get_json(
        $url,
        15
    );

    if (
        !$data ||
        !is_array($data)
    ) {
        return [];
    }

    foreach ($data as $item) {

        if (!is_array($item)) {
            continue;
        }

        $synced =
            (string)(
                $item['syncedLyrics']
                ?? ''
            );

        $plain =
            (string)(
                $item['plainLyrics']
                ?? ''
            );

        if (
            $synced !== '' ||
            $plain !== ''
        ) {

            return [
                'synced' => $synced,
                'plain' => $plain
            ];
        }
    }

    return [];
}


/* =========================================================
   PLAYLIST / PLAYER
========================================================= */

function create_player(
    int|string $userId,
    array $songs,
    int $index = 0
): string {

    $players = get_players();

    $token = bin2hex(
        random_bytes(18)
    );

    $clean = [];

    foreach ($songs as $song) {

        $normalized =
            normalize_song($song);

        if (
            $normalized['download_url'] === ''
        ) {
            continue;
        }

        if (
            $normalized['thumbnail'] === ''
        ) {
            $normalized['thumbnail'] =
                get_artwork(
                    $normalized['title'],
                    $normalized['artists']
                );
        }

        $clean[] = $normalized;
    }

    if (!$clean) {
        return '';
    }

    if ($index < 0) {
        $index = 0;
    }

    if ($index >= count($clean)) {
        $index = 0;
    }

    $players[$token] = [
        'user_id' => (string)$userId,
        'songs' => $clean,
        'index' => $index,
        'created_at' => now()
    ];

    write_json(
        PLAYERS_FILE,
        $players
    );

    return $token;
}


function get_player(
    string $token
): ?array {

    $players = get_players();

    if (!isset($players[$token])) {
        return null;
    }

    return $players[$token];
}


function save_player(
    string $token,
    array $player
): bool {

    $players = get_players();

    $players[$token] = $player;

    return write_json(
        PLAYERS_FILE,
        $players
    );
}


/* =========================================================
   QUEUE
========================================================= */

function get_user_queue(
    int|string $userId
): array {

    $all = get_queues();

    return
        is_array($all[(string)$userId] ?? null)
        ? $all[(string)$userId]
        : [];
}


function save_user_queue(
    int|string $userId,
    array $queue
): bool {

    $all = get_queues();

    $all[(string)$userId] = array_values(
        $queue
    );

    return write_json(
        QUEUES_FILE,
        $all
    );
}


/* =========================================================
   MAIN KEYBOARD
========================================================= */

function main_keyboard(
    int|string $userId,
    bool $admin = false
): array {

    $keyboard = [
        [
            [
                'text' => '🎵 Search Music',
                'callback_data' => 'menu_search'
            ],
            [
                'text' => '👤 Account',
                'callback_data' => 'menu_account'
            ]
        ],
        [
            [
                'text' => '💎 Premium',
                'callback_data' => 'menu_premium'
            ],
            [
                'text' => '🔑 Redeem',
                'callback_data' => 'menu_redeem'
            ]
        ],
        [
            [
                'text' => '🎧 My Queue',
                'callback_data' => 'menu_queue'
            ],
            [
                'text' => '🆘 Support',
                'callback_data' => 'menu_support'
            ]
        ]
    ];

    if ($admin) {

        $keyboard[] = [
            [
                'text' => '⚙️ Admin Panel',
                'callback_data' => 'menu_admin'
            ]
        ];
    }

    return [
        'inline_keyboard' => $keyboard
    ];
}


/* =========================================================
   PREMIUM PAGE
========================================================= */

function premium_keyboard(): array
{
    return [
        'inline_keyboard' => [

            [
                [
                    'text' => '💳 Buy ₹49 / 30 Days',
                    'callback_data' => 'buy_premium'
                ]
            ],

            [
                [
                    'text' => '🔄 Check Account',
                    'callback_data' => 'menu_account'
                ]
            ],

            [
                [
                    'text' => '🔙 Main Menu',
                    'callback_data' => 'main_menu'
                ]
            ]

        ]
    ];
}


function premium_text(): string
{
    return
        "💎 <b>MAYAMUSIC PREMIUM</b>\n\n" .

        "Unlock private-user music access for " .
        "<b>" . money(PREMIUM_PRICE) . "</b> / " .
        PREMIUM_DAYS . " days.\n\n" .

        "✨ <b>Premium Features</b>\n" .
        "• 🎵 Full music search\n" .
        "• ▶️ Telegram Mini App player\n" .
        "• 🖼 Album artwork\n" .
        "• 🎤 Lyrics\n" .
        "• ⏭ Auto-next\n" .
        "• ⏮ Previous / Next\n" .
        "• 📋 Queue\n" .
        "• 🎧 Private music access\n" .
        "• ⚡ Fast search interface\n" .
        "• 🔑 Redeem-key support\n\n" .

        "💰 <b>Price:</b> ₹49\n" .
        "⏱ <b>Validity:</b> 30 days\n\n" .

        "🏷 <b>Channel / Group access is FREE.</b>\n" .
        "Private-user premium requires activation.";
}


/* =========================================================
   PAYMENT SYSTEM
========================================================= */

function create_payment(
    int|string $userId
): array {

    $payments = get_payments();

    $paymentId =
        'MAYA-' .
        date('YmdHis') .
        '-' .
        random_id(6);

    $payments[$paymentId] = [
        'payment_id' => $paymentId,
        'user_id' => (string)$userId,
        'amount' => PREMIUM_PRICE,
        'currency' => 'INR',
        'upi_id' => UPI_ID,
        'status' => 'pending',
        'utr' => '',
        'created_at' => now(),
        'updated_at' => now()
    ];

    write_json(
        PAYMENTS_FILE,
        $payments
    );

    return $payments[$paymentId];
}


function payment_page(
    string $paymentId
): string {

    $upiLink =
        'upi://pay?' .
        http_build_query(
            [
                'pa' => UPI_ID,
                'pn' => UPI_NAME,
                'am' => number_format(
                    PREMIUM_PRICE,
                    2,
                    '.',
                    ''
                ),
                'cu' => 'INR',
                'tn' => 'MAYAMUSIC ' . $paymentId
            ]
        );

    $botBack =
        WEBAPP_URL;

    return '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport"
content="width=device-width,initial-scale=1">
<title>MAYAMUSIC Premium</title>

<style>

*{
box-sizing:border-box;
}

body{
margin:0;
font-family:Inter,Arial,sans-serif;
background:
radial-gradient(circle at top,#25204f,#090914 55%);
color:#fff;
min-height:100vh;
padding:20px;
}

.card{
max-width:520px;
margin:20px auto;
background:rgba(255,255,255,.08);
border:1px solid rgba(255,255,255,.12);
backdrop-filter:blur(20px);
border-radius:28px;
padding:26px;
box-shadow:0 25px 80px rgba(0,0,0,.45);
}

.logo{
width:72px;
height:72px;
border-radius:22px;
display:flex;
align-items:center;
justify-content:center;
font-size:34px;
background:linear-gradient(135deg,#7c3aed,#ec4899);
margin-bottom:18px;
}

h1{
margin:0 0 8px;
font-size:28px;
}

.price{
font-size:40px;
font-weight:800;
margin:20px 0;
}

.features{
line-height:1.9;
color:#ddd;
}

.upi{
padding:15px;
background:rgba(255,255,255,.07);
border-radius:16px;
margin:20px 0;
word-break:break-all;
}

a.pay{
display:block;
text-align:center;
text-decoration:none;
color:white;
background:linear-gradient(135deg,#7c3aed,#ec4899);
padding:16px;
border-radius:16px;
font-weight:800;
margin:18px 0;
}

input{
width:100%;
padding:15px;
border:1px solid rgba(255,255,255,.15);
border-radius:14px;
background:#11111c;
color:#fff;
outline:none;
font-size:16px;
}

button{
width:100%;
border:0;
padding:16px;
border-radius:15px;
background:#fff;
color:#111;
font-weight:800;
margin-top:12px;
font-size:16px;
}

.small{
color:#999;
font-size:13px;
line-height:1.6;
}

</style>
</head>

<body>

<div class="card">

<div class="logo">🎵</div>

<h1>MAYAMUSIC Premium</h1>

<div class="price">₹49
<span style="font-size:15px;color:#aaa">
/ 30 days
</span>
</div>

<div class="features">
✓ Full private music access<br>
✓ Mini App music player<br>
✓ Lyrics<br>
✓ Album artwork<br>
✓ Auto-next<br>
✓ Queue<br>
✓ Previous / Next
</div>

<div class="upi">
<b>UPI ID</b><br><br>
' . h(UPI_ID) . '
</div>

<a class="pay"
href="' . h($upiLink) . '">
💳 Open UPI App & Pay ₹49
</a>

<form method="post"
action="?action=submit_utr">

<input type="hidden"
name="payment_id"
value="' . h($paymentId) . '">

<label>
<b>Transaction / UTR ID</b>
</label>

<br><br>

<input
name="utr"
required
maxlength="100"
placeholder="Enter UTR / Transaction ID">

<button type="submit">
Submit Payment
</button>

</form>

<p class="small">
After submitting the UTR, your payment will be
reviewed by the MAYAMUSIC admin.
Access activates after approval.
</p>

</div>

</body>
</html>';
}


/* =========================================================
   REDEEM KEY SYSTEM
========================================================= */

function generate_redeem_key(
    int $days,
    int|string $createdBy
): array {

    $keys = get_keys();

    do {
        $key =
            'MAYA-' .
            random_id(5) .
            '-' .
            random_id(5) .
            '-' .
            random_id(5);
    } while (isset($keys[$key]));

    $keys[$key] = [
        'key' => $key,
        'days' => max(1, $days),
        'created_by' => (string)$createdBy,
        'created_at' => now(),
        'used' => false,
        'used_by' => null,
        'used_at' => null,
        'expires_at' => null
    ];

    write_json(
        KEYS_FILE,
        $keys
    );

    return $keys[$key];
}


function redeem_key(
    int|string $userId,
    string $inputKey
): array {

    $key =
        strtoupper(
            trim($inputKey)
        );

    $keys = get_keys();

    if (!isset($keys[$key])) {

        return [
            'ok' => false,
            'message' => '❌ Invalid redeem key.'
        ];
    }

    if (!empty($keys[$key]['used'])) {

        return [
            'ok' => false,
            'message' => '❌ This redeem key has already been used.'
        ];
    }

    if (
        !empty($keys[$key]['expires_at']) &&
        (int)$keys[$key]['expires_at'] <= now()
    ) {

        return [
            'ok' => false,
            'message' => '❌ This redeem key has expired.'
        ];
    }

    $days =
        max(
            1,
            (int)$keys[$key]['days']
        );

    $user = get_user($userId);

    if (!$user) {

        return [
            'ok' => false,
            'message' => '❌ User account not found.'
        ];
    }

    $current =
        max(
            now(),
            (int)($user['premium_until'] ?? 0)
        );

    $newExpiry =
        $current + ($days * 86400);

    update_user(
        $userId,
        [
            'premium_until' => $newExpiry
        ]
    );

    $keys[$key]['used'] = true;
    $keys[$key]['used_by'] = (string)$userId;
    $keys[$key]['used_at'] = now();

    write_json(
        KEYS_FILE,
        $keys
    );

    return [
        'ok' => true,
        'message' =>
            "✅ <b>Premium Activated</b>\n\n" .
            "Key: <code>" . h($key) . "</code>\n" .
            "Added: <b>" . $days . " days</b>\n" .
            "Valid until: <b>" .
            h(format_date($newExpiry)) .
            "</b>"
    ];
}


/* =========================================================
   SEARCH RESULT KEYBOARD
========================================================= */

function song_keyboard(
    string $token
): array {

    return [
        'inline_keyboard' => [

            [
                [
                    'text' => '▶️ Open Player',
                    'web_app' => [
                        'url' =>
                            WEBAPP_URL .
                            '?action=player&token=' .
                            rawurlencode($token)
                    ]
                ]
            ],

            [
                [
                    'text' => '➕ Add Queue',
                    'callback_data' => 'addq:' . $token
                ]
            ],

            [
                [
                    'text' => '💎 Premium',
                    'callback_data' => 'menu_premium'
                ]
            ]

        ]
    ];
}


/* =========================================================
   SEND SEARCH RESULTS
========================================================= */

function send_search_results(
    int|string $chatId,
    string $query
): void {

    $results =
        search_music($query);

    if (!$results) {

        send_message(
            $chatId,
            "❌ No music found for:\n<code>" .
            h($query) .
            "</code>",
            main_keyboard(
                $chatId,
                is_admin($chatId)
            )
        );

        return;
    }

    $users = get_users();

    if (isset($users[(string)$chatId])) {

        $users[(string)$chatId]['searches'] =
            (int)(
                $users[(string)$chatId]['searches']
                ?? 0
            ) + 1;

        write_json(
            USERS_FILE,
            $users
        );
    }

    $settings = get_settings();

    $settings['total_searches'] =
        (int)(
            $settings['total_searches']
            ?? 0
        ) + 1;

    write_json(
        SETTINGS_FILE,
        $settings
    );

    $songs = [];

    foreach (
        array_slice(
            $results,
            0,
            10
        ) as $song
    ) {

        $songs[] =
            normalize_song($song);
    }

    $token =
        create_player(
            $chatId,
            $songs,
            0
        );

    if ($token === '') {

        send_message(
            $chatId,
            '❌ Unable to create player.'
        );

        return;
    }

    $first =
        $songs[0];

    $text =
        "🎵 <b>" .
        h($first['title']) .
        "</b>\n\n" .

        "👤 " .
        h($first['artists']) .
        "\n" .

        (
            $first['album'] !== ''
                ? "💿 " .
                  h($first['album']) .
                  "\n"
                : ''
        ) .

        (
            $first['duration'] !== ''
                ? "⏱ " .
                  h($first['duration']) .
                  "\n"
                : ''
        ) .

        "\n🔎 Search: <code>" .
        h($query) .
        "</code>\n\n" .

        "▶️ Open the player to listen.";

    send_message(
        $chatId,
        $text,
        song_keyboard($token)
    );

    if (count($songs) > 1) {

        $list = "📋 <b>Queue Preview</b>\n\n";

        foreach (
            array_slice(
                $songs,
                0,
                8
            ) as $i => $s
        ) {

            $list .=
                ($i + 1) .
                ". " .
                h($s['title']) .
                " — " .
                h($s['artists']) .
                "\n";
        }

        send_message(
            $chatId,
            $list
        );
    }
}


/* =========================================================
   USER MENU TEXT
========================================================= */

function account_text(
    int|string $userId
): string {

    $user = get_user($userId);

    if (!$user) {
        return '❌ Account not found.';
    }

    $active =
        is_premium($userId);

    if ($active) {

        return
            "👤 <b>Your MAYAMUSIC Account</b>\n\n" .
            "🟢 Premium: <b>ACTIVE</b>\n" .
            "⏳ Valid until: <b>" .
            h(
                format_date(
                    (int)$user['premium_until']
                )
            ) .
            "</b>\n" .
            "📅 Remaining: <b>" .
            remaining_days(
                (int)$user['premium_until']
            ) .
            " days</b>\n\n" .
            "🎵 Searches: " .
            (int)($user['searches'] ?? 0) .
            "\n" .
            "▶️ Plays: " .
            (int)($user['plays'] ?? 0);

    }

    return
        "👤 <b>Your MAYAMUSIC Account</b>\n\n" .
        "🔴 Premium: <b>INACTIVE</b>\n\n" .
        "Private music access requires Premium.\n\n" .
        "💎 Price: <b>₹49 / 30 days</b>\n\n" .
        "Channel / Group access is FREE.";
}


/* =========================================================
   ADMIN TEXT
========================================================= */

function admin_text(): string
{
    $users =
        get_users();

    $payments =
        get_payments();

    $keys =
        get_keys();

    $pending = 0;

    foreach ($payments as $p) {

        if (
            ($p['status'] ?? '') ===
            'pending'
        ) {
            $pending++;
        }
    }

    $unusedKeys = 0;

    foreach ($keys as $k) {

        if (
            empty($k['used'])
        ) {
            $unusedKeys++;
        }
    }

    return
        "⚙️ <b>MAYAMUSIC ADMIN</b>\n\n" .

        "👥 Users: <b>" .
        count($users) .
        "</b>\n" .

        "💳 Pending Payments: <b>" .
        $pending .
        "</b>\n" .

        "🔑 Unused Keys: <b>" .
        $unusedKeys .
        "</b>\n\n" .

        "Use the buttons below.";
}


function admin_keyboard(): array
{
    return [
        'inline_keyboard' => [

            [
                [
                    'text' => '📊 Stats',
                    'callback_data' => 'admin_stats'
                ],
                [
                    'text' => '💳 Payments',
                    'callback_data' => 'admin_payments'
                ]
            ],

            [
                [
                    'text' => '🔑 Generate Key',
                    'callback_data' => 'admin_key'
                ],
                [
                    'text' => '👥 Users',
                    'callback_data' => 'admin_users'
                ]
            ],

            [
                [
                    'text' => '🌐 Admin Mini App',
                    'web_app' => [
                        'url' =>
                            WEBAPP_URL .
                            '?action=adminapp'
                    ]
                ]
            ],

            [
                [
                    'text' => '🔙 Main Menu',
                    'callback_data' => 'main_menu'
                ]
            ]

        ]
    ];
}


/* =========================================================
   ADMIN PAYMENT NOTIFICATION
========================================================= */

function notify_admin_payment(
    array $payment
): void {

    $user =
        get_user(
            $payment['user_id']
        );

    $name =
        $user
        ? trim(
            ($user['first_name'] ?? '') .
            ' ' .
            ($user['last_name'] ?? '')
        )
        : 'Unknown';

    $username =
        $user['username'] ?? '';

    $text =
        "💳 <b>NEW PREMIUM PAYMENT</b>\n\n" .

        "🆔 Payment: <code>" .
        h($payment['payment_id']) .
        "</code>\n" .

        "👤 User ID: <code>" .
        h((string)$payment['user_id']) .
        "</code>\n" .

        "👤 Name: " .
        h($name) .
        "\n" .

        (
            $username !== ''
                ? "🔗 @" .
                  h($username) .
                  "\n"
                : ''
        ) .

        "💰 Amount: <b>" .
        money((float)$payment['amount']) .
        "</b>\n" .

        "🏦 UPI: <code>" .
        h(UPI_ID) .
        "</code>\n\n" .

        "Waiting for UTR submission.";

    send_message(
        ADMIN_ID,
        $text,
        [
            'inline_keyboard' => [
                [
                    [
                        'text' => '⏳ Pending',
                        'callback_data' =>
                            'viewpay:' .
                            $payment['payment_id']
                    ]
                ]
            ]
        ]
    );
}


/* =========================================================
   PAYMENT APPROVAL
========================================================= */

function approve_payment(
    string $paymentId
): string {

    $payments =
        get_payments();

    if (!isset($payments[$paymentId])) {
        return 'Payment not found.';
    }

    $payment =
        $payments[$paymentId];

    if (
        ($payment['status'] ?? '') ===
        'approved'
    ) {
        return 'Payment already approved.';
    }

    $userId =
        (string)$payment['user_id'];

    $user =
        get_user($userId);

    if (!$user) {
        return 'User not found.';
    }

    $current =
        max(
            now(),
            (int)(
                $user['premium_until']
                ?? 0
            )
        );

    $expiry =
        $current +
        (
            PREMIUM_DAYS *
            86400
        );

    update_user(
        $userId,
        [
            'premium_until' =>
                $expiry
        ]
    );

    $payments[$paymentId]['status'] =
        'approved';

    $payments[$paymentId]['updated_at'] =
        now();

    $payments[$paymentId]['approved_by'] =
        (string)ADMIN_ID;

    write_json(
        PAYMENTS_FILE,
        $payments
    );

    send_message(
        (int)$userId,
        "✅ <b>Premium Activated!</b>\n\n" .
        "💎 Plan: MAYAMUSIC Premium\n" .
        "⏱ Added: " .
        PREMIUM_DAYS .
        " days\n" .
        "📅 Valid until: <b>" .
        h(format_date($expiry)) .
        "</b>\n\n" .
        "Enjoy MAYAMUSIC 🎵"
    );

    return
        "✅ Payment approved.\n" .
        "Premium activated until " .
        format_date($expiry);
}


function decline_payment(
    string $paymentId
): string {

    $payments =
        get_payments();

    if (!isset($payments[$paymentId])) {
        return 'Payment not found.';
    }

    $payments[$paymentId]['status'] =
        'declined';

    $payments[$paymentId]['updated_at'] =
        now();

    $payments[$paymentId]['declined_by'] =
        (string)ADMIN_ID;

    write_json(
        PAYMENTS_FILE,
        $payments
    );

    $userId =
        (string)(
            $payments[$paymentId]['user_id']
            ?? ''
        );

    if ($userId !== '') {

        send_message(
            (int)$userId,
            "❌ <b>Payment Declined</b>\n\n" .
            "Your MAYAMUSIC Premium payment was not approved.\n\n" .
            "Please verify the UTR/payment details and try again."
        );
    }

    return '❌ Payment declined.';
}


/* =========================================================
   WEBAPP INITDATA SECURITY
========================================================= */

function verify_webapp_init_data(
    string $initData
): ?array {

    if ($initData === '') {
        return null;
    }

    parse_str(
        $initData,
        $data
    );

    if (
        empty($data['hash'])
    ) {
        return null;
    }

    $hash =
        $data['hash'];

    unset(
        $data['hash']
    );

    ksort($data);

    $pairs = [];

    foreach ($data as $key => $value) {

        $pairs[] =
            $key .
            '=' .
            $value;
    }

    $dataCheckString =
        implode(
            "\n",
            $pairs
        );

    $secretKey =
        hash_hmac(
            'sha256',
            BOT_TOKEN,
            'WebAppData',
            true
        );

    $calculated =
        hash_hmac(
            'sha256',
            $dataCheckString,
            $secretKey
        );

    if (
        !hash_equals(
            $calculated,
            $hash
        )
    ) {
        return null;
    }

    if (
        isset($data['auth_date']) &&
        (
            now() -
            (int)$data['auth_date']
        ) > 86400
    ) {
        return null;
    }

    $user = [];

    if (!empty($data['user'])) {

        $user =
            json_decode(
                $data['user'],
                true
            );
    }

    return [
        'data' => $data,
        'user' => is_array($user)
            ? $user
            : []
    ];
}


/* =========================================================
   WEBAPP HTML
========================================================= */

function player_app(
    string $token
): string {

    $player =
        get_player($token);

    if (!$player) {

        return '<!doctype html>
<html>
<body style="background:#090914;color:white;font-family:Arial;padding:30px">
<h2>❌ Player expired</h2>
<p>Search the song again from MAYAMUSIC.</p>
</body>
</html>';
    }

    $json =
        json_encode(
            $player,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

    $apiBase =
        WEBAPP_URL;

    return '<!doctype html>
<html>
<head>

<meta charset="utf-8">

<meta name="viewport"
content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">

<title>MAYAMUSIC Player</title>

<style>

*{
box-sizing:border-box;
-webkit-tap-highlight-color:transparent;
}

html,body{
margin:0;
padding:0;
width:100%;
height:100%;
overflow:hidden;
font-family:Inter,Arial,sans-serif;
background:#08080f;
color:#fff;
}

body{
background:
radial-gradient(
circle at 50% -10%,
#47227d 0,
#160d29 30%,
#08080f 70%
);
}

.app{
height:100vh;
height:100dvh;
display:flex;
flex-direction:column;
padding:18px;
}

.top{
display:flex;
align-items:center;
justify-content:space-between;
height:50px;
}

.top button{
border:0;
background:rgba(255,255,255,.09);
color:white;
width:44px;
height:44px;
border-radius:15px;
font-size:20px;
}

.brand{
font-weight:800;
letter-spacing:.5px;
}

.coverWrap{
flex:1;
display:flex;
align-items:center;
justify-content:center;
padding:12px 0;
min-height:0;
}

.cover{
width:min(78vw,330px);
height:min(78vw,330px);
border-radius:30px;
object-fit:cover;
background:linear-gradient(135deg,#26233a,#11111c);
box-shadow:
0 30px 80px rgba(0,0,0,.55);
}

.noCover{
display:flex;
align-items:center;
justify-content:center;
font-size:70px;
}

.info{
text-align:center;
padding:4px 10px 12px;
}

.title{
font-size:22px;
font-weight:800;
white-space:nowrap;
overflow:hidden;
text-overflow:ellipsis;
}

.artist{
color:#aaa;
margin-top:7px;
white-space:nowrap;
overflow:hidden;
text-overflow:ellipsis;
}

.progress{
margin:12px 0 3px;
}

input[type=range]{
width:100%;
accent-color:#fff;
}

.time{
display:flex;
justify-content:space-between;
font-size:11px;
color:#888;
}

.controls{
display:flex;
align-items:center;
justify-content:center;
gap:22px;
padding:12px 0;
}

.ctrl{
border:0;
background:transparent;
color:white;
font-size:27px;
width:52px;
height:52px;
border-radius:50%;
}

.play{
background:#fff;
color:#090914;
width:68px;
height:68px;
font-size:28px;
}

.extra{
display:flex;
justify-content:center;
gap:12px;
padding:8px 0;
}

.extra button{
border:0;
background:rgba(255,255,255,.08);
color:#fff;
border-radius:14px;
padding:11px 14px;
}

.lyrics{
position:fixed;
left:0;
right:0;
bottom:0;
top:0;
background:rgba(5,5,10,.97);
z-index:10;
display:none;
padding:22px;
overflow:auto;
}

.lyrics.show{
display:block;
}

.lyricsHead{
display:flex;
justify-content:space-between;
align-items:center;
position:sticky;
top:0;
background:#08080f;
padding-bottom:15px;
}

.lyricsText{
white-space:pre-wrap;
line-height:1.8;
color:#ddd;
padding-bottom:70px;
}

.spinner{
opacity:.6;
}

</style>

</head>

<body>

<div class="app">

<div class="top">

<button onclick="goBack()">‹</button>

<div class="brand">MAYAMUSIC</div>

<button onclick="showLyrics()">♫</button>

</div>

<div class="coverWrap">

<img id="cover"
class="cover"
alt="Artwork">

</div>

<div class="info">

<div id="title"
class="title">
MAYAMUSIC
</div>

<div id="artist"
class="artist">
Loading...
</div>

<div class="progress">

<input
id="progress"
type="range"
min="0"
max="100"
value="0"
step="0.1">

<div class="time">
<span id="current">0:00</span>
<span id="duration">0:00</span>
</div>

</div>

</div>

<div class="controls">

<button
class="ctrl"
onclick="previousSong()">
⏮
</button>

<button
class="ctrl play"
id="playBtn"
onclick="togglePlay()">
▶
</button>

<button
class="ctrl"
onclick="nextSong()">
⏭
</button>

</div>

<div class="extra">

<button onclick="showLyrics()">
🎤 Lyrics
</button>

<button onclick="addQueue()">
➕ Queue
</button>

</div>

</div>

<div id="lyrics"
class="lyrics">

<div class="lyricsHead">

<b>Lyrics</b>

<button
class="ctrl"
onclick="hideLyrics()">
✕
</button>

</div>

<div
id="lyricsText"
class="lyricsText">
Loading lyrics...
</div>

</div>

<audio
id="audio"
preload="auto"
playsinline>
</audio>

<script>

const API =
<?php
echo json_encode(
    $apiBase,
    JSON_UNESCAPED_SLASHES
);
?>;

let player =
<?php
echo $json;
?>;

let currentIndex =
    Number(player.index || 0);

let songs =
    Array.isArray(player.songs)
    ? player.songs
    : [];

const audio =
    document.getElementById('audio');

const cover =
    document.getElementById('cover');

const title =
    document.getElementById('title');

const artist =
    document.getElementById('artist');

const playBtn =
    document.getElementById('playBtn');

const progress =
    document.getElementById('progress');

const currentTime =
    document.getElementById('current');

const duration =
    document.getElementById('duration');

const lyricsBox =
    document.getElementById('lyrics');

const lyricsText =
    document.getElementById('lyricsText');


function formatTime(sec){

    if(!Number.isFinite(sec)){
        return '0:00';
    }

    sec =
        Math.max(0,Math.floor(sec));

    const m =
        Math.floor(sec / 60);

    const s =
        sec % 60;

    return m + ':' +
        String(s).padStart(2,'0');
}


function currentSong(){

    return songs[currentIndex]
        || null;
}


function loadSong(index, autoPlay = true){

    if(!songs.length){
        return;
    }

    if(index < 0){
        index = songs.length - 1;
    }

    if(index >= songs.length){
        index = 0;
    }

    currentIndex = index;

    const song =
        currentSong();

    if(!song){
        return;
    }

    title.textContent =
        song.title || 'Unknown';

    artist.textContent =
        song.artists ||
        'Unknown Artist';

    if(song.thumbnail){

        cover.src =
            song.thumbnail;

        cover.classList.remove(
            'noCover'
        );

    }else{

        cover.removeAttribute(
            'src'
        );

        cover.classList.add(
            'noCover'
        );

        cover.alt = '🎵';
    }

    audio.src =
        song.download_url;

    audio.load();

    loadLyrics(song);

    if(autoPlay){

        const p =
            audio.play();

        if(p && p.catch){

            p.catch(() => {

                playBtn.textContent =
                    '▶';

            });

        }

    }

    playBtn.textContent =
        autoPlay
        ? 'Ⅱ'
        : '▶';

    history.replaceState(
        null,
        '',
        location.href
    );
}


async function loadLyrics(song){

    lyricsText.innerHTML =
        '<span class="spinner">Loading lyrics...</span>';

    try{

        const url =
            API +
            '?action=lyrics' +
            '&title=' +
            encodeURIComponent(
                song.title || ''
            ) +
            '&artist=' +
            encodeURIComponent(
                song.artists || ''
            );

        const response =
            await fetch(url);

        const data =
            await response.json();

        if(data.ok){

            lyricsText.textContent =
                data.lyrics ||
                'Lyrics not available.';

        }else{

            lyricsText.textContent =
                'Lyrics not available for this song.';
        }

    }catch(e){

        lyricsText.textContent =
            'Unable to load lyrics.';
    }
}


function togglePlay(){

    if(audio.paused){

        audio.play()
            .then(() => {

                playBtn.textContent =
                    'Ⅱ';

            })
            .catch(() => {

                playBtn.textContent =
                    '▶';

            });

    }else{

        audio.pause();

        playBtn.textContent =
            '▶';
    }
}


function nextSong(){

    if(!songs.length){
        return;
    }

    loadSong(
        currentIndex + 1,
        true
    );
}


function previousSong(){

    if(!songs.length){
        return;
    }

    if(audio.currentTime > 5){

        audio.currentTime = 0;

        return;
    }

    loadSong(
        currentIndex - 1,
        true
    );
}


function showLyrics(){

    lyricsBox.classList.add(
        'show'
    );
}


function hideLyrics(){

    lyricsBox.classList.remove(
        'show'
    );
}


function goBack(){

    if(
        window.Telegram &&
        Telegram.WebApp
    ){

        Telegram.WebApp.close();

    }else{

        history.back();
    }
}


async function addQueue(){

    const song =
        currentSong();

    if(!song){
        return;
    }

    try{

        const url =
            API +
            '?action=queue_add' +
            '&token=' +
            encodeURIComponent(
                <?php echo json_encode($token); ?>
            ) +
            '&index=' +
            currentIndex;

        const response =
            await fetch(url);

        const data =
            await response.json();

        alert(
            data.message ||
            'Added to queue'
        );

    }catch(e){

        alert(
            'Unable to add queue.'
        );
    }
}


audio.addEventListener(
    'play',
    () => {
        playBtn.textContent = 'Ⅱ';
    }
);


audio.addEventListener(
    'pause',
    () => {
        playBtn.textContent = '▶';
    }
);


audio.addEventListener(
    'timeupdate',
    () => {

        if(!audio.duration){
            return;
        }

        progress.value =
            (
                audio.currentTime /
                audio.duration
            ) * 100;

        currentTime.textContent =
            formatTime(
                audio.currentTime
            );

        duration.textContent =
            formatTime(
                audio.duration
            );
    }
);


audio.addEventListener(
    'loadedmetadata',
    () => {

        duration.textContent =
            formatTime(
                audio.duration
            );
    }
);


progress.addEventListener(
    'input',
    () => {

        if(!audio.duration){
            return;
        }

        audio.currentTime =
            (
                Number(progress.value) /
                100
            ) *
            audio.duration;
    }
);


/*
IMPORTANT AUTO NEXT

When the current song ends,
the next result starts automatically.
*/
audio.addEventListener(
    'ended',
    () => {

        nextSong();
    }
);


document.addEventListener(
    'visibilitychange',
    () => {

        /*
        Do not stop audio when
        Telegram WebView becomes hidden.
        Browser/Telegram policy controls
        background behavior.
        */
    }
);


if(
    window.Telegram &&
    Telegram.WebApp
){

    Telegram.WebApp.ready();

    Telegram.WebApp.expand();
}


loadSong(
    currentIndex,
    true
);

</script>

</body>
</html>';
}


/* =========================================================
   ADMIN MINI APP
========================================================= */

function admin_app(): string
{
    return '<!doctype html>
<html>
<head>

<meta charset="utf-8">

<meta name="viewport"
content="width=device-width,initial-scale=1">

<title>MAYAMUSIC Admin</title>

<style>

*{
box-sizing:border-box;
}

body{
margin:0;
font-family:Arial,sans-serif;
background:
radial-gradient(circle at top,#32195f,#08080f 65%);
color:#fff;
min-height:100vh;
padding:18px;
}

.wrap{
max-width:700px;
margin:auto;
}

.card{
background:rgba(255,255,255,.07);
border:1px solid rgba(255,255,255,.1);
border-radius:24px;
padding:20px;
margin-bottom:16px;
backdrop-filter:blur(18px);
}

h1{
margin-top:0;
}

input,button{
width:100%;
padding:15px;
border-radius:14px;
border:1px solid rgba(255,255,255,.12);
font-size:16px;
}

input{
background:#10101a;
color:#fff;
margin-bottom:10px;
}

button{
background:#fff;
color:#111;
font-weight:800;
margin-top:8px;
}

.stat{
font-size:30px;
font-weight:800;
}

.muted{
color:#aaa;
font-size:13px;
}

.result{
white-space:pre-wrap;
margin-top:15px;
line-height:1.7;
}

</style>

</head>

<body>

<div class="wrap">

<div class="card">

<h1>⚙️ MAYAMUSIC ADMIN</h1>

<div class="muted">
Secure Telegram Mini App
</div>

</div>

<div class="card">

<h2>🔑 Generate Redeem Key</h2>

<input
id="days"
type="number"
min="1"
value="30"
placeholder="Days">

<input
id="count"
type="number"
min="1"
max="20"
value="1"
placeholder="Number of keys">

<button onclick="generateKeys()">
Generate
</button>

<div
id="keyResult"
class="result">
</div>

</div>

<div class="card">

<h2>👤 Give Premium</h2>

<input
id="uid"
placeholder="Telegram User ID">

<input
id="giveDays"
type="number"
min="1"
value="30"
placeholder="Days">

<button onclick="givePremium()">
Activate Premium
</button>

<div
id="giveResult"
class="result">
</div>

</div>

<div class="card">

<h2>🚫 Revoke Premium</h2>

<input
id="revokeUid"
placeholder="Telegram User ID">

<button onclick="revokePremium()">
Revoke
</button>

<div
id="revokeResult"
class="result">
</div>

</div>

<div class="card">

<h2>📊 Statistics</h2>

<button onclick="loadStats()">
Refresh Stats
</button>

<div
id="stats"
class="result">
</div>

</div>

</div>

<script>

const tg =
window.Telegram &&
Telegram.WebApp
    ? Telegram.WebApp
    : null;

if(tg){

    tg.ready();
    tg.expand();
}


async function api(action, params = {}){

    const body =
        new URLSearchParams();

    body.set(
        'action',
        action
    );

    body.set(
        'initData',
        tg ? tg.initData : ''
    );

    Object.keys(params).forEach(
        key => {
            body.set(
                key,
                params[key]
            );
        }
    );

    const response =
        await fetch(
            location.href,
            {
                method:'POST',
                headers:{
                    'Content-Type':
                        'application/x-www-form-urlencoded'
                },
                body
            }
        );

    return response.json();
}


async function generateKeys(){

    const days =
        document.getElementById(
            'days'
        ).value;

    const count =
        document.getElementById(
            'count'
        ).value;

    const data =
        await api(
            'admin_generate_keys',
            {
                days,
                count
            }
        );

    document.getElementById(
        'keyResult'
    ).textContent =
        data.message || 'Error';
}


async function givePremium(){

    const userId =
        document.getElementById(
            'uid'
        ).value;

    const days =
        document.getElementById(
            'giveDays'
        ).value;

    const data =
        await api(
            'admin_give',
            {
                user_id:userId,
                days
            }
        );

    document.getElementById(
        'giveResult'
    ).textContent =
        data.message || 'Error';
}


async function revokePremium(){

    const userId =
        document.getElementById(
            'revokeUid'
        ).value;

    const data =
        await api(
            'admin_revoke',
            {
                user_id:userId
            }
        );

    document.getElementById(
        'revokeResult'
    ).textContent =
        data.message || 'Error';
}


async function loadStats(){

    const data =
        await api(
            'admin_stats'
        );

    document.getElementById(
        'stats'
    ).textContent =
        data.message || 'Error';
}

</script>

</body>
</html>';
}


/* =========================================================
   HANDLE MINI APP API
========================================================= */

function handle_webapp_api(): void
{
    header(
        'Content-Type: application/json; charset=utf-8'
    );

    $action =
        (string)(
            $_POST['action']
            ??
            $_GET['action']
            ??
            ''
        );

    if ($action === 'lyrics') {

        $title =
            trim(
                (string)(
                    $_GET['title'] ?? ''
                )
            );

        $artist =
            trim(
                (string)(
                    $_GET['artist'] ?? ''
                )
            );

        if ($title === '') {

            echo json_encode(
                [
                    'ok' => false,
                    'lyrics' => ''
                ]
            );

            return;
        }

        $lyrics =
            get_lyrics(
                $title,
                $artist
            );

        $text =
            $lyrics['plain']
            ?? '';

        if ($text === '') {

            $text =
                $lyrics['synced']
                ?? '';
        }

        echo json_encode(
            [
                'ok' => $text !== '',
                'lyrics' => $text
            ],
            JSON_UNESCAPED_UNICODE
        );

        return;
    }


    if ($action === 'queue_add') {

        $token =
            (string)(
                $_GET['token'] ?? ''
            );

        $index =
            (int)(
                $_GET['index'] ?? 0
            );

        $player =
            get_player($token);

        if (!$player) {

            echo json_encode(
                [
                    'ok' => false,
                    'message' =>
                        'Player expired.'
                ]
            );

            return;
        }

        $userId =
            (string)$player['user_id'];

        $songs =
            $player['songs']
            ?? [];

        if (!isset($songs[$index])) {

            echo json_encode(
                [
                    'ok' => false,
                    'message' =>
                        'Song not found.'
                ]
            );

            return;
        }

        $queue =
            get_user_queue($userId);

        $queue[] =
            $songs[$index];

        save_user_queue(
            $userId,
            $queue
        );

        echo json_encode(
            [
                'ok' => true,
                'message' =>
                    '✅ Added to queue.'
            ]
        );

        return;
    }


    if (
        in_array(
            $action,
            [
                'admin_generate_keys',
                'admin_give',
                'admin_revoke',
                'admin_stats'
            ],
            true
        )
    ) {

        $initData =
            (string)(
                $_POST['initData']
                ?? ''
            );

        $verified =
            verify_webapp_init_data(
                $initData
            );

        if (
            !$verified ||
            empty($verified['user']['id']) ||
            !is_admin(
                $verified['user']['id']
            )
        ) {

            echo json_encode(
                [
                    'ok' => false,
                    'message' =>
                        'Unauthorized.'
                ]
            );

            return;
        }


        if (
            $action ===
            'admin_generate_keys'
        ) {

            $days =
                max(
                    1,
                    (int)(
                        $_POST['days']
                        ?? 30
                    )
                );

            $count =
                max(
                    1,
                    min(
                        20,
                        (int)(
                            $_POST['count']
                            ?? 1
                        )
                    )
                );

            $created = [];

            for ($i = 0; $i < $count; $i++) {

                $created[] =
                    generate_redeem_key(
                        $days,
                        ADMIN_ID
                    )['key'];
            }

            echo json_encode(
                [
                    'ok' => true,
                    'message' =>
                        "Generated {$count} key(s), {$days} days:\n\n" .
                        implode(
                            "\n",
                            $created
                        )
                ],
                JSON_UNESCAPED_UNICODE
            );

            return;
        }


        if (
            $action ===
            'admin_give'
        ) {

            $userId =
                trim(
                    (string)(
                        $_POST['user_id']
                        ?? ''
                    )
                );

            $days =
                max(
                    1,
                    (int)(
                        $_POST['days']
                        ?? 30
                    )
                );

            if (
                $userId === '' ||
                !get_user($userId)
            ) {

                echo json_encode(
                    [
                        'ok' => false,
                        'message' =>
                            'User not found.'
                    ]
                );

                return;
            }

            $user =
                get_user($userId);

            $expiry =
                max(
                    now(),
                    (int)(
                        $user['premium_until']
                        ?? 0
                    )
                ) +
                ($days * 86400);

            update_user(
                $userId,
                [
                    'premium_until' =>
                        $expiry
                ]
            );

            send_message(
                (int)$userId,
                "💎 <b>Premium Activated</b>\n\n" .
                "Admin granted you " .
                "<b>{$days} days</b>.\n\n" .
                "Valid until:\n<b>" .
                h(format_date($expiry)) .
                "</b>"
            );

            echo json_encode(
                [
                    'ok' => true,
                    'message' =>
                        'Premium activated until ' .
                        format_date($expiry)
                ]
            );

            return;
        }


        if (
            $action ===
            'admin_revoke'
        ) {

            $userId =
                trim(
                    (string)(
                        $_POST['user_id']
                        ?? ''
                    )
                );

            if (
                $userId === '' ||
                !get_user($userId)
            ) {

                echo json_encode(
                    [
                        'ok' => false,
                        'message' =>
                            'User not found.'
                    ]
                );

                return;
            }

            update_user(
                $userId,
                [
                    'premium_until' => 0
                ]
            );

            send_message(
                (int)$userId,
                "⚠️ <b>Premium Revoked</b>\n\n" .
                "Your MAYAMUSIC premium access has been revoked by admin."
            );

            echo json_encode(
                [
                    'ok' => true,
                    'message' =>
                        'Premium revoked.'
                ]
            );

            return;
        }


        if (
            $action ===
            'admin_stats'
        ) {

            $users =
                get_users();

            $payments =
                get_payments();

            $keys =
                get_keys();

            $active = 0;
            $pending = 0;
            $approved = 0;
            $unused = 0;

            foreach ($users as $u) {

                if (
                    !empty(
                        $u['premium_until']
                    ) &&
                    (int)$u['premium_until'] >
                    now()
                ) {
                    $active++;
                }
            }

            foreach ($payments as $p) {

                if (
                    ($p['status'] ?? '') ===
                    'pending'
                ) {
                    $pending++;
                }

                if (
                    ($p['status'] ?? '') ===
                    'approved'
                ) {
                    $approved++;
                }
            }

            foreach ($keys as $k) {

                if (
                    empty($k['used'])
                ) {
                    $unused++;
                }
            }

            $settings =
                get_settings();

            $message =
                "📊 MAYAMUSIC STATS\n\n" .
                "👥 Total Users: " .
                count($users) .
                "\n" .
                "💎 Active Premium: " .
                $active .
                "\n" .
                "💳 Pending Payments: " .
                $pending .
                "\n" .
                "✅ Approved Payments: " .
                $approved .
                "\n" .
                "🔑 Unused Keys: " .
                $unused .
                "\n" .
                "🔎 Searches: " .
                (int)(
                    $settings['total_searches']
                    ?? 0
                ) .
                "\n" .
                "▶️ Plays: " .
                (int)(
                    $settings['total_plays']
                    ?? 0
                );

            echo json_encode(
                [
                    'ok' => true,
                    'message' => $message
                ],
                JSON_UNESCAPED_UNICODE
            );

            return;
        }
    }


    echo json_encode(
        [
            'ok' => false,
            'message' => 'Unknown action.'
        ]
    );
}


/* =========================================================
   PAYMENT FORM SUBMISSION
========================================================= */

function handle_utr_submission(): void
{
    $paymentId =
        trim(
            (string)(
                $_POST['payment_id']
                ?? ''
            )
        );

    $utr =
        trim(
            (string)(
                $_POST['utr']
                ?? ''
            )
        );

    $payments =
        get_payments();

    if (
        $paymentId === '' ||
        !isset($payments[$paymentId])
    ) {

        payment_result_page(
            false,
            'Invalid payment ID.'
        );

        return;
    }

    if ($utr === '') {

        payment_result_page(
            false,
            'Please enter your UTR / transaction ID.'
        );

        return;
    }

    if (
        strlen($utr) < 4 ||
        strlen($utr) > 100
    ) {

        payment_result_page(
            false,
            'Invalid transaction ID.'
        );

        return;
    }

    if (
        ($payments[$paymentId]['status'] ?? '') !==
        'pending'
    ) {

        payment_result_page(
            false,
            'This payment is no longer pending.'
        );

        return;
    }

    $payments[$paymentId]['utr'] =
        $utr;

    $payments[$paymentId]['updated_at'] =
        now();

    write_json(
        PAYMENTS_FILE,
        $payments
    );

    $payment =
        $payments[$paymentId];

    $user =
        get_user(
            $payment['user_id']
        );

    $name =
        $user
        ? trim(
            ($user['first_name'] ?? '') .
            ' ' .
            ($user['last_name'] ?? '')
        )
        : 'Unknown';

    $adminText =
        "💳 <b>PAYMENT READY FOR REVIEW</b>\n\n" .

        "🆔 Payment: <code>" .
        h($paymentId) .
        "</code>\n" .

        "👤 User ID: <code>" .
        h((string)$payment['user_id']) .
        "</code>\n" .

        "👤 Name: " .
        h($name) .
        "\n" .

        "💰 Amount: " .
        money((float)$payment['amount']) .
        "\n" .

        "🏦 UPI: <code>" .
        h(UPI_ID) .
        "</code>\n" .

        "🧾 UTR: <code>" .
        h($utr) .
        "</code>\n\n" .

        "Approve or decline this payment.";

    send_message(
        ADMIN_ID,
        $adminText,
        [
            'inline_keyboard' => [

                [
                    [
                        'text' => '✅ APPROVE',
                        'callback_data' =>
                            'payapprove:' .
                            $paymentId
                    ],
                    [
                        'text' => '❌ DECLINE',
                        'callback_data' =>
                            'paydecline:' .
                            $paymentId
                    ]
                ]

            ]
        ]
    );

    payment_result_page(
        true,
        'UTR submitted successfully. Please wait for admin approval.'
    );
}


function payment_result_page(
    bool $success,
    string $message
): void {

    $icon =
        $success
        ? '✅'
        : '❌';

    echo '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport"
content="width=device-width,initial-scale=1">
<title>MAYAMUSIC</title>
<style>
body{
margin:0;
min-height:100vh;
display:flex;
align-items:center;
justify-content:center;
background:#090914;
color:white;
font-family:Arial;
padding:20px;
}
.card{
max-width:480px;
width:100%;
padding:30px;
border-radius:26px;
background:#151522;
text-align:center;
}
.icon{
font-size:55px;
}
p{
color:#aaa;
line-height:1.7;
}
</style>
</head>
<body>
<div class="card">
<div class="icon">' .
        $icon .
        '</div>
<h2>MAYAMUSIC</h2>
<p>' .
        h($message) .
        '</p>
</div>
</body>
</html>';
}


/* =========================================================
   WEBHOOK SETUP
========================================================= */

function set_webhook(): array
{
    return telegram(
        'setWebhook',
        [
            'url' => WEBAPP_URL
        ]
    );
}


function delete_webhook(): array
{
    return telegram(
        'deleteWebhook'
    );
}


/* =========================================================
   COMMAND HELP
========================================================= */

function help_text(
    int|string $userId
): string {

    $text =
        "🎵 <b>MAYAMUSIC HELP</b>\n\n" .

        "<b>Music</b>\n" .
        "/search song name\n" .
        "/play song name\n" .
        "/queue\n\n" .

        "<b>Premium</b>\n" .
        "/premium\n" .
        "/account\n" .
        "/redeem KEY\n" .
        "/support\n\n" .

        "<b>Channel / Group</b>\n" .
        "/playcc song name\n" .
        "/pausecc\n" .
        "/resumecc\n" .
        "/skipcc\n" .
        "/stopcc\n" .
        "/leavecc\n\n";

    if (is_admin($userId)) {

        $text .=
            "<b>Admin</b>\n" .
            "/stats\n" .
            "/pending\n" .
            "/genkey DAYS [COUNT]\n" .
            "/give USER_ID DAYS\n" .
            "/revoke USER_ID\n" .
            "/broadcast TEXT\n" .
            "/setwebhook\n" .
            "/delwebhook\n" .
            "/adminapp\n\n";
    }

    $text .=
        "💡 Channel / Group access is FREE.";

    return $text;
}


/* =========================================================
   PROCESS COMMAND
========================================================= */

function process_command(
    array $message,
    string $command,
    string $args
): void {

    $chat =
        $message['chat'];

    $chatId =
        $chat['id'];

    $from =
        $message['from']
        ?? [];

    ensure_user($from);


    /* START */

    if ($command === 'start') {

        $name =
            h(
                (string)(
                    $from['first_name']
                    ?? 'there'
                )
            );

        $text =
            "🎵 <b>Welcome to MAYAMUSIC, {$name}!</b>\n\n" .

            "Search music and open the Telegram Mini App player.\n\n" .

            "✨ <b>Features</b>\n" .
            "• Music search\n" .
            "• Album artwork\n" .
            "• Lyrics\n" .
            "• Auto-next\n" .
            "• Queue\n" .
            "• Previous / Next\n\n" .

            "💎 Private users need Premium.\n" .
            "🆓 Channel / Group access is FREE.";

        send_message(
            $chatId,
            $text,
            main_keyboard(
                $chatId,
                is_admin($chatId)
            )
        );

        return;
    }


    /* HELP */

    if ($command === 'help') {

        send_message(
            $chatId,
            help_text($chatId),
            main_keyboard(
                $chatId,
                is_admin($chatId)
            )
        );

        return;
    }


    /* SEARCH */

    if (
        in_array(
            $command,
            [
                'search',
                'play'
            ],
            true
        )
    ) {

        if (
            !chat_is_free_area($chat) &&
            !is_premium($chatId)
        ) {

            send_message(
                $chatId,
                "🔒 <b>Premium Required</b>\n\n" .
                "Private users need Premium to search music.\n\n" .
                "💎 ₹49 / 30 days",
                premium_keyboard()
            );

            return;
        }

        if ($args === '') {

            send_message(
                $chatId,
                "🎵 Usage:\n<code>/" .
                $command .
                " song name</code>"
            );

            return;
        }

        send_search_results(
            $chatId,
            $args
        );

        return;
    }


    /* PREMIUM */

    if ($command === 'premium') {

        send_message(
            $chatId,
            premium_text(),
            premium_keyboard()
        );

        return;
    }


    /* ACCOUNT */

    if ($command === 'account') {

        send_message(
            $chatId,
            account_text($chatId),
            [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '💎 Get Premium',
                            'callback_data' =>
                                'menu_premium'
                        ]
                    ],
                    [
                        [
                            'text' => '🔙 Main Menu',
                            'callback_data' =>
                                'main_menu'
                        ]
                    ]
                ]
            ]
        );

        return;
    }


    /* REDEEM */

    if ($command === 'redeem') {

        if ($args === '') {

            send_message(
                $chatId,
                "🔑 Usage:\n\n" .
                "<code>/redeem MAYA-XXXXX-XXXXX-XXXXX</code>"
            );

            return;
        }

        $result =
            redeem_key(
                $chatId,
                $args
            );

        send_message(
            $chatId,
            $result['message'],
            main_keyboard(
                $chatId,
                is_admin($chatId)
            )
        );

        return;
    }


    /* SUPPORT */

    if ($command === 'support') {

        send_message(
            $chatId,
            "🆘 <b>MAYAMUSIC Support</b>\n\n" .
            "Contact support for payment, premium or bot issues.",
            [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '💬 Contact Support',
                            'url' =>
                                'https://t.me/' .
                                ltrim(
                                    SUPPORT_USERNAME,
                                    '@'
                                )
                        ]
                    ],
                    [
                        [
                            'text' => '🔙 Main Menu',
                            'callback_data' =>
                                'main_menu'
                        ]
                    ]
                ]
            ]
        );

        return;
    }


    /* QUEUE */

    if ($command === 'queue') {

        $queue =
            get_user_queue(
                $chatId
            );

        if (!$queue) {

            send_message(
                $chatId,
                "📋 Your queue is empty."
            );

            return;
        }

        $text =
            "📋 <b>Your Queue</b>\n\n";

        foreach (
            array_slice(
                $queue,
                0,
                20
            ) as $i => $song
        ) {

            $text .=
                ($i + 1) .
                ". " .
                h(
                    (string)(
                        $song['title']
                        ?? 'Unknown'
                    )
                ) .
                " — " .
                h(
                    (string)(
                        $song['artists']
                        ?? 'Unknown'
                    )
                ) .
                "\n";
        }

        send_message(
            $chatId,
            $text
        );

        return;
    }


    /* =====================================================
       CHANNEL / GROUP VOICE COMMANDS
    ===================================================== */

    if (
        in_array(
            $command,
            [
                'playcc',
                'pausecc',
                'resumecc',
                'skipcc',
                'stopcc',
                'leavecc'
            ],
            true
        )
    ) {

        if (
            !chat_is_free_area($chat)
        ) {

            send_message(
                $chatId,
                "⚠️ These commands are intended for a Telegram group/channel."
            );

            return;
        }


        if ($command === 'playcc') {

            if ($args === '') {

                send_message(
                    $chatId,
                    "🎧 Usage:\n" .
                    "<code>/playcc song name</code>"
                );

                return;
            }

            $results =
                search_music($args);

            if (!$results) {

                send_message(
                    $chatId,
                    "❌ No music found."
                );

                return;
            }

            /*
             * The supplied music API does not expose
             * a rating field. Therefore we use its first
             * returned result rather than inventing a rating.
             */

            $song =
                normalize_song(
                    $results[0]
                );

            send_message(
                $chatId,
                "🎧 <b>VC Request</b>\n\n" .
                "🎵 " .
                h($song['title']) .
                "\n" .
                "👤 " .
                h($song['artists']) .
                "\n\n" .
                "ℹ️ Selected from the API's first result.\n\n" .
                "⚠️ PHP Telegram Bot API cannot itself join and stream audio into Telegram Voice Chat. Connect an external MTProto/voice engine to perform the actual VC playback."
            );

            return;
        }


        if ($command === 'pausecc') {

            send_message(
                $chatId,
                "⏸ <b>VC pause command received.</b>\n\n" .
                "Connect your MTProto/voice engine to execute the actual pause."
            );

            return;
        }


        if ($command === 'resumecc') {

            send_message(
                $chatId,
                "▶️ <b>VC resume command received.</b>\n\n" .
                "Connect your MTProto/voice engine to execute the actual resume."
            );

            return;
        }


        if ($command === 'skipcc') {

            send_message(
                $chatId,
                "⏭ <b>VC skip command received.</b>\n\n" .
                "Connect your MTProto/voice engine for actual VC control."
            );

            return;
        }


        if ($command === 'stopcc') {

            send_message(
                $chatId,
                "⏹ <b>VC stop command received.</b>"
            );

            return;
        }


        if ($command === 'leavecc') {

            send_message(
                $chatId,
                "👋 <b>VC leave command received.</b>\n\n" .
                "Actual voice-client leave requires the external VC engine."
            );

            return;
        }
    }


    /* =====================================================
       ADMIN COMMANDS
    ===================================================== */

    if (!is_admin($chatId)) {

        if (
            str_starts_with(
                $command,
                'admin'
            )
        ) {

            send_message(
                $chatId,
                '❌ Admin only.'
            );
        }

        return;
    }


    /* STATS */

    if ($command === 'stats') {

        send_message(
            $chatId,
            admin_text(),
            admin_keyboard()
        );

        return;
    }


    /* PENDING */

    if ($command === 'pending') {

        $payments =
            get_payments();

        $pending = [];

        foreach ($payments as $p) {

            if (
                ($p['status'] ?? '') ===
                'pending'
            ) {
                $pending[] = $p;
            }
        }

        if (!$pending) {

            send_message(
                $chatId,
                "✅ No pending payments."
            );

            return;
        }

        foreach ($pending as $p) {

            $text =
                "💳 <b>Pending Payment</b>\n\n" .
                "ID: <code>" .
                h($p['payment_id']) .
                "</code>\n" .
                "User: <code>" .
                h((string)$p['user_id']) .
                "</code>\n" .
                "Amount: " .
                money((float)$p['amount']) .
                "\n" .
                "UTR: <code>" .
                h(
                    (string)(
                        $p['utr']
                        ?? ''
                    )
                ) .
                "</code>";

            send_message(
                $chatId,
                $text,
                [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '✅ Approve',
                                'callback_data' =>
                                    'payapprove:' .
                                    $p['payment_id']
                            ],
                            [
                                'text' => '❌ Decline',
                                'callback_data' =>
                                    'paydecline:' .
                                    $p['payment_id']
                            ]
                        ]
                    ]
                ]
            );
        }

        return;
    }


    /* GENERATE KEY */

    if ($command === 'genkey') {

        $parts =
            preg_split(
                '/\s+/',
                trim($args)
            );

        $days =
            max(
                1,
                (int)(
                    $parts[0] ?? 30
                )
            );

        $count =
            max(
                1,
                min(
                    20,
                    (int)(
                        $parts[1] ?? 1
                    )
                )
            );

        $created = [];

        for ($i = 0; $i < $count; $i++) {

            $created[] =
                generate_redeem_key(
                    $days,
                    $chatId
                )['key'];
        }

        send_message(
            $chatId,
            "🔑 <b>Redeem Keys Generated</b>\n\n" .
            "Days: <b>" .
            $days .
            "</b>\n" .
            "Count: <b>" .
            $count .
            "</b>\n\n" .
            "<code>" .
            h(
                implode(
                    "\n",
                    $created
                )
            ) .
            "</code>"
        );

        return;
    }


    /* GIVE */

    if ($command === 'give') {

        $parts =
            preg_split(
                '/\s+/',
                trim($args)
            );

        $userId =
            (string)(
                $parts[0] ?? ''
            );

        $days =
            max(
                1,
                (int)(
                    $parts[1] ?? 30
                )
            );

        if (
            $userId === '' ||
            !get_user($userId)
        ) {

            send_message(
                $chatId,
                "❌ User not found."
            );

            return;
        }

        $user =
            get_user($userId);

        $expiry =
            max(
                now(),
                (int)(
                    $user['premium_until']
                    ?? 0
                )
            ) +
            ($days * 86400);

        update_user(
            $userId,
            [
                'premium_until' =>
                    $expiry
            ]
        );

        send_message(
            $chatId,
            "✅ Premium granted.\n\n" .
            "User: <code>" .
            h($userId) .
            "</code>\n" .
            "Days: <b>" .
            $days .
            "</b>\n" .
            "Until: <b>" .
            h(
                format_date($expiry)
            ) .
            "</b>"
        );

        send_message(
            (int)$userId,
            "💎 <b>Premium Activated</b>\n\n" .
            "Admin granted you " .
            "<b>" .
            $days .
            " days</b>.\n\n" .
            "Valid until:\n<b>" .
            h(
                format_date($expiry)
            ) .
            "</b>"
        );

        return;
    }


    /* REVOKE */

    if ($command === 'revoke') {

        $userId =
            trim($args);

        if (
            $userId === '' ||
            !get_user($userId)
        ) {

            send_message(
                $chatId,
                "❌ User not found."
            );

            return;
        }

        update_user(
            $userId,
            [
                'premium_until' => 0
            ]
        );

        send_message(
            $chatId,
            "🚫 Premium revoked for <code>" .
            h($userId) .
            "</code>."
        );

        send_message(
            (int)$userId,
            "⚠️ <b>Premium Revoked</b>\n\n" .
            "Your MAYAMUSIC Premium access has been revoked."
        );

        return;
    }


    /* BROADCAST */

    if ($command === 'broadcast') {

        $broadcast =
            trim($args);

        if ($broadcast === '') {

            send_message(
                $chatId,
                "Usage:\n<code>/broadcast Your message</code>"
            );

            return;
        }

        $users =
            get_users();

        $sent = 0;
        $failed = 0;

        foreach ($users as $user) {

            $result =
                send_message(
                    (int)$user['id'],
                    $broadcast
                );

            if (
                !empty($result['ok'])
            ) {
                $sent++;
            } else {
                $failed++;
            }

            usleep(100000);
        }

        send_message(
            $chatId,
            "📢 <b>Broadcast Finished</b>\n\n" .
            "✅ Sent: " .
            $sent .
            "\n" .
            "❌ Failed: " .
            $failed
        );

        return;
    }


    /* SET WEBHOOK */

    if ($command === 'setwebhook') {

        $result =
            set_webhook();

        send_message(
            $chatId,
            !empty($result['ok'])
                ? "✅ Webhook set to:\n<code>" .
                  h(WEBAPP_URL) .
                  "</code>"
                : "❌ Webhook failed:\n" .
                  h(
                      (string)(
                          $result['description']
                          ?? 'Unknown error'
                      )
                  )
        );

        return;
    }


    /* DELETE WEBHOOK */

    if ($command === 'delwebhook') {

        $result =
            delete_webhook();

        send_message(
            $chatId,
            !empty($result['ok'])
                ? "✅ Webhook deleted."
                : "❌ Failed:\n" .
                  h(
                      (string)(
                          $result['description']
                          ?? ''
                      )
                  )
        );

        return;
    }


    /* ADMIN APP */

    if ($command === 'adminapp') {

        send_message(
            $chatId,
            "⚙️ <b>MAYAMUSIC Admin Mini App</b>\n\n" .
            "Open the secure admin panel.",
            [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '⚙️ Open Admin Panel',
                            'web_app' => [
                                'url' =>
                                    WEBAPP_URL .
                                    '?action=adminapp'
                            ]
                        ]
                    ]
                ]
            ]
        );

        return;
    }
}


/* =========================================================
   CALLBACK HANDLER
========================================================= */

function process_callback(
    array $callback
): void {

    $id =
        (string)(
            $callback['id']
            ?? ''
        );

    $from =
        $callback['from']
        ?? [];

    $userId =
        (int)(
            $from['id']
            ?? 0
        );

    ensure_user($from);

    $data =
        (string)(
            $callback['data']
            ?? ''
        );

    $message =
        $callback['message']
        ?? [];

    $chatId =
        $message['chat']['id']
        ?? $userId;

    $messageId =
        (int)(
            $message['message_id']
            ?? 0
        );


    /* MAIN */

    if ($data === 'main_menu') {

        answer_callback(
            $id
        );

        edit_message(
            $chatId,
            $messageId,
            "🎵 <b>MAYAMUSIC</b>\n\n" .
            "Choose an option below.",
            main_keyboard(
                $userId,
                is_admin($userId)
            )
        );

        return;
    }


    /* SEARCH */

    if ($data === 'menu_search') {

        answer_callback(
            $id
        );

        send_message(
            $chatId,
            "🔎 <b>Search Music</b>\n\n" .
            "Send:\n" .
            "<code>/search song name</code>"
        );

        return;
    }


    /* ACCOUNT */

    if ($data === 'menu_account') {

        answer_callback(
            $id
        );

        edit_message(
            $chatId,
            $messageId,
            account_text($userId),
            [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '💎 Premium',
                            'callback_data' =>
                                'menu_premium'
                        ]
                    ],
                    [
                        [
                            'text' => '🔙 Main Menu',
                            'callback_data' =>
                                'main_menu'
                        ]
                    ]
                ]
            ]
        );

        return;
    }


    /* PREMIUM */

    if ($data === 'menu_premium') {

        answer_callback(
            $id
        );

        edit_message(
            $chatId,
            $messageId,
            premium_text(),
            premium_keyboard()
        );

        return;
    }


    /* BUY */

    if ($data === 'buy_premium') {

        answer_callback(
            $id
        );

        $payment =
            create_payment(
                $userId
            );

        $paymentId =
            $payment['payment_id'];

        $link =
            WEBAPP_URL .
            '?action=payment&payment=' .
            rawurlencode(
                $paymentId
            );

        notify_admin_payment(
            $payment
        );

        send_message(
            $chatId,
            "💳 <b>Premium Payment</b>\n\n" .
            "Amount: <b>₹49</b>\n" .
            "Validity: <b>30 days</b>\n\n" .
            "Open the secure payment page.\n" .
            "Pay using UPI and submit your UTR.",
            [
                'inline_keyboard' => [
                    [
                        [
                            'text' =>
                                '💳 Open Payment Page',
                            'web_app' => [
                                'url' => $link
                            ]
                        ]
                    ],
                    [
                        [
                            'text' =>
                                '🔙 Back',
                            'callback_data' =>
                                'menu_premium'
                        ]
                    ]
                ]
            ]
        );

        return;
    }


    /* REDEEM */

    if ($data === 'menu_redeem') {

        answer_callback(
            $id
        );

        send_message(
            $chatId,
            "🔑 <b>Redeem Premium Key</b>\n\n" .
            "Use:\n" .
            "<code>/redeem YOUR-KEY</code>"
        );

        return;
    }


    /* QUEUE */

    if ($data === 'menu_queue') {

        answer_callback(
            $id
        );

        $queue =
            get_user_queue(
                $userId
            );

        if (!$queue) {

            send_message(
                $chatId,
                "📋 Your queue is empty."
            );

            return;
        }

        $text =
            "📋 <b>Your Queue</b>\n\n";

        foreach (
            array_slice(
                $queue,
                0,
                20
            ) as $i => $song
        ) {

            $text .=
                ($i + 1) .
                ". " .
                h(
                    (string)(
                        $song['title']
                        ?? 'Unknown'
                    )
                ) .
                "\n";
        }

        send_message(
            $chatId,
            $text
        );

        return;
    }


    /* SUPPORT */

    if ($data === 'menu_support') {

        answer_callback(
            $id
        );

        send_message(
            $chatId,
            "🆘 <b>MAYAMUSIC Support</b>\n\n" .
            "For payment, premium or technical support:",
            [
                'inline_keyboard' => [
                    [
                        [
                            'text' =>
                                '💬 Contact Support',
                            'url' =>
                                'https://t.me/' .
                                ltrim(
                                    SUPPORT_USERNAME,
                                    '@'
                                )
                        ]
                    ]
                ]
            ]
        );

        return;
    }


    /* ADMIN */

    if (
        str_starts_with(
            $data,
            'admin_'
        ) &&
        !is_admin($userId)
    ) {

        answer_callback(
            $id,
            'Admin only.',
            true
        );

        return;
    }


    if ($data === 'menu_admin') {

        if (!is_admin($userId)) {

            answer_callback(
                $id,
                'Admin only.',
                true
            );

            return;
        }

        answer_callback(
            $id
        );

        edit_message(
            $chatId,
            $messageId,
            admin_text(),
            admin_keyboard()
        );

        return;
    }


    if ($data === 'admin_stats') {

        answer_callback(
            $id
        );

        $users =
            get_users();

        $payments =
            get_payments();

        $keys =
            get_keys();

        $active = 0;
        $pending = 0;
        $approved = 0;
        $unused = 0;

        foreach ($users as $u) {

            if (
                (int)(
                    $u['premium_until']
                    ?? 0
                ) > now()
            ) {
                $active++;
            }
        }

        foreach ($payments as $p) {

            if (
                ($p['status'] ?? '') ===
                'pending'
            ) {
                $pending++;
            }

            if (
                ($p['status'] ?? '') ===
                'approved'
            ) {
                $approved++;
            }
        }

        foreach ($keys as $k) {

            if (
                empty($k['used'])
            ) {
                $unused++;
            }
        }

        edit_message(
            $chatId,
            $messageId,
            "📊 <b>MAYAMUSIC Statistics</b>\n\n" .
            "👥 Users: <b>" .
            count($users) .
            "</b>\n" .
            "💎 Active Premium: <b>" .
            $active .
            "</b>\n" .
            "💳 Pending Payments: <b>" .
            $pending .
            "</b>\n" .
            "✅ Approved Payments: <b>" .
            $approved .
            "</b>\n" .
            "🔑 Unused Keys: <b>" .
            $unused .
            "</b>",
            admin_keyboard()
        );

        return;
    }


    if ($data === 'admin_key') {

        answer_callback(
            $id
        );

        send_message(
            $chatId,
            "🔑 <b>Generate Redeem Key</b>\n\n" .
            "Quick examples:\n\n" .
            "<code>/genkey 7</code>\n" .
            "<code>/genkey 30</code>\n" .
            "<code>/genkey 90 5</code>\n\n" .
            "Or use the Admin Mini App for custom expiry."
        );

        return;
    }


    if ($data === 'admin_users') {

        answer_callback(
            $id
        );

        $users =
            get_users();

        $text =
            "👥 <b>Users</b>\n\n";

        $shown = 0;

        foreach (
            array_reverse($users, true)
            as $u
        ) {

            if ($shown >= 30) {
                break;
            }

            $text .=
                "<code>" .
                h(
                    (string)$u['id']
                ) .
                "</code> " .
                h(
                    trim(
                        ($u['first_name'] ?? '') .
                        ' ' .
                        ($u['last_name'] ?? '')
                    )
                ) .
                "\n";

            $shown++;
        }

        edit_message(
            $chatId,
            $messageId,
            $text,
            admin_keyboard()
        );

        return;
    }


    /* PAYMENT APPROVE */

    if (
        str_starts_with(
            $data,
            'payapprove:'
        )
    ) {

        if (!is_admin($userId)) {

            answer_callback(
                $id,
                'Admin only.',
                true
            );

            return;
        }

        $paymentId =
            substr(
                $data,
                strlen('payapprove:')
            );

        $result =
            approve_payment(
                $paymentId
            );

        answer_callback(
            $id,
            $result,
            false
        );

        if ($messageId) {

            edit_message(
                $chatId,
                $messageId,
                "✅ <b>PAYMENT APPROVED</b>\n\n" .
                h($result)
            );
        }

        return;
    }


    /* PAYMENT DECLINE */

    if (
        str_starts_with(
            $data,
            'paydecline:'
        )
    ) {

        if (!is_admin($userId)) {

            answer_callback(
                $id,
                'Admin only.',
                true
            );

            return;
        }

        $paymentId =
            substr(
                $data,
                strlen('paydecline:')
            );

        $result =
            decline_payment(
                $paymentId
            );

        answer_callback(
            $id,
            $result,
            false
        );

        if ($messageId) {

            edit_message(
                $chatId,
                $messageId,
                "❌ <b>PAYMENT DECLINED</b>\n\n" .
                h($result)
            );
        }

        return;
    }


    /* ADD QUEUE */

    if (
        str_starts_with(
            $data,
            'addq:'
        )
    ) {

        $token =
            substr(
                $data,
                5
            );

        $player =
            get_player($token);

        if (!$player) {

            answer_callback(
                $id,
                'Player expired.',
                true
            );

            return;
        }

        $songs =
            $player['songs']
            ?? [];

        if (!$songs) {

            answer_callback(
                $id,
                'No songs.',
                true
            );

            return;
        }

        $queue =
            get_user_queue(
                $userId
            );

        foreach ($songs as $song) {

            $queue[] =
                $song;
        }

        save_user_queue(
            $userId,
            $queue
        );

        answer_callback(
            $id,
            'Added to queue.'
        );

        return;
    }


    answer_callback(
        $id
    );
}


/* =========================================================
   PROCESS TELEGRAM UPDATE
========================================================= */

function process_update(
    array $update
): void {

    if (
        isset(
            $update['callback_query']
        )
    ) {

        process_callback(
            $update['callback_query']
        );

        return;
    }


    if (
        isset(
            $update['pre_checkout_query']
        )
    ) {

        telegram(
            'answerPreCheckoutQuery',
            [
                'pre_checkout_query_id' =>
                    $update[
                        'pre_checkout_query'
                    ]['id'],

                'ok' => false,

                'error_message' =>
                    'MAYAMUSIC uses UPI payment instead of Telegram Stars.'
            ]
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

    $text =
        trim(
            (string)(
                $message['text']
                ?? ''
            )
        );

    if ($text === '') {
        return;
    }

    $parts =
        preg_split(
            '/\s+/',
            $text,
            2
        );

    $rawCommand =
        $parts[0]
        ?? '';

    $args =
        trim(
            $parts[1]
            ?? ''
        );

    if (
        !str_starts_with(
            $rawCommand,
            '/'
        )
    ) {

        /*
         * Allow plain text search for
         * private premium users.
         */

        $chat =
            $message['chat']
            ?? [];

        $chatId =
            $chat['id']
            ?? 0;

        ensure_user(
            $message['from']
            ?? []
        );

        if (
            chat_is_free_area($chat) ||
            is_premium($chatId)
        ) {

            send_search_results(
                $chatId,
                $text
            );

        } else {

            send_message(
                $chatId,
                "🔒 Private music search requires Premium.\n\n" .
                "💎 ₹49 / 30 days",
                premium_keyboard()
            );
        }

        return;
    }


    $rawCommand =
        substr(
            $rawCommand,
            1
        );

    /*
     * Handle /start@botusername
     */
    if (
        str_contains(
            $rawCommand,
            '@'
        )
    ) {

        $rawCommand =
            explode(
                '@',
                $rawCommand
            )[0];
    }

    $command =
        strtolower(
            $rawCommand
        );

    process_command(
        $message,
        $command,
        $args
    );
}


/* =========================================================
   WEB ROUTES
========================================================= */


/*
 * Payment page
 */
if (
    isset($_GET['action']) &&
    $_GET['action'] === 'payment'
) {

    $paymentId =
        trim(
            (string)(
                $_GET['payment']
                ?? ''
            )
        );

    $payments =
        get_payments();

    if (
        $paymentId === '' ||
        !isset($payments[$paymentId])
    ) {

        http_response_code(404);

        echo 'Invalid payment.';

        exit;
    }

    echo payment_page(
        $paymentId
    );

    exit;
}


/*
 * UTR submission
 */
if (
    isset($_GET['action']) &&
    $_GET['action'] === 'submit_utr' &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    handle_utr_submission();

    exit;
}


/*
 * Mini App player
 */
if (
    isset($_GET['action']) &&
    $_GET['action'] === 'player'
) {

    $token =
        trim(
            (string)(
                $_GET['token']
                ?? ''
            )
        );

    echo player_app(
        $token
    );

    exit;
}


/*
 * Admin Mini App
 */
if (
    isset($_GET['action']) &&
    $_GET['action'] === 'adminapp' &&
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {

    echo admin_app();

    exit;
}


/*
 * AJAX/API
 */
if (
    isset($_GET['action']) &&
    in_array(
        $_GET['action'],
        [
            'lyrics',
            'queue_add'
        ],
        true
    )
) {

    handle_webapp_api();

    exit;
}


/*
 * POST admin Mini App API
 */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'])
) {

    handle_webapp_api();

    exit;
}


/* =========================================================
   TELEGRAM WEBHOOK
========================================================= */

$rawInput =
    file_get_contents(
        'php://input'
    );

if (
    $rawInput !== false &&
    trim($rawInput) !== ''
) {

    $update =
        json_decode(
            $rawInput,
            true
        );

    if (is_array($update)) {

        process_update(
            $update
        );
    }

    http_response_code(200);

    echo 'OK';

    exit;
}


/* =========================================================
   DIRECT ACCESS
========================================================= */

header(
    'Content-Type: text/html; charset=utf-8'
);

echo '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport"
content="width=device-width,initial-scale=1">
<title>MAYAMUSIC</title>

<style>

body{
margin:0;
min-height:100vh;
display:flex;
align-items:center;
justify-content:center;
background:
radial-gradient(circle at top,#43206c,#08080f 65%);
color:#fff;
font-family:Arial,sans-serif;
}

.card{
width:min(90%,500px);
padding:35px;
border-radius:28px;
background:rgba(255,255,255,.07);
border:1px solid rgba(255,255,255,.1);
text-align:center;
backdrop-filter:blur(20px);
box-shadow:0 30px 80px rgba(0,0,0,.5);
}

.logo{
font-size:55px;
margin-bottom:15px;
}

h1{
margin:0 0 10px;
}

p{
color:#aaa;
line-height:1.7;
}

</style>

</head>

<body>

<div class="card">

<div class="logo">🎵</div>

<h1>MAYAMUSIC</h1>

<p>
Telegram Music Bot is online.
</p>

<p>
Search music, open the Telegram Mini App,
listen with lyrics, queue and auto-next.
</p>

</div>

</body>
</html>';

?>
