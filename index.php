<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| MAYAMUSIC - Single File Telegram Music Bot
|--------------------------------------------------------------------------
| PHP 8+
| Railway compatible
|
| ONLY REQUIRED SECRET:
| BOT_TOKEN
|
| Railway recommended environment variables:
| BOT_TOKEN=YOUR_NEW_BOT_TOKEN
|
|--------------------------------------------------------------------------
*/

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/* =========================================================
   CONFIGURATION
   ========================================================= */

const BOT_TOKEN = '8817347840:AAFpsNeTkzHqjnlqkV_18AjMEgIX-FXHmQo';

const BOT_NAME = 'MAYAMUSIC';
const BOT_USERNAME = 'MayaMusicDownload_BOT';

const ADMIN_ID = 8897821078;
const SUPPORT_USERNAME = '@HyperxVicky';

const WEBAPP_URL =
    'https://hosting-production-aacd.up.railway.app/index.php';

const MUSIC_API =
    'https://music-search-api-frnb.vercel.app/search?song=';

const MONTHLY_PRICE = 49;
const PREMIUM_DAYS = 30;

const UPI_ID = 'vickybanna8674@ybl';
const UPI_NAME = 'MAYAMUSIC';

const DATA_DIR = __DIR__ . '/data';

const USERS_FILE    = DATA_DIR . '/users.json';
const PAYMENTS_FILE = DATA_DIR . '/payments.json';
const KEYS_FILE     = DATA_DIR . '/keys.json';
const PLAYERS_FILE  = DATA_DIR . '/players.json';
const QUEUES_FILE   = DATA_DIR . '/queues.json';
const LOG_FILE      = DATA_DIR . '/bot.log';

/* =========================================================
   BASIC HELPERS
   ========================================================= */

function boot(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }

    $files = [
        USERS_FILE,
        PAYMENTS_FILE,
        KEYS_FILE,
        PLAYERS_FILE,
        QUEUES_FILE
    ];

    foreach ($files as $file) {
        if (!file_exists($file)) {
            @file_put_contents($file, '{}', LOCK_EX);
        }
    }
}

boot();

function logMessage(string $message): void
{
    @file_put_contents(
        LOG_FILE,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function loadJson(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $raw = @file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function saveJson(string $file, array $data): bool
{
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    return @file_put_contents(
        $file,
        $json,
        LOCK_EX
    ) !== false;
}

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

function randomId(int $length = 16): string
{
    return substr(
        bin2hex(random_bytes(max(8, (int)ceil($length / 2)))),
        0,
        $length
    );
}

function isAdmin(int|string $id): bool
{
    return (int)$id === ADMIN_ID;
}

function isPrivateChat(array $chat): bool
{
    return ($chat['type'] ?? '') === 'private';
}

function isGroupChat(array $chat): bool
{
    return in_array(
        $chat['type'] ?? '',
        ['group', 'supergroup', 'channel'],
        true
    );
}

/* =========================================================
   TELEGRAM API
   ========================================================= */

function telegram(string $method, array $params = []): array
{
    $token = getenv('BOT_TOKEN') ?: BOT_TOKEN;

    if (
        $token === '' ||
        $token === 'PASTE_NEW_BOT_TOKEN_HERE'
    ) {
        return [
            'ok' => false,
            'description' => 'BOT_TOKEN is not configured'
        ];
    }

    $url = 'https://api.telegram.org/bot' .
        $token . '/' . $method;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        logMessage('Telegram cURL error: ' . $error);

        return [
            'ok' => false,
            'description' => $error
        ];
    }

    curl_close($ch);

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return [
            'ok' => false,
            'description' => 'Invalid Telegram response'
        ];
    }

    return $data;
}

function sendMessage(
    int|string $chatId,
    string $text,
    ?array $keyboard = null,
    string $parseMode = 'HTML'
): array {
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true
    ];

    if ($parseMode !== '') {
        $params['parse_mode'] = $parseMode;
    }

    if ($keyboard !== null) {
        $params['reply_markup'] = json_encode(
            $keyboard,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }

    return telegram('sendMessage', $params);
}

function answerCallback(
    string $callbackId,
    string $text = '',
    bool $alert = false
): array {
    return telegram('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text,
        'show_alert' => $alert
    ]);
}

function editMessage(
    int|string $chatId,
    int $messageId,
    string $text,
    ?array $keyboard = null
): array {
    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];

    if ($keyboard !== null) {
        $params['reply_markup'] = json_encode(
            $keyboard,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
    }

    return telegram('editMessageText', $params);
}

/* =========================================================
   USERS
   ========================================================= */

function getUser(int $userId): array
{
    $users = loadJson(USERS_FILE);

    $key = (string)$userId;

    if (!isset($users[$key])) {
        $users[$key] = [
            'id' => $userId,
            'username' => '',
            'first_name' => '',
            'premium_until' => 0,
            'created_at' => now(),
            'last_seen' => now()
        ];

        saveJson(USERS_FILE, $users);
    }

    return $users[$key];
}

function saveUser(array $user): void
{
    $users = loadJson(USERS_FILE);

    $users[(string)$user['id']] = $user;

    saveJson(USERS_FILE, $users);
}

function updateTelegramUser(array $from): array
{
    $id = (int)($from['id'] ?? 0);

    $user = getUser($id);

    $user['username'] = $from['username'] ?? '';
    $user['first_name'] = $from['first_name'] ?? '';
    $user['last_seen'] = now();

    saveUser($user);

    return $user;
}

function premiumActive(array $user): bool
{
    return (int)($user['premium_until'] ?? 0) > now();
}

function premiumUntilText(array $user): string
{
    $until = (int)($user['premium_until'] ?? 0);

    if ($until <= now()) {
        return 'Not active';
    }

    return date('d M Y, h:i A', $until);
}

function activatePremium(
    int $userId,
    int $days = PREMIUM_DAYS
): array {
    $user = getUser($userId);

    $current = max(
        now(),
        (int)($user['premium_until'] ?? 0)
    );

    $user['premium_until'] =
        $current + ($days * 86400);

    saveUser($user);

    return $user;
}

/* =========================================================
   KEY SYSTEM
   ========================================================= */

function generateRedeemKey(int $days): array
{
    $keys = loadJson(KEYS_FILE);

    do {
        $key =
            'MAYA-' .
            strtoupper(randomId(5)) .
            '-' .
            strtoupper(randomId(5));
    } while (isset($keys[$key]));

    $keys[$key] = [
        'key' => $key,
        'days' => $days,
        'created_at' => now(),
        'created_by' => ADMIN_ID,
        'used' => false,
        'used_by' => null,
        'used_at' => null
    ];

    saveJson(KEYS_FILE, $keys);

    return $keys[$key];
}

function redeemKey(
    int $userId,
    string $rawKey
): array {
    $key = strtoupper(trim($rawKey));

    $keys = loadJson(KEYS_FILE);

    if (!isset($keys[$key])) {
        return [
            'ok' => false,
            'message' => '❌ Invalid redeem key.'
        ];
    }

    if (($keys[$key]['used'] ?? false) === true) {
        return [
            'ok' => false,
            'message' => '❌ This key has already been used.'
        ];
    }

    $days = max(
        1,
        (int)($keys[$key]['days'] ?? 30)
    );

    $user = activatePremium($userId, $days);

    $keys[$key]['used'] = true;
    $keys[$key]['used_by'] = $userId;
    $keys[$key]['used_at'] = now();

    saveJson(KEYS_FILE, $keys);

    return [
        'ok' => true,
        'message' =>
            "✅ <b>Premium Activated</b>\n\n" .
            "Plan: {$days} days\n" .
            "Valid until: <b>" .
            h(premiumUntilText($user)) .
            "</b>"
    ];
}

/* =========================================================
   MUSIC API
   ========================================================= */

function httpGet(string $url, int $timeout = 20): ?string
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'MAYAMUSIC/1.0'
    ]);

    $data = curl_exec($ch);

    if ($data === false) {
        logMessage(
            'HTTP error: ' . curl_error($ch)
        );

        curl_close($ch);

        return null;
    }

    curl_close($ch);

    return $data;
}

function searchMusic(string $query): array
{
    $query = trim($query);

    if ($query === '') {
        return [];
    }

    $url =
        MUSIC_API .
        urlencode($query);

    $raw = httpGet($url, 20);

    if ($raw === null) {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return [];
    }

    $results = $data['results'] ?? [];

    if (!is_array($results)) {
        return [];
    }

    $clean = [];

    foreach ($results as $item) {
        if (!is_array($item)) {
            continue;
        }

        $title = trim(
            (string)($item['title'] ?? '')
        );

        $artist = trim(
            (string)($item['artists'] ?? '')
        );

        $download = trim(
            (string)($item['download_url'] ?? '')
        );

        if ($title === '' || $download === '') {
            continue;
        }

        $clean[] = [
            'title' => $title,
            'artists' => $artist !== ''
                ? $artist
                : 'Unknown Artist',
            'album' => (string)(
                $item['album'] ?? ''
            ),
            'duration' => (string)(
                $item['duration'] ?? ''
            ),
            'download_url' => $download
        ];
    }

    return $clean;
}

/* =========================================================
   ARTWORK
   ========================================================= */

function getArtwork(
    string $title,
    string $artist = ''
): string {
    $term = trim(
        $title . ' ' . $artist
    );

    $url =
        'https://itunes.apple.com/search?' .
        http_build_query([
            'term' => $term,
            'media' => 'music',
            'entity' => 'song',
            'limit' => 1
        ]);

    $raw = httpGet($url, 12);

    if ($raw !== null) {
        $data = json_decode($raw, true);

        if (
            is_array($data) &&
            !empty($data['results'][0])
        ) {
            $art = (string)(
                $data['results'][0]['artworkUrl100'] ?? ''
            );

            if ($art !== '') {
                return str_replace(
                    '100x100bb',
                    '600x600bb',
                    $art
                );
            }
        }
    }

    return '';
}

/* =========================================================
   LYRICS
   ========================================================= */

function getLyrics(
    string $title,
    string $artist = ''
): array {
    $url =
        'https://lrclib.net/api/search?' .
        http_build_query([
            'track_name' => $title,
            'artist_name' => $artist
        ]);

    $raw = httpGet($url, 15);

    if ($raw === null) {
        return [
            'plain' => '',
            'synced' => ''
        ];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        return [
            'plain' => '',
            'synced' => ''
        ];
    }

    foreach ($data as $item) {
        if (!is_array($item)) {
            continue;
        }

        $synced = trim(
            (string)(
                $item['syncedLyrics'] ?? ''
            )
        );

        $plain = trim(
            (string)(
                $item['plainLyrics'] ?? ''
            )
        );

        if ($synced !== '' || $plain !== '') {
            return [
                'plain' => $plain,
                'synced' => $synced
            ];
        }
    }

    return [
        'plain' => '',
        'synced' => ''
    ];
}

/* =========================================================
   PLAYER TOKENS
   ========================================================= */

function createPlayer(
    int $userId,
    array $songs,
    int $index = 0
): string {
    $players = loadJson(PLAYERS_FILE);

    $token = randomId(32);

    $players[$token] = [
        'user_id' => $userId,
        'songs' => array_values($songs),
        'index' => max(
            0,
            min(
                $index,
                max(0, count($songs) - 1)
            )
        ),
        'created_at' => now()
    ];

    saveJson(PLAYERS_FILE, $players);

    return $token;
}

function getPlayer(string $token): ?array
{
    $players = loadJson(PLAYERS_FILE);

    if (!isset($players[$token])) {
        return null;
    }

    return $players[$token];
}

/* =========================================================
   URLS / KEYBOARDS
   ========================================================= */

function playerUrl(string $token): string
{
    return WEBAPP_URL .
        '?action=player&token=' .
        urlencode($token);
}

function mainKeyboard(bool $premium = false): array
{
    return [
        'inline_keyboard' => [
            [
                [
                    'text' => '🎵 Search Music',
                    'callback_data' => 'search'
                ],
                [
                    'text' => '▶️ Player',
                    'callback_data' => 'player'
                ]
            ],
            [
                [
                    'text' => '💎 Premium',
                    'callback_data' => 'premium'
                ],
                [
                    'text' => '👤 Account',
                    'callback_data' => 'account'
                ]
            ],
            [
                [
                    'text' => '🔑 Redeem',
                    'callback_data' => 'redeem'
                ],
                [
                    'text' => '🎧 Queue',
                    'callback_data' => 'queue'
                ]
            ],
            [
                [
                    'text' => '🆘 Support',
                    'url' =>
                        'https://t.me/' .
                        ltrim(SUPPORT_USERNAME, '@')
                ]
            ]
        ]
    ];
}

function premiumKeyboard(): array
{
    $upiLink =
        'upi://pay?' .
        http_build_query([
            'pa' => UPI_ID,
            'pn' => UPI_NAME,
            'am' => number_format(
                MONTHLY_PRICE,
                2,
                '.',
                ''
            ),
            'cu' => 'INR',
            'tn' => BOT_NAME . ' Premium'
        ]);

    return [
        'inline_keyboard' => [
            [
                [
                    'text' =>
                        '💳 Pay ₹' .
                        MONTHLY_PRICE,
                    'url' => $upiLink
                ]
            ],
            [
                [
                    'text' =>
                        '🧾 Submit UTR',
                    'callback_data' => 'submitutr'
                ]
            ],
            [
                [
                    'text' =>
                        '👤 Account',
                    'callback_data' => 'account'
                ]
            ]
        ]
    ];
}

/* =========================================================
   PAYMENT SYSTEM
   ========================================================= */

function createPayment(int $userId): array
{
    $payments = loadJson(PAYMENTS_FILE);

    $id =
        'PAY-' .
        strtoupper(randomId(8));

    $payments[$id] = [
        'id' => $id,
        'user_id' => $userId,
        'amount' => MONTHLY_PRICE,
        'status' => 'pending',
        'utr' => '',
        'created_at' => now(),
        'updated_at' => now()
    ];

    saveJson(PAYMENTS_FILE, $payments);

    return $payments[$id];
}

function getPayment(string $id): ?array
{
    $payments = loadJson(PAYMENTS_FILE);

    return $payments[$id] ?? null;
}

function savePayment(array $payment): void
{
    $payments = loadJson(PAYMENTS_FILE);

    $payments[$payment['id']] = $payment;

    saveJson(PAYMENTS_FILE, $payments);
}

function submitUtr(
    int $userId,
    string $paymentId,
    string $utr
): bool {
    $payment = getPayment($paymentId);

    if ($payment === null) {
        return false;
    }

    if ((int)$payment['user_id'] !== $userId) {
        return false;
    }

    if (($payment['status'] ?? '') !== 'pending') {
        return false;
    }

    $utr = trim($utr);

    if ($utr === '' || strlen($utr) > 100) {
        return false;
    }

    $payment['utr'] = $utr;
    $payment['status'] = 'waiting_review';
    $payment['updated_at'] = now();

    savePayment($payment);

    return true;
}

function approvePayment(string $paymentId): bool
{
    $payment = getPayment($paymentId);

    if ($payment === null) {
        return false;
    }

    if (
        !in_array(
            $payment['status'] ?? '',
            ['pending', 'waiting_review'],
            true
        )
    ) {
        return false;
    }

    $payment['status'] = 'approved';
    $payment['updated_at'] = now();

    savePayment($payment);

    $user = activatePremium(
        (int)$payment['user_id'],
        PREMIUM_DAYS
    );

    sendMessage(
        (int)$payment['user_id'],
        "🎉 <b>Premium Activated!</b>\n\n" .
        "Plan: ₹" . MONTHLY_PRICE . " / " .
        PREMIUM_DAYS . " days\n" .
        "Valid until: <b>" .
        h(premiumUntilText($user)) .
        "</b>\n\n" .
        "Enjoy MAYAMUSIC Premium 🎵"
    );

    return true;
}

function declinePayment(string $paymentId): bool
{
    $payment = getPayment($paymentId);

    if ($payment === null) {
        return false;
    }

    $payment['status'] = 'declined';
    $payment['updated_at'] = now();

    savePayment($payment);

    sendMessage(
        (int)$payment['user_id'],
        "❌ <b>Payment Declined</b>\n\n" .
        "Your payment request was not approved.\n" .
        "Please contact " .
        h(SUPPORT_USERNAME) .
        " if you believe this is an error."
    );

    return true;
}

/* =========================================================
   PREMIUM PAGE
   ========================================================= */

function premiumPage(int $userId): string
{
    $payment = createPayment($userId);

    $upiLink =
        'upi://pay?' .
        http_build_query([
            'pa' => UPI_ID,
            'pn' => UPI_NAME,
            'am' => number_format(
                MONTHLY_PRICE,
                2,
                '.',
                ''
            ),
            'cu' => 'INR',
            'tn' => BOT_NAME . ' ' . $payment['id']
        ]);

    return '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport"
 content="width=device-width,initial-scale=1">
<title>MAYAMUSIC Premium</title>
<style>
*{box-sizing:border-box}
body{
 margin:0;
 font-family:system-ui,-apple-system,BlinkMacSystemFont,
 "Segoe UI",sans-serif;
 background:
 radial-gradient(circle at 20% 0%,#34205d 0,#11121c 40%,#07080d 100%);
 color:#fff;
 min-height:100vh;
 padding:20px;
}
.card{
 max-width:520px;
 margin:30px auto;
 padding:26px;
 border:1px solid rgba(255,255,255,.12);
 border-radius:28px;
 background:rgba(255,255,255,.07);
 backdrop-filter:blur(22px);
 box-shadow:0 30px 80px rgba(0,0,0,.4);
}
.logo{
 width:68px;height:68px;
 display:grid;place-items:center;
 border-radius:20px;
 background:linear-gradient(135deg,#9b5cff,#ff4f9a);
 font-size:30px;
 margin-bottom:18px;
}
h1{margin:0 0 7px;font-size:30px}
.sub{opacity:.7;line-height:1.5}
.price{
 font-size:46px;
 font-weight:800;
 margin:24px 0 4px;
}
.small{opacity:.6}
.feature{
 padding:13px 0;
 border-bottom:1px solid rgba(255,255,255,.08);
}
.btn{
 display:block;
 width:100%;
 border:0;
 border-radius:16px;
 padding:16px;
 margin-top:18px;
 text-align:center;
 text-decoration:none;
 color:white;
 background:linear-gradient(135deg,#8f52ff,#ff4e9c);
 font-weight:800;
 font-size:16px;
}
.form{
 margin-top:22px;
}
input{
 width:100%;
 padding:15px;
 border-radius:14px;
 border:1px solid rgba(255,255,255,.14);
 background:rgba(0,0,0,.25);
 color:#fff;
 outline:none;
}
button{
 width:100%;
 border:0;
 padding:15px;
 margin-top:10px;
 border-radius:14px;
 background:#fff;
 color:#111;
 font-weight:800;
}
.notice{
 margin-top:16px;
 padding:14px;
 border-radius:14px;
 background:rgba(255,255,255,.06);
 color:#ddd;
 font-size:13px;
 line-height:1.5;
}
</style>
</head>
<body>
<div class="card">
<div class="logo">🎵</div>
<h1>MAYAMUSIC Premium</h1>
<div class="sub">
Unlock the private-user premium music experience.
</div>

<div class="price">₹49</div>
<div class="small">30 days access</div>

<div class="feature">✓ Full music streaming</div>
<div class="feature">✓ Telegram Mini App player</div>
<div class="feature">✓ Album artwork</div>
<div class="feature">✓ Lyrics</div>
<div class="feature">✓ Auto-next queue</div>
<div class="feature">✓ Previous / Next controls</div>
<div class="feature">✓ Saved player session</div>
<div class="feature">✓ Premium account access</div>

<a class="btn"
 href="' . h($upiLink) . '">
💳 Open UPI & Pay ₹49
</a>

<div class="form">
<form method="post"
 action="?action=submit_utr">
<input type="hidden"
 name="payment_id"
 value="' . h($payment['id']) . '">

<input
 name="utr"
 maxlength="100"
 placeholder="Enter UTR / Transaction ID"
 required>

<button type="submit">
Submit Payment
</button>
</form>
</div>

<div class="notice">
UPI ID: <b>' .
h(UPI_ID) .
'</b><br>
Payment is manually verified by the administrator.
After approval, premium will be activated for 30 days.
</div>
</div>
</body>
</html>';
}

/* =========================================================
   PLAYER PAGE
   ========================================================= */

function playerPage(string $token): string
{
    $player = getPlayer($token);

    if ($player === null) {
        return '<h2>Player session expired.</h2>';
    }

    $songs = $player['songs'] ?? [];

    $safeSongs = [];

    foreach ($songs as $song) {
        $title = (string)(
            $song['title'] ?? ''
        );

        $artist = (string)(
            $song['artists'] ?? ''
        );

        $safeSongs[] = [
            'title' => $title,
            'artist' => $artist,
            'album' => (string)(
                $song['album'] ?? ''
            ),
            'duration' => (string)(
                $song['duration'] ?? ''
            ),
            'url' => (string)(
                $song['download_url'] ?? ''
            ),
            'artwork' => getArtwork(
                $title,
                $artist
            )
        ];
    }

    $json = json_encode(
        $safeSongs,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    return '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport"
 content="width=device-width,initial-scale=1,
 maximum-scale=1,user-scalable=no">
<title>MAYAMUSIC Player</title>

<style>
*{box-sizing:border-box}
html,body{
 margin:0;
 width:100%;
 min-height:100%;
 background:#08090d;
 color:#fff;
 font-family:system-ui,-apple-system,
 BlinkMacSystemFont,"Segoe UI",sans-serif;
}
body{
 overflow-x:hidden;
}
.bg{
 position:fixed;
 inset:0;
 background:
 radial-gradient(circle at 50% 10%,
 rgba(151,82,255,.32),transparent 35%),
 radial-gradient(circle at 0% 100%,
 rgba(255,65,145,.16),transparent 40%);
 pointer-events:none;
}
.app{
 position:relative;
 max-width:600px;
 min-height:100vh;
 margin:auto;
 padding:20px 18px 30px;
}
.top{
 display:flex;
 align-items:center;
 justify-content:space-between;
}
.brand{
 font-weight:800;
 letter-spacing:.3px;
}
.dot{
 width:8px;height:8px;
 border-radius:50%;
 display:inline-block;
 background:#71ffae;
 margin-right:6px;
 box-shadow:0 0 12px #71ffae;
}
.coverWrap{
 margin:45px auto 25px;
 width:min(76vw,330px);
 aspect-ratio:1;
 position:relative;
}
.cover{
 width:100%;
 height:100%;
 object-fit:cover;
 border-radius:30px;
 background:#171821;
 box-shadow:
 0 30px 70px rgba(0,0,0,.5);
}
.meta{text-align:center}
.title{
 font-size:25px;
 font-weight:800;
 white-space:nowrap;
 overflow:hidden;
 text-overflow:ellipsis;
}
.artist{
 margin-top:7px;
 color:#a8a9b3;
}
.progress{
 margin-top:28px;
}
input[type=range]{
 width:100%;
 accent-color:#bd76ff;
}
.times{
 display:flex;
 justify-content:space-between;
 color:#888a96;
 font-size:12px;
}
.controls{
 display:flex;
 justify-content:center;
 align-items:center;
 gap:28px;
 margin:25px 0;
}
.ctrl{
 width:50px;height:50px;
 border:0;
 border-radius:50%;
 background:rgba(255,255,255,.08);
 color:#fff;
 font-size:21px;
}
.play{
 width:72px;height:72px;
 background:#fff;
 color:#111;
 font-size:28px;
}
.lyrics{
 margin-top:20px;
 min-height:180px;
 padding:22px;
 border-radius:24px;
 background:rgba(255,255,255,.055);
 border:1px solid rgba(255,255,255,.07);
 text-align:center;
 white-space:pre-wrap;
 line-height:1.75;
 color:#d8d8df;
}
.status{
 text-align:center;
 color:#888a96;
 font-size:12px;
 margin-top:12px;
}
.queue{
 margin-top:20px;
}
.qitem{
 padding:13px;
 border-bottom:1px solid rgba(255,255,255,.07);
}
</style>
</head>

<body>

<div class="bg"></div>

<div class="app">

<div class="top">
<div class="brand">
<span class="dot"></span>MAYAMUSIC
</div>
<div id="state">READY</div>
</div>

<div class="coverWrap">
<img id="cover"
 class="cover"
 src=""
 alt="Artwork">
</div>

<div class="meta">
<div id="title" class="title">Loading...</div>
<div id="artist" class="artist"></div>
</div>

<div class="progress">
<input id="seek"
 type="range"
 min="0"
 max="100"
 value="0">

<div class="times">
<span id="current">0:00</span>
<span id="duration">0:00</span>
</div>
</div>

<div class="controls">

<button class="ctrl"
 onclick="previousSong()">
⏮
</button>

<button class="ctrl play"
 id="playButton"
 onclick="togglePlay()">
▶
</button>

<button class="ctrl"
 onclick="nextSong()">
⏭
</button>

</div>

<div class="lyrics" id="lyrics">
Loading lyrics...
</div>

<div class="status" id="status">
Tap play to start
</div>

</div>

<audio
 id="audio"
 preload="auto"
 playsinline></audio>

<script>

const songs = ' . $json . ';
let index = ' .
(int)($player['index'] ?? 0) .
';

const audio =
document.getElementById("audio");

const titleEl =
document.getElementById("title");

const artistEl =
document.getElementById("artist");

const coverEl =
document.getElementById("cover");

const lyricsEl =
document.getElementById("lyrics");

const seekEl =
document.getElementById("seek");

const currentEl =
document.getElementById("current");

const durationEl =
document.getElementById("duration");

const playButton =
document.getElementById("playButton");

const statusEl =
document.getElementById("status");

const stateEl =
document.getElementById("state");

function formatTime(seconds){
 if(!isFinite(seconds)) return "0:00";

 const m =
 Math.floor(seconds / 60);

 const s =
 Math.floor(seconds % 60)
 .toString()
 .padStart(2,"0");

 return m + ":" + s;
}

async function loadLyrics(song){

 lyricsEl.textContent =
 "Loading lyrics...";

 const params =
 new URLSearchParams({
   title:song.title,
   artist:song.artist
 });

 try{

   const response =
   await fetch(
     "?action=lyrics&" +
     params.toString()
   );

   const data =
   await response.json();

   if(data.ok){

     lyricsEl.textContent =
       data.lyrics ||
       "Lyrics not available.";

   }else{

     lyricsEl.textContent =
       "Lyrics not available.";

   }

 }catch(e){

   lyricsEl.textContent =
     "Lyrics unavailable.";

 }
}

function loadSong(autoPlay=false){

 if(!songs.length) return;

 if(index < 0)
   index = songs.length - 1;

 if(index >= songs.length)
   index = 0;

 const song = songs[index];

 titleEl.textContent =
   song.title || "Unknown";

 artistEl.textContent =
   song.artist || "Unknown Artist";

 coverEl.src =
   song.artwork ||
   "";

 audio.src =
   song.url;

 audio.load();

 stateEl.textContent =
   "READY";

 statusEl.textContent =
   "Song " +
   (index + 1) +
   " of " +
   songs.length;

 loadLyrics(song);

 if(autoPlay){

   const p = audio.play();

   if(p){

     p.catch(() => {

       playButton.textContent =
         "▶";

       statusEl.textContent =
         "Tap play to start";

     });

   }

 }
}

function togglePlay(){

 if(audio.paused){

   audio.play().then(() => {

     playButton.textContent =
       "❚❚";

     stateEl.textContent =
       "PLAYING";

   }).catch(() => {

     statusEl.textContent =
       "Tap Play again";

   });

 }else{

   audio.pause();

   playButton.textContent =
     "▶";

   stateEl.textContent =
     "PAUSED";

 }
}

function nextSong(){

 index++;

 if(index >= songs.length)
   index = 0;

 loadSong(true);
}

function previousSong(){

 index--;

 if(index < 0)
   index = songs.length - 1;

 loadSong(true);
}

audio.addEventListener(
 "play",
 () => {

   playButton.textContent =
     "❚❚";

   stateEl.textContent =
     "PLAYING";

 }
);

audio.addEventListener(
 "pause",
 () => {

   playButton.textContent =
     "▶";

   if(!audio.ended)
     stateEl.textContent =
       "PAUSED";

 }
);

audio.addEventListener(
 "timeupdate",
 () => {

   if(audio.duration){

     seekEl.value =
       (audio.currentTime /
       audio.duration) * 100;

     currentEl.textContent =
       formatTime(
         audio.currentTime
       );

     durationEl.textContent =
       formatTime(
         audio.duration
       );

   }

 }
);

seekEl.addEventListener(
 "input",
 () => {

   if(audio.duration){

     audio.currentTime =
       (seekEl.value / 100) *
       audio.duration;

   }

 }
);

audio.addEventListener(
 "ended",
 () => {

   /*
    Auto-next:
    when current song ends,
    next song automatically starts.
   */

   nextSong();

 }
);

audio.addEventListener(
 "waiting",
 () => {

   stateEl.textContent =
     "BUFFERING";

 }
);

audio.addEventListener(
 "playing",
 () => {

   stateEl.textContent =
     "PLAYING";

 }
);

audio.addEventListener(
 "error",
 () => {

   stateEl.textContent =
     "ERROR";

   statusEl.textContent =
     "Unable to play this source.";

 }
);

loadSong(false);

</script>

</body>
</html>';
}

/* =========================================================
   ADMIN PANEL
   ========================================================= */

function adminPage(int $adminId): string
{
    if (!isAdmin($adminId)) {
        http_response_code(403);

        return 'Access denied';
    }

    $users = loadJson(USERS_FILE);
    $payments = loadJson(PAYMENTS_FILE);
    $keys = loadJson(KEYS_FILE);

    $active = 0;

    foreach ($users as $u) {
        if (
            (int)($u['premium_until'] ?? 0) > now()
        ) {
            $active++;
        }
    }

    $pending = 0;

    foreach ($payments as $p) {
        if (
            ($p['status'] ?? '') ===
            'waiting_review'
        ) {
            $pending++;
        }
    }

    $unusedKeys = 0;

    foreach ($keys as $k) {
        if (
            ($k['used'] ?? false) === false
        ) {
            $unusedKeys++;
        }
    }

    return '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport"
 content="width=device-width,initial-scale=1">
<title>MAYAMUSIC Admin</title>
<style>
body{
 margin:0;
 padding:20px;
 background:#08090d;
 color:#fff;
 font-family:system-ui;
}
.wrap{
 max-width:700px;
 margin:auto;
}
.card{
 padding:20px;
 margin:12px 0;
 border-radius:22px;
 background:#14151d;
 border:1px solid #262833;
}
.grid{
 display:grid;
 grid-template-columns:1fr 1fr;
 gap:12px;
}
.stat{
 padding:20px;
 background:#1a1b25;
 border-radius:18px;
}
.num{
 font-size:30px;
 font-weight:800;
}
button,input{
 width:100%;
 padding:13px;
 margin-top:8px;
 border-radius:12px;
 border:0;
}
button{
 background:#9d5cff;
 color:white;
 font-weight:800;
}
input{
 background:#090a0f;
 color:white;
 border:1px solid #30313c;
}
</style>
</head>
<body>
<div class="wrap">

<h1>MAYAMUSIC Admin</h1>

<div class="grid">

<div class="stat">
<div>Users</div>
<div class="num">' .
count($users) .
'</div>
</div>

<div class="stat">
<div>Premium</div>
<div class="num">' .
$active .
'</div>
</div>

<div class="stat">
<div>Pending Payments</div>
<div class="num">' .
$pending .
'</div>
</div>

<div class="stat">
<div>Unused Keys</div>
<div class="num">' .
$unusedKeys .
'</div>
</div>

</div>

<div class="card">

<h3>Generate Redeem Keys</h3>

<form method="post"
 action="?action=admin_generate_keys">

<input
 type="number"
 name="days"
 value="30"
 min="1"
 placeholder="Days">

<input
 type="number"
 name="count"
 value="1"
 min="1"
 max="100"
 placeholder="Number of keys">

<button>
Generate Keys
</button>

</form>

</div>

<div class="card">

<h3>Webhook</h3>

<form method="post"
 action="?action=admin_webhook">

<button name="mode"
 value="set">
Set Webhook
</button>

<button name="mode"
 value="delete">
Delete Webhook
</button>

</form>

</div>

</div>
</body>
</html>';
}

/* =========================================================
   COMMAND HELP
   ========================================================= */

function helpText(bool $admin = false): string
{
    $text =
"🎵 <b>MAYAMUSIC Commands</b>

<b>User Commands</b>

/start — Open bot
/help — Commands
/search SONG — Search music
/play SONG — Search & play
/premium — Premium plan
/account — Account status
/redeem KEY — Redeem premium
/queue — Queue
/support — Support
/cancel — Cancel current action

<b>Voice Chat Controls</b>

/playcc SONG
/pausecc
/resumecc
/skipcc
/stopcc
/leavecc";

    if ($admin) {
        $text .=
"

<b>Admin Commands</b>

/stats
/users
/pending
/genkey DAYS
/genkey DAYS COUNT
/give USER_ID DAYS
/revoke USER_ID
/broadcast TEXT
/adminapp
/setwebhook
/delwebhook";
    }

    return $text;
}

/* =========================================================
   SEARCH MESSAGE
   ========================================================= */

function sendSearchResults(
    int $chatId,
    string $query,
    bool $checkPremium = true
): void {
    $user = getUser($chatId);

    if (
        $checkPremium &&
        !premiumActive($user)
    ) {
        sendMessage(
            $chatId,
            "🔒 <b>Premium Required</b>\n\n" .
            "Private music access requires " .
            "MAYAMUSIC Premium.\n\n" .
            "💎 ₹49 / 30 days",
            premiumKeyboard()
        );

        return;
    }

    $results = searchMusic($query);

    if (!$results) {
        sendMessage(
            $chatId,
            "❌ No music results found.\n\n" .
            "Try another song name."
        );

        return;
    }

    /*
     * Limit result list to 10.
     */
    $results = array_slice(
        $results,
        0,
        10
    );

    $keyboard = [];

    foreach ($results as $i => $song) {

        $label =
            '▶ ' .
            $song['title'];

        if (mb_strlen($label) > 55) {
            $label =
                mb_substr(
                    $label,
                    0,
                    52
                ) . '...';
        }

        $keyboard[] = [[
            'text' => $label,
            'callback_data' =>
                'song:' . $i
        ]];
    }

    /*
     * Temporary search cache
     * for the current user.
     */
    $queues = loadJson(QUEUES_FILE);

    $queues[(string)$chatId] = [
        'query' => $query,
        'songs' => $results,
        'updated_at' => now()
    ];

    saveJson(QUEUES_FILE, $queues);

    sendMessage(
        $chatId,
        "🎵 <b>Search Results</b>\n\n" .
        "Query: <b>" .
        h($query) .
        "</b>\n\n" .
        "Choose a song:",
        [
            'inline_keyboard' => $keyboard
        ]
    );
}

/* =========================================================
   WEB APP / ACTION ROUTER
   ========================================================= */

$action = $_GET['action'] ?? '';

if ($action === 'player') {

    $token =
        trim((string)(
            $_GET['token'] ?? ''
        ));

    if ($token === '') {
        http_response_code(400);
        exit('Missing player token');
    }

    header(
        'Content-Type: text/html; charset=utf-8'
    );

    echo playerPage($token);

    exit;
}

if ($action === 'lyrics') {

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    $title =
        trim((string)(
            $_GET['title'] ?? ''
        ));

    $artist =
        trim((string)(
            $_GET['artist'] ?? ''
        ));

    if ($title === '') {
        echo json_encode([
            'ok' => false,
            'lyrics' => ''
        ]);

        exit;
    }

    $lyrics =
        getLyrics($title, $artist);

    echo json_encode([
        'ok' => true,
        'lyrics' =>
            $lyrics['plain'] !== ''
                ? $lyrics['plain']
                : (
                    $lyrics['synced'] !== ''
                        ? $lyrics['synced']
                        : ''
                )
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($action === 'premium') {

    $userId =
        (int)($_GET['user_id'] ?? 0);

    if ($userId <= 0) {
        http_response_code(400);
        exit('Invalid user');
    }

    header(
        'Content-Type: text/html; charset=utf-8'
    );

    echo premiumPage($userId);

    exit;
}

if ($action === 'submit_utr') {

    $paymentId =
        trim((string)(
            $_POST['payment_id'] ?? ''
        ));

    $utr =
        trim((string)(
            $_POST['utr'] ?? ''
        ));

    $payment =
        getPayment($paymentId);

    if (
        $payment === null ||
        $utr === ''
    ) {
        http_response_code(400);
        exit('Invalid payment');
    }

    if (submitUtr(
        (int)$payment['user_id'],
        $paymentId,
        $utr
    )) {

        $userId =
            (int)$payment['user_id'];

        sendMessage(
            ADMIN_ID,
            "💰 <b>New Payment Review</b>\n\n" .
            "Payment: <code>" .
            h($paymentId) .
            "</code>\n" .
            "User ID: <code>" .
            $userId .
            "</code>\n" .
            "Amount: ₹" .
            MONTHLY_PRICE .
            "\nUTR: <code>" .
            h($utr) .
            "</code>",
            [
                'inline_keyboard' => [
                    [
                        [
                            'text' =>
                                '✅ Approve',
                            'callback_data' =>
                                'approve:' .
                                $paymentId
                        ],
                        [
                            'text' =>
                                '❌ Decline',
                            'callback_data' =>
                                'decline:' .
                                $paymentId
                        ]
                    ]
                ]
            ]
        );

        header(
            'Content-Type: text/html; charset=utf-8'
        );

        echo '<!doctype html>
<html>
<head>
<meta name="viewport"
 content="width=device-width,initial-scale=1">
<style>
body{
 background:#090a0e;
 color:#fff;
 font-family:system-ui;
 display:grid;
 place-items:center;
 min-height:100vh;
}
.card{
 padding:30px;
 border-radius:24px;
 background:#171820;
 text-align:center;
 max-width:400px;
}
</style>
</head>
<body>
<div class="card">
<h1>✅ Submitted</h1>
<p>Your payment is waiting for admin verification.</p>
<p>You will receive a Telegram notification after approval.</p>
</div>
</body>
</html>';

        exit;
    }

    http_response_code(400);
    exit('Unable to submit payment');
}

if ($action === 'admin') {

    $id =
        (int)($_GET['id'] ?? 0);

    header(
        'Content-Type: text/html; charset=utf-8'
    );

    echo adminPage($id);

    exit;
}

if ($action === 'admin_generate_keys') {

    $adminId =
        (int)($_GET['id'] ?? 0);

    if (!isAdmin($adminId)) {
        http_response_code(403);
        exit('Forbidden');
    }

    $days =
        max(
            1,
            (int)($_POST['days'] ?? 30)
        );

    $count =
        max(
            1,
            min(
                100,
                (int)($_POST['count'] ?? 1)
            )
        );

    $output = [];

    for ($i = 0; $i < $count; $i++) {
        $output[] =
            generateRedeemKey($days)['key'];
    }

    header(
        'Content-Type: text/plain; charset=utf-8'
    );

    echo implode(
        PHP_EOL,
        $output
    );

    exit;
}

if ($action === 'admin_webhook') {

    $adminId =
        (int)($_GET['id'] ?? 0);

    if (!isAdmin($adminId)) {
        http_response_code(403);
        exit('Forbidden');
    }

    $mode =
        $_POST['mode'] ?? 'set';

    if ($mode === 'delete') {

        $result =
            telegram('deleteWebhook');

    } else {

        $result =
            telegram('setWebhook', [
                'url' => WEBAPP_URL,
                'allowed_updates' =>
                    json_encode([
                        'message',
                        'callback_query',
                        'pre_checkout_query'
                    ])
            ]);
    }

    header(
        'Content-Type: application/json'
    );

    echo json_encode(
        $result,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/* =========================================================
   WEBHOOK
   ========================================================= */

$rawUpdate =
    @file_get_contents('php://input');

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    $rawUpdate !== false &&
    trim($rawUpdate) !== ''
) {

    $update =
        json_decode($rawUpdate, true);

    if (!is_array($update)) {
        http_response_code(400);
        exit('Invalid update');
    }

    /*
     * CALLBACK QUERY
     */
    if (isset($update['callback_query'])) {

        $callback =
            $update['callback_query'];

        $callbackId =
            (string)(
                $callback['id'] ?? ''
            );

        $data =
            (string)(
                $callback['data'] ?? ''
            );

        $from =
            $callback['from'] ?? [];

        $userId =
            (int)($from['id'] ?? 0);

        $message =
            $callback['message'] ?? [];

        $chatId =
            (int)(
                $message['chat']['id'] ??
                $userId
            );

        updateTelegramUser($from);

        /*
         * Search button
         */
        if ($data === 'search') {

            answerCallback(
                $callbackId,
                'Use /search SONG'
            );

            sendMessage(
                $chatId,
                "🔎 <b>Search Music</b>\n\n" .
                "Send:\n" .
                "<code>/search song name</code>"
            );

            exit('OK');
        }

        /*
         * Premium
         */
        if ($data === 'premium') {

            answerCallback(
                $callbackId
            );

            $user =
                getUser($userId);

            if (premiumActive($user)) {

                sendMessage(
                    $chatId,
                    "💎 <b>Premium Active</b>\n\n" .
                    "Valid until:\n<b>" .
                    h(premiumUntilText($user)) .
                    "</b>"
                );

            } else {

                sendMessage(
                    $chatId,
                    "💎 <b>MAYAMUSIC Premium</b>\n\n" .
                    "₹49 / 30 days\n\n" .
                    "Private music access + Mini App player + " .
                    "lyrics + artwork + auto-next.",
                    premiumKeyboard()
                );
            }

            exit('OK');
        }

        /*
         * Account
         */
        if ($data === 'account') {

            answerCallback(
                $callbackId
            );

            $user =
                getUser($userId);

            $status =
                premiumActive($user)
                    ? '🟢 ACTIVE'
                    : '🔴 INACTIVE';

            sendMessage(
                $chatId,
                "👤 <b>Your Account</b>\n\n" .
                "ID: <code>" .
                $userId .
                "</code>\n" .
                "Status: <b>" .
                $status .
                "</b>\n" .
                "Valid until: <b>" .
                h(premiumUntilText($user)) .
                "</b>"
            );

            exit('OK');
        }

        /*
         * Redeem
         */
        if ($data === 'redeem') {

            answerCallback(
                $callbackId
            );

            sendMessage(
                $chatId,
                "🔑 <b>Redeem Premium</b>\n\n" .
                "Send:\n" .
                "<code>/redeem MAYA-XXXXX-XXXXX</code>"
            );

            exit('OK');
        }

        /*
         * Support
         */
        if ($data === 'support') {

            answerCallback(
                $callbackId
            );

            sendMessage(
                $chatId,
                "🆘 Support: " .
                h(SUPPORT_USERNAME)
            );

            exit('OK');
        }

        /*
         * Submit UTR
         */
        if ($data === 'submitutr') {

            answerCallback(
                $callbackId
            );

            $payment =
                createPayment($userId);

            $upiLink =
                'upi://pay?' .
                http_build_query([
                    'pa' => UPI_ID,
                    'pn' => UPI_NAME,
                    'am' => '49.00',
                    'cu' => 'INR',
                    'tn' =>
                        BOT_NAME . ' ' .
                        $payment['id']
                ]);

            sendMessage(
                $chatId,
                "💳 <b>Payment</b>\n\n" .
                "Amount: ₹49\n" .
                "UPI: <code>" .
                h(UPI_ID) .
                "</code>\n" .
                "Payment ID: <code>" .
                h($payment['id']) .
                "</code>\n\n" .
                "1. Pay ₹49\n" .
                "2. Open the payment page if needed\n" .
                "3. Submit your UTR/Transaction ID\n\n" .
                "Payment verification is manual.",
                [
                    'inline_keyboard' => [
                        [
                            [
                                'text' =>
                                    '💳 Open UPI',
                                'url' =>
                                    $upiLink
                            ]
                        ],
                        [
                            [
                                'text' =>
                                    '🧾 Open Payment Page',
                                'web_app' => [
                                    'url' =>
                                        WEBAPP_URL .
                                        '?action=premium&user_id=' .
                                        $userId
                                ]
                            ]
                        ]
                    ]
                ]
            );

            exit('OK');
        }

        /*
         * Search result song
         */
        if (str_starts_with($data, 'song:')) {

            answerCallback(
                $callbackId,
                'Preparing player...'
            );

            $index =
                (int)substr(
                    $data,
                    strlen('song:')
                );

            $queues =
                loadJson(QUEUES_FILE);

            $queue =
                $queues[(string)$userId] ??
                null;

            if (
                !$queue ||
                empty($queue['songs'][$index])
            ) {
                sendMessage(
                    $chatId,
                    "❌ Search session expired. " .
                    "Search again."
                );

                exit('OK');
            }

            $token =
                createPlayer(
                    $userId,
                    $queue['songs'],
                    $index
                );

            $song =
                $queue['songs'][$index];

            sendMessage(
                $chatId,
                "🎵 <b>" .
                h($song['title']) .
                "</b>\n" .
                "👤 " .
                h($song['artists']) .
                "\n\n" .
                "Open the MAYAMUSIC player:",
                [
                    'inline_keyboard' => [
                        [
                            [
                                'text' =>
                                    '▶️ Open Player',
                                'web_app' => [
                                    'url' =>
                                        playerUrl($token)
                                ]
                            ]
                        ]
                    ]
                ]
            );

            exit('OK');
        }

        /*
         * Payment approve
         */
        if (
            str_starts_with(
                $data,
                'approve:'
            )
        ) {

            if (!isAdmin($userId)) {

                answerCallback(
                    $callbackId,
                    'Admin only',
                    true
                );

                exit('OK');
            }

            $paymentId =
                substr(
                    $data,
                    strlen('approve:')
                );

            if (approvePayment($paymentId)) {

                answerCallback(
                    $callbackId,
                    'Payment approved'
                );

                editMessage(
                    $chatId,
                    (int)$message['message_id'],
                    "✅ <b>Payment Approved</b>\n\n" .
                    "Payment ID: <code>" .
                    h($paymentId) .
                    "</code>"
                );

            } else {

                answerCallback(
                    $callbackId,
                    'Could not approve',
                    true
                );
            }

            exit('OK');
        }

        /*
         * Payment decline
         */
        if (
            str_starts_with(
                $data,
                'decline:'
            )
        ) {

            if (!isAdmin($userId)) {

                answerCallback(
                    $callbackId,
                    'Admin only',
                    true
                );

                exit('OK');
            }

            $paymentId =
                substr(
                    $data,
                    strlen('decline:')
                );

            if (declinePayment($paymentId)) {

                answerCallback(
                    $callbackId,
                    'Payment declined'
                );

                editMessage(
                    $chatId,
                    (int)$message['message_id'],
                    "❌ <b>Payment Declined</b>\n\n" .
                    "Payment ID: <code>" .
                    h($paymentId) .
                    "</code>"
                );

            } else {

                answerCallback(
                    $callbackId,
                    'Could not decline',
                    true
                );
            }

            exit('OK');
        }

        exit('OK');
    }

    /*
     * NORMAL MESSAGE
     */

    $message =
        $update['message'] ?? null;

    if (!$message) {
        exit('OK');
    }

    $chat =
        $message['chat'] ?? [];

    $from =
        $message['from'] ?? [];

    $chatId =
        (int)($chat['id'] ?? 0);

    $userId =
        (int)($from['id'] ?? 0);

    $text =
        trim((string)(
            $message['text'] ?? ''
        ));

    if ($userId > 0) {
        updateTelegramUser($from);
    }

    /*
     * CHANNEL/GROUP MODE
     *
     * Channel/group music access is FREE.
     */

    $groupMode =
        isGroupChat($chat);

    /*
     * /start
     */

    if (
        preg_match(
            '/^\/start(?:@\w+)?(?:\s+(.*))?$/iu',
            $text,
            $m
        )
    ) {

        $user =
            getUser($userId);

        $status =
            premiumActive($user)
                ? "💎 Premium active until " .
                  premiumUntilText($user)
                : "🔒 Private premium inactive";

        sendMessage(
            $chatId,
            "🎵 <b>Welcome to MAYAMUSIC</b>\n\n" .
            "Real music search + streaming player.\n\n" .
            "━━━━━━━━━━━━━━\n" .
            "🎧 Search music\n" .
            "🖼 Album artwork\n" .
            "🎤 Lyrics\n" .
            "⏭ Auto-next\n" .
            "📋 Queue\n" .
            "💎 Premium\n" .
            "🔑 Redeem\n" .
            "━━━━━━━━━━━━━━\n\n" .
            h($status) .
            "\n\n" .
            "Use <code>/search song name</code> to begin.",
            mainKeyboard(
                premiumActive($user)
            )
        );

        exit('OK');
    }

    /*
     * /help
     */

    if (
        preg_match(
            '/^\/help(?:@\w+)?$/iu',
            $text
        )
    ) {

        sendMessage(
            $chatId,
            helpText(
                isAdmin($userId)
            )
        );

        exit('OK');
    }

    /*
     * /account
     */

    if (
        preg_match(
            '/^\/account(?:@\w+)?$/iu',
            $text
        )
    ) {

        $user =
            getUser($userId);

        $status =
            premiumActive($user)
                ? '🟢 ACTIVE'
                : '🔴 INACTIVE';

        sendMessage(
            $chatId,
            "👤 <b>Account</b>\n\n" .
            "User ID: <code>" .
            $userId .
            "</code>\n" .
            "Premium: <b>" .
            $status .
            "</b>\n" .
            "Valid until: <b>" .
            h(premiumUntilText($user)) .
            "</b>"
        );

        exit('OK');
    }

    /*
     * /premium
     */

    if (
        preg_match(
            '/^\/premium(?:@\w+)?$/iu',
            $text
        )
    ) {

        $user =
            getUser($userId);

        if (premiumActive($user)) {

            sendMessage(
                $chatId,
                "💎 <b>Premium Active</b>\n\n" .
                "Valid until: <b>" .
                h(premiumUntilText($user)) .
                "</b>"
            );

        } else {

            $payment =
                createPayment($userId);

            $upiLink =
                'upi://pay?' .
                http_build_query([
                    'pa' => UPI_ID,
                    'pn' => UPI_NAME,
                    'am' => '49.00',
                    'cu' => 'INR',
                    'tn' =>
                        BOT_NAME . ' ' .
                        $payment['id']
                ]);

            sendMessage(
                $chatId,
                "💎 <b>MAYAMUSIC Premium</b>\n\n" .
                "₹49 / 30 days\n\n" .
                "✓ Music streaming\n" .
                "✓ Mini App player\n" .
                "✓ Artwork\n" .
                "✓ Lyrics\n" .
                "✓ Auto-next\n" .
                "✓ Queue\n\n" .
                "UPI: <code>" .
                h(UPI_ID) .
                "</code>\n\n" .
                "After payment submit your UTR.",
                [
                    'inline_keyboard' => [
                        [
                            [
                                'text' =>
                                    '💳 Pay ₹49',
                                'url' =>
                                    $upiLink
                            ]
                        ],
                        [
                            [
                                'text' =>
                                    '🧾 Payment Page',
                                'web_app' => [
                                    'url' =>
                                        WEBAPP_URL .
                                        '?action=premium&user_id=' .
                                        $userId
                                ]
                            ]
                        ]
                    ]
                ]
            );
        }

        exit('OK');
    }

    /*
     * /search
     */

    if (
        preg_match(
            '/^\/search(?:@\w+)?\s+(.+)$/iu',
            $text,
            $m
        )
    ) {

        sendSearchResults(
            $chatId,
            trim($m[1]),
            !$groupMode
        );

        exit('OK');
    }

    /*
     * /play
     */

    if (
        preg_match(
            '/^\/play(?:@\w+)?\s+(.+)$/iu',
            $text,
            $m
        )
    ) {

        sendSearchResults(
            $chatId,
            trim($m[1]),
            !$groupMode
        );

        exit('OK');
    }

    /*
     * /redeem
     */

    if (
        preg_match(
            '/^\/redeem(?:@\w+)?\s+(.+)$/iu',
            $text,
            $m
        )
    ) {

        $result =
            redeemKey(
                $userId,
                trim($m[1])
            );

        sendMessage(
            $chatId,
            $result['message']
        );

        exit('OK');
    }

    /*
     * /support
     */

    if (
        preg_match(
            '/^\/support(?:@\w+)?$/iu',
            $text
        )
    ) {

        sendMessage(
            $chatId,
            "🆘 <b>MAYAMUSIC Support</b>\n\n" .
            "Contact: " .
            h(SUPPORT_USERNAME),
            [
                'inline_keyboard' => [
                    [
                        [
                            'text' =>
                                '💬 Open Support',
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

        exit('OK');
    }

    /*
     * VOICE CHAT COMMANDS
     *
     * PHP Bot API cannot itself join Telegram
     * Voice Chat as an audio participant.
     */

    if (
        preg_match(
            '/^\/playcc(?:@\w+)?(?:\s+(.+))?$/iu',
            $text,
            $m
        )
    ) {

        $query =
            trim((string)(
                $m[1] ?? ''
            ));

        if ($query === '') {

            sendMessage(
                $chatId,
                "🎧 Usage:\n" .
                "<code>/playcc song name</code>\n\n" .
                "Note: actual Voice Chat audio requires " .
                "a separate VC engine."
            );

            exit('OK');
        }

        $results =
            searchMusic($query);

        if (!$results) {

            sendMessage(
                $chatId,
                "❌ No result found."
            );

            exit('OK');
        }

        /*
         * API currently has no rating field.
         * Therefore first API result is used.
         */
        $song = $results[0];

        sendMessage(
            $chatId,
            "🎧 <b>VC Request</b>\n\n" .
            "Selected result:\n" .
            "🎵 " .
            h($song['title']) .
            "\n👤 " .
            h($song['artists']) .
            "\n\n" .
            "⚠️ PHP Bot API cannot directly join/play " .
            "audio in Telegram Voice Chat. " .
            "Connect a compatible VC audio engine to " .
            "enable actual playback."
        );

        exit('OK');
    }

    if (
        in_array(
            strtolower(
                preg_replace(
                    '/^\/(\w+).*$/',
                    '$1',
                    $text
                )
            ),
            [
                'pausecc',
                'resumecc',
                'skipcc',
                'stopcc',
                'leavecc'
            ],
            true
        )
    ) {

        sendMessage(
            $chatId,
            "🎧 VC command received.\n\n" .
            "Actual VC playback requires a separate " .
            "Telegram Voice Chat audio engine."
        );

        exit('OK');
    }

    /*
     * ADMIN
     */

    if (isAdmin($userId)) {

        /*
         * /stats
         */

        if (
            preg_match(
                '/^\/stats(?:@\w+)?$/iu',
                $text
            )
        ) {

            $users =
                loadJson(USERS_FILE);

            $payments =
                loadJson(PAYMENTS_FILE);

            $keys =
                loadJson(KEYS_FILE);

            $active = 0;

            foreach ($users as $u) {
                if (
                    (int)(
                        $u['premium_until'] ?? 0
                    ) > now()
                ) {
                    $active++;
                }
            }

            $pending = 0;

            foreach ($payments as $p) {
                if (
                    ($p['status'] ?? '') ===
                    'waiting_review'
                ) {
                    $pending++;
                }
            }

            $unused = 0;

            foreach ($keys as $k) {
                if (
                    ($k['used'] ?? false) === false
                ) {
                    $unused++;
                }
            }

            sendMessage(
                $chatId,
                "📊 <b>MAYAMUSIC Stats</b>\n\n" .
                "Users: <b>" .
                count($users) .
                "</b>\n" .
                "Premium: <b>" .
                $active .
                "</b>\n" .
                "Pending payments: <b>" .
                $pending .
                "</b>\n" .
                "Unused keys: <b>" .
                $unused .
                "</b>"
            );

            exit('OK');
        }

        /*
         * /users
         */

        if (
            preg_match(
                '/^\/users(?:@\w+)?$/iu',
                $text
            )
        ) {

            $users =
                loadJson(USERS_FILE);

            $lines = [];

            foreach (
                array_slice(
                    array_values($users),
                    -20
                ) as $u
            ) {

                $active =
                    (int)(
                        $u['premium_until'] ?? 0
                    ) > now();

                $lines[] =
                    '• <code>' .
                    (int)$u['id'] .
                    '</code> — ' .
                    (
                        $active
                            ? '🟢'
                            : '⚪'
                    );
            }

            sendMessage(
                $chatId,
                "👥 <b>Recent Users</b>\n\n" .
                (
                    $lines
                        ? implode(
                            "\n",
                            $lines
                        )
                        : 'No users.'
                )
            );

            exit('OK');
        }

        /*
         * /pending
         */

        if (
            preg_match(
                '/^\/pending(?:@\w+)?$/iu',
                $text
            )
        ) {

            $payments =
                loadJson(PAYMENTS_FILE);

            $found = false;

            foreach ($payments as $p) {

                if (
                    ($p['status'] ?? '') !==
                    'waiting_review'
                ) {
                    continue;
                }

                $found = true;

                sendMessage(
                    $chatId,
                    "💰 <b>Payment Review</b>\n\n" .
                    "ID: <code>" .
                    h($p['id']) .
                    "</code>\n" .
                    "User: <code>" .
                    (int)$p['user_id'] .
                    "</code>\n" .
                    "Amount: ₹" .
                    (int)$p['amount'] .
                    "\nUTR: <code>" .
                    h($p['utr']) .
                    "</code>",
                    [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' =>
                                        '✅ Approve',
                                    'callback_data' =>
                                        'approve:' .
                                        $p['id']
                                ],
                                [
                                    'text' =>
                                        '❌ Decline',
                                    'callback_data' =>
                                        'decline:' .
                                        $p['id']
                                ]
                            ]
                        ]
                    ]
                );
            }

            if (!$found) {
                sendMessage(
                    $chatId,
                    "✅ No pending payments."
                );
            }

            exit('OK');
        }

        /*
         * /genkey DAYS [COUNT]
         */

        if (
            preg_match(
                '/^\/genkey(?:@\w+)?\s+(\d+)(?:\s+(\d+))?$/iu',
                $text,
                $m
            )
        ) {

            $days =
                max(
                    1,
                    (int)$m[1]
                );

            $count =
                max(
                    1,
                    min(
                        100,
                        (int)($m[2] ?? 1)
                    )
                );

            $keys = [];

            for ($i = 0; $i < $count; $i++) {

                $item =
                    generateRedeemKey($days);

                $keys[] =
                    '<code>' .
                    h($item['key']) .
                    '</code>';
            }

            sendMessage(
                $chatId,
                "🔑 <b>Generated Keys</b>\n\n" .
                "Days: <b>" .
                $days .
                "</b>\n" .
                "Count: <b>" .
                $count .
                "</b>\n\n" .
                implode(
                    "\n",
                    $keys
                )
            );

            exit('OK');
        }

        /*
         * /give USER_ID DAYS
         */

        if (
            preg_match(
                '/^\/give(?:@\w+)?\s+(\d+)\s+(\d+)$/iu',
                $text,
                $m
            )
        ) {

            $target =
                (int)$m[1];

            $days =
                max(
                    1,
                    (int)$m[2]
                );

            $user =
                activatePremium(
                    $target,
                    $days
                );

            sendMessage(
                $chatId,
                "✅ Premium granted.\n\n" .
                "User: <code>" .
                $target .
                "</code>\n" .
                "Days: <b>" .
                $days .
                "</b>\n" .
                "Until: <b>" .
                h(premiumUntilText($user)) .
                "</b>"
            );

            sendMessage(
                $target,
                "🎉 <b>Premium Granted</b>\n\n" .
                "Days: " .
                $days .
                "\nValid until: <b>" .
                h(premiumUntilText($user)) .
                "</b>"
            );

            exit('OK');
        }

        /*
         * /revoke USER_ID
         */

        if (
            preg_match(
                '/^\/revoke(?:@\w+)?\s+(\d+)$/iu',
                $text,
                $m
            )
        ) {

            $target =
                (int)$m[1];

            $user =
                getUser($target);

            $user['premium_until'] = 0;

            saveUser($user);

            sendMessage(
                $chatId,
                "✅ Premium revoked for <code>" .
                $target .
                "</code>"
            );

            sendMessage(
                $target,
                "ℹ️ Your MAYAMUSIC Premium access " .
                "has been revoked."
            );

            exit('OK');
        }

        /*
         * /broadcast
         */

        if (
            preg_match(
                '/^\/broadcast(?:@\w+)?\s+(.+)$/isu',
                $text,
                $m
            )
        ) {

            $users =
                loadJson(USERS_FILE);

            $broadcastText =
                trim($m[1]);

            $sent = 0;

            foreach ($users as $u) {

                $target =
                    (int)($u['id'] ?? 0);

                if ($target <= 0) {
                    continue;
                }

                $result =
                    sendMessage(
                        $target,
                        "📢 <b>MAYAMUSIC Announcement</b>\n\n" .
                        h($broadcastText)
                    );

                if ($result['ok'] ?? false) {
                    $sent++;
                }
            }

            sendMessage(
                $chatId,
                "📢 Broadcast completed.\n\n" .
                "Sent: <b>" .
                $sent .
                "</b>"
            );

            exit('OK');
        }

        /*
         * /adminapp
         */

        if (
            preg_match(
                '/^\/adminapp(?:@\w+)?$/iu',
                $text
            )
        ) {

            $url =
                WEBAPP_URL .
                '?action=admin&id=' .
                $userId;

            sendMessage(
                $chatId,
                "🛠 <b>MAYAMUSIC Admin Panel</b>\n\n" .
                "Open the admin dashboard:",
                [
                    'inline_keyboard' => [
                        [
                            [
                                'text' =>
                                    '🛠 Open Admin Panel',
                                'web_app' => [
                                    'url' => $url
                                ]
                            ]
                        ]
                    ]
                ]
            );

            exit('OK');
        }

        /*
         * /setwebhook
         */

        if (
            preg_match(
                '/^\/setwebhook(?:@\w+)?$/iu',
                $text
            )
        ) {

            $result =
                telegram('setWebhook', [
                    'url' => WEBAPP_URL,
                    'allowed_updates' =>
                        json_encode([
                            'message',
                            'callback_query',
                            'pre_checkout_query'
                        ])
                ]);

            sendMessage(
                $chatId,
                ($result['ok'] ?? false)
                    ? "✅ Webhook set successfully."
                    : "❌ Webhook error:\n" .
                      h(
                          (string)(
                              $result['description'] ??
                              'Unknown error'
                          )
                      )
            );

            exit('OK');
        }

        /*
         * /delwebhook
         */

        if (
            preg_match(
                '/^\/delwebhook(?:@\w+)?$/iu',
                $text
            )
        ) {

            $result =
                telegram(
                    'deleteWebhook'
                );

            sendMessage(
                $chatId,
                ($result['ok'] ?? false)
                    ? "✅ Webhook deleted."
                    : "❌ Could not delete webhook."
            );

            exit('OK');
        }
    }

    /*
     * Unknown command
     */

    if (
        str_starts_with(
            $text,
            '/'
        )
    ) {

        sendMessage(
            $chatId,
            "❓ Unknown command.\n\n" .
            "Use /help"
        );

        exit('OK');
    }

    /*
     * Plain text in private/group:
     * treat as music search.
     */

    if (
        $text !== '' &&
        mb_strlen($text) >= 2
    ) {

        sendSearchResults(
            $chatId,
            $text,
            !$groupMode
        );

        exit('OK');
    }

    exit('OK');
}

/* =========================================================
   GET HEALTH / RAILWAY CHECK
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
 display:grid;
 place-items:center;
 background:#08090d;
 color:#fff;
 font-family:system-ui;
}
.card{
 text-align:center;
 padding:30px;
 border-radius:25px;
 background:#15161e;
 border:1px solid #282934;
}
.ok{
 color:#71ffae;
 font-weight:800;
}
</style>
</head>
<body>
<div class="card">
<h1>🎵 MAYAMUSIC</h1>
<p class="ok">● ONLINE</p>
<p>Telegram music bot backend is running.</p>
<p>Webhook URL:</p>
<small>' .
h(WEBAPP_URL) .
'</small>
</div>
</body>
</html>';
?>
