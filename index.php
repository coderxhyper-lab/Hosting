<?php
/**
 * ============================================================
 * MAYAMUSIC - COMPLETE SINGLE FILE TELEGRAM MUSIC BOT
 * PHP 8.1+
 * ============================================================
 *
 * FEATURES
 * ------------------------------------------------------------
 * Telegram Bot
 * /start /help /search /play /premium /account /redeem
 * /utr /queue /genkey /give /revoke /broadcast
 * /stats /apitest /webhookinfo /setwebhook /delwebhook
 *
 * MUSIC
 * ------------------------------------------------------------
 * Music search API
 * Correct "artists" field
 * Download/stream URL
 * Album artwork via iTunes fallback
 * Lyrics via LRCLIB
 * Mini App HTML5 player
 * Previous / Next
 * Auto-next
 * Queue
 *
 * PREMIUM
 * ------------------------------------------------------------
 * ₹49 / 30 days
 * UPI payment
 * UTR submission
 * Admin approve / decline
 * Account status
 * Redeem keys
 *
 * ADMIN
 * ------------------------------------------------------------
 * Permanent access
 * User management
 * Generate redeem keys
 * Give premium
 * Revoke premium
 * Payment approval
 * Statistics
 * API test
 * Webhook diagnostics
 *
 * GROUP / CHANNEL
 * ------------------------------------------------------------
 * Group/channel access FREE
 * /playcc command hooks included
 *
 * IMPORTANT
 * ------------------------------------------------------------
 * Telegram Bot API alone cannot become a Telegram Voice Chat
 * audio participant. Actual VC audio playback requires a
 * separate MTProto/voice engine.
 * ============================================================
 */

declare(strict_types=1);

/* ============================================================
   CONFIG
   ============================================================ */

const BOT_TOKEN = '8817347840:AAFpsNeTkzHqjnlqkV_18AjMEgIX-FXHmQo';
const ADMIN_ID = 8897821078;

const BOT_NAME = 'MAYAMUSIC';
const BOT_USERNAME = 'MayaMusicDownload_BOT';

const SUPPORT_USERNAME = 'HyperxVicky';

const WEBAPP_URL =
    'https://hosting-production-aacd.up.railway.app/index.php';

const MUSIC_API =
    'https://music-search-api-frnb.vercel.app/search?song=';

const ITUNES_API =
    'https://itunes.apple.com/search';

const LRCLIB_API =
    'https://lrclib.net/api/search';

const MONTHLY_PRICE = 49;
const ACCESS_DAYS = 30;

const UPI_ID = 'vickybanna8674@ybl';
const UPI_NAME = 'MAYAMUSIC';

const DATA_DIR = __DIR__ . '/data';

const HTTP_TIMEOUT = 18;
const API_CONNECT_TIMEOUT = 8;


/* ============================================================
   ENVIRONMENT OVERRIDE
   ============================================================ */

function configBotToken(): string
{
    $env = getenv('BOT_TOKEN');

    if ($env !== false && trim($env) !== '') {
        return trim($env);
    }

    return trim(BOT_TOKEN);
}

function configWebAppUrl(): string
{
    $env = getenv('WEBAPP_URL');

    if ($env !== false && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }

    return rtrim(WEBAPP_URL, '/');
}


/* ============================================================
   DATA DIRECTORY
   ============================================================ */

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}


/* ============================================================
   JSON STORAGE
   ============================================================ */

function filePath(string $name): string
{
    return DATA_DIR . '/' . $name . '.json';
}

function readJson(string $name, array $default = []): array
{
    $path = filePath($name);

    if (!is_file($path)) {
        return $default;
    }

    $raw = @file_get_contents($path);

    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : $default;
}

function writeJson(string $name, array $data): bool
{
    $path = filePath($name);
    $tmp  = $path . '.tmp';

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        return false;
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return @rename($tmp, $path);
}


/* ============================================================
   HELPERS
   ============================================================ */

function now(): int
{
    return time();
}

function esc(string $text): string
{
    return htmlspecialchars(
        $text,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function fmtDate(int $timestamp): string
{
    if ($timestamp <= 0) {
        return 'Not active';
    }

    return date('d M Y, h:i A', $timestamp);
}

function safeInt(mixed $value): int
{
    return (int)$value;
}

function jsonReply(array $data): void
{
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* ============================================================
   TELEGRAM API
   ============================================================ */

function tg(string $method, array $params = []): array
{
    $token = configBotToken();

    if ($token === '') {
        return [
            'ok' => false,
            'description' => 'BOT_TOKEN is not configured'
        ];
    }

    $url =
        'https://api.telegram.org/bot' .
        $token .
        '/' .
        $method;

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'ok' => false,
            'description' => 'Unable to initialize cURL'
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'MAYAMUSIC/1.0'
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'http_code' => $http,
            'description' => $error ?: 'Telegram request failed'
        ];
    }

    $data = json_decode($body, true);

    if (!is_array($data)) {
        return [
            'ok' => false,
            'http_code' => $http,
            'description' => 'Invalid Telegram JSON response'
        ];
    }

    $data['http_code'] = $http;

    return $data;
}

function sendMsg(
    int|string $chatId,
    string $text,
    array $extra = []
): array {
    return tg(
        'sendMessage',
        array_merge(
            [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true
            ],
            $extra
        )
    );
}

function editMsg(
    int|string $chatId,
    int $messageId,
    string $text,
    array $extra = []
): array {
    return tg(
        'editMessageText',
        array_merge(
            [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true
            ],
            $extra
        )
    );
}

function answerCb(
    string $id,
    string $text = '',
    bool $alert = false
): void {
    tg(
        'answerCallbackQuery',
        [
            'callback_query_id' => $id,
            'text' => $text,
            'show_alert' => $alert ? 'true' : 'false'
        ]
    );
}

function kb(array $rows): string
{
    return json_encode(
        ['inline_keyboard' => $rows],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
}


/* ============================================================
   USER SYSTEM
   ============================================================ */

function isAdmin(int $uid): bool
{
    return $uid === ADMIN_ID;
}

function userRecord(int $uid): array
{
    $users = readJson('users');

    $key = (string)$uid;

    if (!isset($users[$key])) {
        $users[$key] = [
            'id' => $uid,
            'created_at' => now(),
            'last_seen' => now(),
            'premium_until' => 0,
            'username' => '',
            'first_name' => ''
        ];
    }

    $users[$key]['last_seen'] = now();

    writeJson('users', $users);

    return $users[$key];
}

function updateUser(
    int $uid,
    array $patch
): array {
    $users = readJson('users');

    $key = (string)$uid;

    $user = $users[$key] ?? [
        'id' => $uid,
        'created_at' => now(),
        'premium_until' => 0
    ];

    $user = array_merge(
        $user,
        $patch,
        [
            'last_seen' => now()
        ]
    );

    $users[$key] = $user;

    writeJson('users', $users);

    return $user;
}

function premiumUntil(int $uid): int
{
    $user = userRecord($uid);

    return (int)($user['premium_until'] ?? 0);
}

function premiumActive(int $uid): bool
{
    /*
     * ADMIN = ALWAYS ACTIVE
     */
    if (isAdmin($uid)) {
        return true;
    }

    return premiumUntil($uid) > now();
}

function privateAccess(int $uid): bool
{
    return premiumActive($uid);
}


/* ============================================================
   MAIN KEYBOARD
   ============================================================ */

function mainKeyboard(int $uid): string
{
    $rows = [
        [
            [
                'text' => '🔎 Search Music',
                'callback_data' => 'search'
            ],
            [
                'text' => '▶️ Player',
                'callback_data' => 'player'
            ]
        ],
        [
            [
                'text' => '⭐ Premium',
                'callback_data' => 'premium'
            ],
            [
                'text' => '🎟 Redeem',
                'callback_data' => 'redeem'
            ]
        ],
        [
            [
                'text' => '👤 Account',
                'callback_data' => 'account'
            ],
            [
                'text' => '💬 Support',
                'url' =>
                    'https://t.me/' .
                    ltrim(SUPPORT_USERNAME, '@')
            ]
        ]
    ];

    if (isAdmin($uid)) {
        $rows[] = [
            [
                'text' => '🛠 Admin Panel',
                'callback_data' => 'admin'
            ]
        ];
    }

    return kb($rows);
}


/* ============================================================
   MUSIC API
   ============================================================ */

function httpGetJson(
    string $url,
    int $timeout = HTTP_TIMEOUT
): array {
    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'ok' => false,
            'http_code' => 0,
            'error' => 'cURL init failed',
            'data' => null
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => API_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'MAYAMUSIC/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);

    $body = curl_exec($ch);

    $error = curl_error($ch);

    $httpCode = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error' => $error ?: 'HTTP request failed',
            'data' => null
        ];
    }

    $data = json_decode($body, true);

    if (!is_array($data)) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error' => 'Invalid JSON response',
            'data' => null,
            'raw' => substr($body, 0, 1000)
        ];
    }

    return [
        'ok' => true,
        'http_code' => $httpCode,
        'error' => '',
        'data' => $data
    ];
}

function searchMusic(string $query): array
{
    $query = trim($query);

    if ($query === '') {
        return [];
    }

    $url =
        MUSIC_API .
        rawurlencode($query);

    $response = httpGetJson($url);

    if (
        !($response['ok'] ?? false) ||
        !is_array($response['data'] ?? null)
    ) {
        return [];
    }

    $data = $response['data'];

    if (
        empty($data['results']) ||
        !is_array($data['results'])
    ) {
        return [];
    }

    $results = [];

    foreach ($data['results'] as $item) {
        if (!is_array($item)) {
            continue;
        }

        $download =
            trim((string)(
                $item['download_url'] ?? ''
            ));

        if ($download === '') {
            continue;
        }

        /*
         * IMPORTANT:
         * API FIELD IS "artists", NOT "artist"
         */

        $results[] = [
            'title' =>
                trim((string)(
                    $item['title'] ??
                    'Unknown Title'
                )),

            'artists' =>
                trim((string)(
                    $item['artists'] ??
                    'Unknown Artist'
                )),

            'album' =>
                trim((string)(
                    $item['album'] ?? ''
                )),

            'duration' =>
                trim((string)(
                    $item['duration'] ?? ''
                )),

            'download_url' =>
                $download
        ];
    }

    return $results;
}


/* ============================================================
   ARTWORK
   ============================================================ */

function artwork(
    string $title,
    string $artist
): string {
    $term = rawurlencode(
        trim($title . ' ' . $artist)
    );

    $url =
        ITUNES_API .
        '?term=' .
        $term .
        '&entity=song&limit=1';

    $response = httpGetJson($url, 10);

    if (!($response['ok'] ?? false)) {
        return '';
    }

    $data = $response['data'] ?? [];

    if (!is_array($data)) {
        return '';
    }

    $image =
        $data['results'][0]['artworkUrl100']
        ?? '';

    if ($image === '') {
        return '';
    }

    return str_replace(
        '100x100bb',
        '600x600bb',
        $image
    );
}


/* ============================================================
   LYRICS
   ============================================================ */

function getLyrics(
    string $title,
    string $artist
): array {
    $url =
        LRCLIB_API .
        '?track_name=' .
        rawurlencode($title) .
        '&artist_name=' .
        rawurlencode($artist);

    $response = httpGetJson($url, 12);

    if (!($response['ok'] ?? false)) {
        return [
            'synced' => '',
            'plain' => ''
        ];
    }

    $data = $response['data'];

    if (!is_array($data)) {
        return [
            'synced' => '',
            'plain' => ''
        ];
    }

    foreach ($data as $item) {
        if (!is_array($item)) {
            continue;
        }

        $synced =
            trim((string)(
                $item['syncedLyrics'] ?? ''
            ));

        $plain =
            trim((string)(
                $item['plainLyrics'] ?? ''
            ));

        if ($synced !== '' || $plain !== '') {
            return [
                'synced' => $synced,
                'plain' => $plain
            ];
        }
    }

    return [
        'synced' => '',
        'plain' => ''
    ];
}


/* ============================================================
   SEARCH SESSIONS
   ============================================================ */

function createSearchSession(
    int $uid,
    array $results
): string {
    $searches = readJson('searches');

    $id = bin2hex(
        random_bytes(12)
    );

    $searches[$id] = [
        'user_id' => $uid,
        'results' => $results,
        'created_at' => now(),
        'expires_at' => now() + 1800
    ];

    writeJson('searches', $searches);

    return $id;
}


/* ============================================================
   PLAYER TOKEN
   ============================================================ */

function createPlayerToken(
    array $song,
    array $queue
): string {
    $players = readJson('players');

    $token = bin2hex(
        random_bytes(18)
    );

    $players[$token] = [
        'song' => $song,
        'queue' => $queue,
        'created_at' => now(),
        'expires_at' => now() + 86400
    ];

    writeJson('players', $players);

    return $token;
}

function playerUrl(string $token): string
{
    return configWebAppUrl() .
        '?mini=1&token=' .
        rawurlencode($token);
}


/* ============================================================
   CLEAN OLD DATA
   ============================================================ */

function cleanExpiredData(): void
{
    $now = now();

    foreach ([
        'players',
        'searches'
    ] as $file) {
        $data = readJson($file);

        $changed = false;

        foreach ($data as $key => $item) {
            if (
                isset($item['expires_at']) &&
                (int)$item['expires_at'] < $now
            ) {
                unset($data[$key]);
                $changed = true;
            }
        }

        if ($changed) {
            writeJson($file, $data);
        }
    }
}


/* ============================================================
   SEARCH COMMAND
   ============================================================ */

function showSearch(
    int $uid,
    string $query
): void {
    if (!privateAccess($uid)) {
        sendMsg(
            $uid,
            "🔒 <b>Premium required.</b>\n\n" .
            "Use ⭐ <b>Premium</b> to activate access."
        );

        return;
    }

    $query = trim($query);

    if ($query === '') {
        sendMsg(
            $uid,
            "🔎 <b>Search Music</b>\n\n" .
            "Example:\n" .
            "<code>/search Tatvadarshi</code>"
        );

        return;
    }

    $results = searchMusic($query);

    if (!$results) {
        sendMsg(
            $uid,
            "❌ <b>No results found.</b>\n\n" .
            "Try another song/artist name."
        );

        return;
    }

    $results = array_slice(
        $results,
        0,
        10
    );

    $session =
        createSearchSession(
            $uid,
            $results
        );

    $buttons = [];

    foreach ($results as $index => $song) {
        $title =
            mb_substr(
                $song['title'],
                0,
                38
            );

        $buttons[] = [
            [
                'text' =>
                    '▶️ ' . $title,
                'callback_data' =>
                    'pick:' .
                    $session .
                    ':' .
                    $index
            ]
        ];
    }

    sendMsg(
        $uid,
        "<b>🔎 SEARCH RESULTS</b>\n\n" .
        "Query: <code>" .
        esc($query) .
        "</code>\n\n" .
        "Select a song:",
        [
            'reply_markup' =>
                kb($buttons)
        ]
    );
}


/* ============================================================
   PREMIUM
   ============================================================ */

function premiumText(): string
{
    return
        "<b>⭐ MAYAMUSIC PREMIUM</b>\n\n" .
        "<b>₹" .
        MONTHLY_PRICE .
        " / 30 Days</b>\n\n" .

        "🎵 Full music search\n" .
        "🖼 Album artwork\n" .
        "🎤 Lyrics\n" .
        "⏭ Auto-next\n" .
        "⏮ Previous / Next\n" .
        "▶️ Telegram Mini Player\n" .
        "☰ Queue\n" .
        "🎟 Redeem key support\n" .
        "👤 Premium account\n\n" .

        "<b>UPI ID</b>\n" .
        "<code>" .
        esc(UPI_ID) .
        "</code>\n\n" .

        "Payment ke baad UTR submit karein.";
}

function createPayment(
    int $uid
): string {
    $payments = readJson('payments');

    $id =
        'PAY-' .
        date('ymdHis') .
        '-' .
        strtoupper(
            bin2hex(random_bytes(3))
        );

    $payments[$id] = [
        'id' => $id,
        'user_id' => $uid,
        'amount' => MONTHLY_PRICE,
        'status' => 'created',
        'utr' => '',
        'created_at' => now(),
        'utr_submitted_at' => 0,
        'approved_at' => 0,
        'expires_at' => 0
    ];

    writeJson(
        'payments',
        $payments
    );

    return $id;
}

function sendPremium(int $uid): void
{
    $paymentId =
        createPayment($uid);

    $upi =
        'upi://pay?' .
        'pa=' . rawurlencode(UPI_ID) .
        '&pn=' . rawurlencode(UPI_NAME) .
        '&am=' .
        number_format(
            MONTHLY_PRICE,
            2,
            '.',
            ''
        ) .
        '&cu=INR' .
        '&tn=' .
        rawurlencode(
            BOT_NAME .
            ' ' .
            $paymentId
        );

    $buttons = [
        [
            [
                'text' =>
                    '💳 Pay ₹' .
                    MONTHLY_PRICE,
                'url' => $upi
            ]
        ],
        [
            [
                'text' =>
                    '🧾 Submit UTR',
                'callback_data' =>
                    'utr:' .
                    $paymentId
            ]
        ],
        [
            [
                'text' =>
                    '👤 Account',
                'callback_data' =>
                    'account'
            ]
        ]
    ];

    sendMsg(
        $uid,
        premiumText() .
        "\n\n<b>Payment ID:</b>\n" .
        "<code>" .
        esc($paymentId) .
        "</code>",
        [
            'reply_markup' =>
                kb($buttons)
        ]
    );
}


/* ============================================================
   ACCOUNT
   ============================================================ */

function accountText(int $uid): string
{
    $user =
        userRecord($uid);

    if (isAdmin($uid)) {
        return
            "<b>👑 ADMIN ACCOUNT</b>\n\n" .
            "<b>User ID:</b> <code>" .
            $uid .
            "</code>\n\n" .
            "<b>Status:</b> 👑 PERMANENT ACCESS\n\n" .
            "Premium restriction: <b>DISABLED</b>\n" .
            "Admin access: <b>ACTIVE</b>";
    }

    $until =
        (int)(
            $user['premium_until']
            ?? 0
        );

    $active =
        $until > now();

    $payments =
        readJson('payments');

    $lastPayment = null;

    foreach (
        array_reverse(
            $payments,
            true
        ) as $payment
    ) {
        if (
            (int)(
                $payment['user_id']
                ?? 0
            ) === $uid
        ) {
            $lastPayment = $payment;
            break;
        }
    }

    $status =
        $active
            ? '🟢 ACTIVE'
            : '🔴 INACTIVE';

    $valid =
        $active
            ? fmtDate($until)
            : 'Not active';

    return
        "<b>👤 ACCOUNT</b>\n\n" .
        "<b>User ID:</b> <code>" .
        $uid .
        "</code>\n" .
        "<b>Status:</b> " .
        $status .
        "\n" .
        "<b>Valid until:</b> " .
        $valid .
        "\n" .
        "<b>Last payment:</b> " .
        esc(
            (string)(
                $lastPayment['status']
                ?? 'None'
            )
        );
}


/* ============================================================
   REDEEM KEY
   ============================================================ */

function generateRedeemKey(): string
{
    $keys =
        readJson('keys');

    do {
        $key =
            'MAYA-' .
            strtoupper(
                substr(
                    bin2hex(
                        random_bytes(4)
                    ),
                    0,
                    6
                )
            ) .
            '-' .
            strtoupper(
                substr(
                    bin2hex(
                        random_bytes(4)
                    ),
                    0,
                    6
                )
            ) .
            '-' .
            strtoupper(
                substr(
                    bin2hex(
                        random_bytes(4)
                    ),
                    0,
                    6
                )
            );
    } while (
        isset($keys[$key])
    );

    return $key;
}

function createRedeemKey(
    int $days,
    int $adminId
): string {
    $keys =
        readJson('keys');

    $key =
        generateRedeemKey();

    $keys[$key] = [
        'key' => $key,
        'status' => 'unused',
        'duration_days' => $days,
        'created_at' => now(),
        'expires_at' =>
            now() +
            ($days * 86400),
        'created_by' => $adminId,
        'redeemed_by' => 0,
        'redeemed_at' => 0
    ];

    writeJson(
        'keys',
        $keys
    );

    return $key;
}

function redeemKey(
    int $uid,
    string $input
): void {
    $key =
        strtoupper(
            trim($input)
        );

    if ($key === '') {
        sendMsg(
            $uid,
            "🎟 <b>Redeem Key</b>\n\n" .
            "Use:\n" .
            "<code>/redeem MAYA-XXXXXX-XXXXXX-XXXXXX</code>"
        );

        return;
    }

    $keys =
        readJson('keys');

    if (!isset($keys[$key])) {
        sendMsg(
            $uid,
            "❌ Invalid redeem key."
        );

        return;
    }

    $item =
        $keys[$key];

    if (
        ($item['status'] ?? '')
        !== 'unused'
    ) {
        sendMsg(
            $uid,
            "❌ This key has already been used."
        );

        return;
    }

    if (
        (int)(
            $item['expires_at']
            ?? 0
        ) <= now()
    ) {
        sendMsg(
            $uid,
            "❌ This key has expired."
        );

        return;
    }

    $current =
        premiumUntil($uid);

    /*
     * KEY EXPIRY IS THE ACCESS END DATE
     */
    $until =
        max(
            $current,
            (int)$item['expires_at']
        );

    updateUser(
        $uid,
        [
            'premium_until' =>
                $until
        ]
    );

    $keys[$key]['status'] =
        'used';

    $keys[$key]['redeemed_by'] =
        $uid;

    $keys[$key]['redeemed_at'] =
        now();

    writeJson(
        'keys',
        $keys
    );

    sendMsg(
        $uid,
        "✅ <b>Key Redeemed</b>\n\n" .
        "⭐ Premium active until:\n" .
        "<b>" .
        fmtDate($until) .
        "</b>",
        [
            'reply_markup' =>
                kb([
                    [
                        [
                            'text' =>
                                '👤 Account',
                            'callback_data' =>
                                'account'
                        ],
                        [
                            'text' =>
                                '🔎 Search',
                            'callback_data' =>
                                'search'
                        ]
                    ]
                ])
        ]
    );
}


/* ============================================================
   UTR PAYMENT
   ============================================================ */

function submitUtr(
    int $uid,
    string $paymentId,
    string $utr
): void {
    $payments =
        readJson('payments');

    if (
        !isset(
            $payments[$paymentId]
        )
    ) {
        sendMsg(
            $uid,
            "❌ Payment ID not found."
        );

        return;
    }

    if (
        (int)(
            $payments[$paymentId]['user_id']
            ?? 0
        ) !== $uid
    ) {
        sendMsg(
            $uid,
            "❌ Access denied."
        );

        return;
    }

    $utr =
        trim($utr);

    if (
        $utr === '' ||
        strlen($utr) > 120
    ) {
        sendMsg(
            $uid,
            "❌ Invalid UTR."
        );

        return;
    }

    $payments[$paymentId]['utr'] =
        $utr;

    $payments[$paymentId]['status'] =
        'pending';

    $payments[$paymentId]['utr_submitted_at'] =
        now();

    writeJson(
        'payments',
        $payments
    );

    sendMsg(
        $uid,
        "🧾 <b>UTR Submitted</b>\n\n" .
        "Payment ID:\n" .
        "<code>" .
        esc($paymentId) .
        "</code>\n\n" .
        "Admin approval pending."
    );

    sendMsg(
        ADMIN_ID,
        "💳 <b>NEW PAYMENT</b>\n\n" .
        "Payment: <code>" .
        esc($paymentId) .
        "</code>\n" .
        "User: <code>" .
        $uid .
        "</code>\n" .
        "Amount: ₹" .
        MONTHLY_PRICE .
        "\n" .
        "UTR: <code>" .
        esc($utr) .
        "</code>",
        [
            'reply_markup' =>
                kb([
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
                ])
        ]
    );
}

function handleUtrCommand(
    int $uid,
    string $args
): void {
    $parts =
        preg_split(
            '/\s+/',
            trim($args),
            2
        );

    $paymentId =
        trim($parts[0] ?? '');

    $utr =
        trim($parts[1] ?? '');

    if (
        $paymentId === '' ||
        $utr === ''
    ) {
        sendMsg(
            $uid,
            "Usage:\n" .
            "<code>/utr PAYMENT_ID UTR</code>"
        );

        return;
    }

    submitUtr(
        $uid,
        $paymentId,
        $utr
    );
}


/* ============================================================
   PAYMENT APPROVAL
   ============================================================ */

function processPaymentDecision(
    int $adminId,
    string $paymentId,
    bool $approve
): void {
    if (!isAdmin($adminId)) {
        return;
    }

    $payments =
        readJson('payments');

    if (
        !isset(
            $payments[$paymentId]
        )
    ) {
        sendMsg(
            $adminId,
            "❌ Payment not found."
        );

        return;
    }

    $payment =
        $payments[$paymentId];

    if (
        ($payment['status'] ?? '')
        !== 'pending'
    ) {
        sendMsg(
            $adminId,
            "⚠️ Payment already processed."
        );

        return;
    }

    $uid =
        (int)$payment['user_id'];

    if ($approve) {
        $base =
            max(
                now(),
                premiumUntil($uid)
            );

        $until =
            $base +
            (ACCESS_DAYS * 86400);

        $payment['status'] =
            'approved';

        $payment['approved_at'] =
            now();

        $payment['expires_at'] =
            $until;

        $payments[$paymentId] =
            $payment;

        writeJson(
            'payments',
            $payments
        );

        updateUser(
            $uid,
            [
                'premium_until' =>
                    $until
            ]
        );

        sendMsg(
            $uid,
            "✅ <b>Payment Approved</b>\n\n" .
            "⭐ Premium activated.\n\n" .
            "Valid until:\n" .
            "<b>" .
            fmtDate($until) .
            "</b>"
        );

        sendMsg(
            $adminId,
            "✅ Payment approved.\n" .
            "User: <code>" .
            $uid .
            "</code>\n" .
            "Until: <b>" .
            fmtDate($until) .
            "</b>"
        );
    } else {
        $payment['status'] =
            'declined';

        $payments[$paymentId] =
            $payment;

        writeJson(
            'payments',
            $payments
        );

        sendMsg(
            $uid,
            "❌ <b>Payment Declined</b>\n\n" .
            "Please contact support."
        );

        sendMsg(
            $adminId,
            "❌ Payment declined.\n" .
            "Payment: <code>" .
            esc($paymentId) .
            "</code>"
        );
    }
}


/* ============================================================
   ADMIN PANEL
   ============================================================ */

function adminPanel(int $uid): void
{
    if (!isAdmin($uid)) {
        return;
    }

    $users =
        readJson('users');

    $keys =
        readJson('keys');

    $payments =
        readJson('payments');

    $active = 0;

    foreach ($users as $user) {
        if (
            (int)(
                $user['premium_until']
                ?? 0
            ) > now()
        ) {
            $active++;
        }
    }

    $pending = 0;

    foreach ($payments as $payment) {
        if (
            ($payment['status'] ?? '')
            === 'pending'
        ) {
            $pending++;
        }
    }

    $text =
        "<b>🛠 MAYAMUSIC ADMIN</b>\n\n" .
        "👥 Users: <b>" .
        count($users) .
        "</b>\n" .
        "🟢 Active premium: <b>" .
        $active .
        "</b>\n" .
        "🎟 Keys: <b>" .
        count($keys) .
        "</b>\n" .
        "💳 Pending payments: <b>" .
        $pending .
        "</b>\n\n" .

        "<b>Commands</b>\n" .
        "<code>/genkey 30</code>\n" .
        "<code>/give USER_ID 30</code>\n" .
        "<code>/revoke USER_ID</code>\n" .
        "<code>/broadcast message</code>\n" .
        "<code>/stats</code>\n" .
        "<code>/apitest</code>\n" .
        "<code>/webhookinfo</code>";

    $buttons = [
        [
            [
                'text' =>
                    '🎟 Generate Key',
                'callback_data' =>
                    'akey'
            ]
        ],
        [
            [
                'text' =>
                    '💳 Payments',
                'callback_data' =>
                    'apays'
            ],
            [
                'text' =>
                    '🎟 Keys',
                'callback_data' =>
                    'akeys'
            ]
        ],
        [
            [
                'text' =>
                    '👥 Users',
                'callback_data' =>
                    'ausers'
            ],
            [
                'text' =>
                    '📊 Stats',
                'callback_data' =>
                    'astats'
            ]
        ],
        [
            [
                'text' =>
                    '🏠 Home',
                'callback_data' =>
                    'home'
            ]
        ]
    ];

    sendMsg(
        $uid,
        $text,
        [
            'reply_markup' =>
                kb($buttons)
        ]
    );
}


/* ============================================================
   ADMIN COMMANDS
   ============================================================ */

function handleAdminCommand(
    int $uid,
    string $text
): bool {
    if (!isAdmin($uid)) {
        return false;
    }

    $parts =
        preg_split(
            '/\s+/',
            trim($text)
        );

    $cmd =
        strtolower(
            $parts[0] ?? ''
        );

    if ($cmd === '/admin') {
        adminPanel($uid);
        return true;
    }

    if ($cmd === '/genkey') {
        $days =
            (int)(
                $parts[1] ?? 30
            );

        if ($days < 1) {
            $days = 30;
        }

        if ($days > 3650) {
            $days = 3650;
        }

        $key =
            createRedeemKey(
                $days,
                $uid
            );

        sendMsg(
            $uid,
            "🎟 <b>KEY GENERATED</b>\n\n" .
            "<code>" .
            esc($key) .
            "</code>\n\n" .
            "Duration: <b>" .
            $days .
            " days</b>"
        );

        return true;
    }

    if ($cmd === '/give') {
        $target =
            (int)(
                $parts[1] ?? 0
            );

        $days =
            (int)(
                $parts[2] ?? 30
            );

        if (
            $target < 1 ||
            $days < 1
        ) {
            sendMsg(
                $uid,
                "Usage:\n" .
                "<code>/give USER_ID DAYS</code>"
            );

            return true;
        }

        $base =
            max(
                now(),
                premiumUntil($target)
            );

        $until =
            $base +
            ($days * 86400);

        updateUser(
            $target,
            [
                'premium_until' =>
                    $until
            ]
        );

        sendMsg(
            $uid,
            "✅ Premium granted.\n\n" .
            "User: <code>" .
            $target .
            "</code>\n" .
            "Days: <b>" .
            $days .
            "</b>"
        );

        sendMsg(
            $target,
            "⭐ <b>Premium Activated</b>\n\n" .
            "Valid until:\n" .
            "<b>" .
            fmtDate($until) .
            "</b>"
        );

        return true;
    }

    if ($cmd === '/revoke') {
        $target =
            (int)(
                $parts[1] ?? 0
            );

        if ($target < 1) {
            sendMsg(
                $uid,
                "Usage:\n" .
                "<code>/revoke USER_ID</code>"
            );

            return true;
        }

        /*
         * ADMIN CAN NEVER BE REVOKED
         */
        if (isAdmin($target)) {
            sendMsg(
                $uid,
                "👑 Admin access cannot be revoked."
            );

            return true;
        }

        updateUser(
            $target,
            [
                'premium_until' => 0
            ]
        );

        sendMsg(
            $uid,
            "✅ Premium revoked for:\n" .
            "<code>" .
            $target .
            "</code>"
        );

        sendMsg(
            $target,
            "⚠️ Your premium access has been revoked."
        );

        return true;
    }

    if ($cmd === '/broadcast') {
        $message =
            trim(
                preg_replace(
                    '/^\S+\s*/',
                    '',
                    $text
                )
            );

        if ($message === '') {
            sendMsg(
                $uid,
                "Usage:\n" .
                "<code>/broadcast Your message</code>"
            );

            return true;
        }

        $users =
            readJson('users');

        $sent = 0;
        $failed = 0;

        foreach ($users as $id => $user) {
            $result =
                sendMsg(
                    (int)$id,
                    $message
                );

            if (
                ($result['ok'] ?? false)
            ) {
                $sent++;
            } else {
                $failed++;
            }

            usleep(60000);
        }

        sendMsg(
            $uid,
            "📢 <b>Broadcast complete</b>\n\n" .
            "Sent: <b>" .
            $sent .
            "</b>\n" .
            "Failed: <b>" .
            $failed .
            "</b>"
        );

        return true;
    }

    if ($cmd === '/stats') {
        adminStats($uid);
        return true;
    }

    if ($cmd === '/apitest') {
        apiTest($uid);
        return true;
    }

    if ($cmd === '/webhookinfo') {
        webhookInfo($uid);
        return true;
    }

    if ($cmd === '/setwebhook') {
        setWebhookCommand($uid);
        return true;
    }

    if ($cmd === '/delwebhook') {
        deleteWebhookCommand($uid);
        return true;
    }

    return false;
}


/* ============================================================
   ADMIN STATS
   ============================================================ */

function adminStats(int $uid): void
{
    if (!isAdmin($uid)) {
        return;
    }

    $users =
        readJson('users');

    $payments =
        readJson('payments');

    $keys =
        readJson('keys');

    $approved = 0;
    $revenue = 0;
    $pending = 0;

    foreach ($payments as $payment) {
        $status =
            $payment['status'] ?? '';

        if ($status === 'approved') {
            $approved++;

            $revenue +=
                (int)(
                    $payment['amount']
                    ?? 0
                );
        }

        if ($status === 'pending') {
            $pending++;
        }
    }

    sendMsg(
        $uid,
        "<b>📊 MAYAMUSIC STATISTICS</b>\n\n" .
        "👥 Users: <b>" .
        count($users) .
        "</b>\n" .
        "🎟 Keys: <b>" .
        count($keys) .
        "</b>\n" .
        "💳 Approved: <b>" .
        $approved .
        "</b>\n" .
        "⏳ Pending: <b>" .
        $pending .
        "</b>\n" .
        "💰 Recorded revenue: <b>₹" .
        $revenue .
        "</b>"
    );
}


/* ============================================================
   ADMIN USERS
   ============================================================ */

function adminUsers(int $uid): void
{
    if (!isAdmin($uid)) {
        return;
    }

    $users =
        readJson('users');

    $text =
        "<b>👥 USERS</b>\n\n";

    $count = 0;

    foreach (
        array_reverse(
            $users,
            true
        ) as $id => $user
    ) {
        $until =
            (int)(
                $user['premium_until']
                ?? 0
            );

        $status =
            $until > now()
                ? '🟢'
                : '🔴';

        $text .=
            "<code>" .
            (int)$id .
            "</code> " .
            $status .
            " " .
            esc(
                (string)(
                    $user['first_name']
                    ?? ''
                )
            ) .
            "\n";

        $count++;

        if ($count >= 30) {
            break;
        }
    }

    sendMsg(
        $uid,
        $text,
        [
            'reply_markup' =>
                kb([
                    [
                        [
                            'text' =>
                                '⬅️ Admin',
                            'callback_data' =>
                                'admin'
                        ]
                    ]
                ])
        ]
    );
}


/* ============================================================
   ADMIN KEYS
   ============================================================ */

function adminKeys(int $uid): void
{
    if (!isAdmin($uid)) {
        return;
    }

    $keys =
        readJson('keys');

    $text =
        "<b>🎟 REDEEM KEYS</b>\n\n";

    if (!$keys) {
        $text .=
            "No keys generated.";
    } else {
        $items =
            array_reverse(
                $keys,
                true
            );

        $count = 0;

        foreach ($items as $key => $item) {
            $text .=
                "<code>" .
                esc($key) .
                "</code>\n" .
                "Status: <b>" .
                esc(
                    (string)(
                        $item['status']
                        ?? ''
                    )
                ) .
                "</b>\n" .
                "Expires: " .
                fmtDate(
                    (int)(
                        $item['expires_at']
                        ?? 0
                    )
                ) .
                "\n\n";

            $count++;

            if ($count >= 10) {
                break;
            }
        }
    }

    sendMsg(
        $uid,
        $text,
        [
            'reply_markup' =>
                kb([
                    [
                        [
                            'text' =>
                                '🎟 Generate 30D',
                            'callback_data' =>
                                'akey'
                        ]
                    ],
                    [
                        [
                            'text' =>
                                '⬅️ Admin',
                            'callback_data' =>
                                'admin'
                        ]
                    ]
                ])
        ]
    );
}


/* ============================================================
   ADMIN PAYMENTS
   ============================================================ */

function adminPayments(int $uid): void
{
    if (!isAdmin($uid)) {
        return;
    }

    $payments =
        readJson('payments');

    $text =
        "<b>💳 PENDING PAYMENTS</b>\n\n";

    $buttons = [];
    $count = 0;

    foreach (
        array_reverse(
            $payments,
            true
        ) as $id => $payment
    ) {
        if (
            ($payment['status'] ?? '')
            !== 'pending'
        ) {
            continue;
        }

        $text .=
            "<code>" .
            esc($id) .
            "</code>\n" .
            "User: <code>" .
            (int)$payment['user_id'] .
            "</code>\n" .
            "Amount: ₹" .
            (int)$payment['amount'] .
            "\n" .
            "UTR: <code>" .
            esc(
                (string)(
                    $payment['utr']
                    ?? ''
                )
            ) .
            "</code>\n\n";

        $buttons[] = [
            [
                'text' =>
                    '✅ Approve',
                'callback_data' =>
                    'approve:' . $id
            ],
            [
                'text' =>
                    '❌ Decline',
                'callback_data' =>
                    'decline:' . $id
            ]
        ];

        $count++;

        if ($count >= 10) {
            break;
        }
    }

    if ($count === 0) {
        $text .=
            "No pending payments.";
    }

    $buttons[] = [
        [
            'text' =>
                '⬅️ Admin',
            'callback_data' =>
                'admin'
        ]
    ];

    sendMsg(
        $uid,
        $text,
        [
            'reply_markup' =>
                kb($buttons)
        ]
    );
}


/* ============================================================
   API TEST
   ============================================================ */

function apiTest(int $uid): void
{
    if (!isAdmin($uid)) {
        return;
    }

    $testQuery =
        'Chandni';

    $url =
        MUSIC_API .
        rawurlencode($testQuery);

    $response =
        httpGetJson(
            $url,
            20
        );

    if (
        !($response['ok'] ?? false)
    ) {
        sendMsg(
            $uid,
            "❌ <b>MUSIC API TEST FAILED</b>\n\n" .
            "HTTP: <code>" .
            (int)(
                $response['http_code']
                ?? 0
            ) .
            "</code>\n" .
            "Error: <code>" .
            esc(
                (string)(
                    $response['error']
                    ?? 'Unknown error'
                )
            ) .
            "</code>"
        );

        return;
    }

    $data =
        $response['data']
        ?? [];

    $count =
        is_array(
            $data['results'] ?? null
        )
            ? count($data['results'])
            : 0;

    $first =
        $data['results'][0]
        ?? null;

    if (is_array($first)) {
        sendMsg(
            $uid,
            "✅ <b>MUSIC API WORKING</b>\n\n" .
            "HTTP: <b>" .
            (int)$response['http_code'] .
            "</b>\n" .
            "Results: <b>" .
            $count .
            "</b>\n\n" .
            "Title: <b>" .
            esc(
                (string)(
                    $first['title']
                    ?? ''
                )
            ) .
            "</b>\n" .
            "Artist: <b>" .
            esc(
                (string)(
                    $first['artists']
                    ?? ''
                )
            ) .
            "</b>\n" .
            "Album: <b>" .
            esc(
                (string)(
                    $first['album']
                    ?? ''
                )
            ) .
            "</b>\n" .
            "Duration: <b>" .
            esc(
                (string)(
                    $first['duration']
                    ?? ''
                )
            ) .
            "</b>\n" .
            "Download URL: <b>" .
            (
                !empty(
                    $first['download_url']
                )
                ? 'YES'
                : 'NO'
            ) .
            "</b>"
        );
    } else {
        sendMsg(
            $uid,
            "⚠️ API responded but no valid results were found.\n\n" .
            "HTTP: <b>" .
            (int)$response['http_code'] .
            "</b>"
        );
    }
}


/* ============================================================
   WEBHOOK
   ============================================================ */

function webhookInfo(int $uid): void
{
    if (!isAdmin($uid)) {
        return;
    }

    $result =
        tg('getWebhookInfo');

    if (
        !($result['ok'] ?? false)
    ) {
        sendMsg(
            $uid,
            "❌ Webhook check failed.\n\n" .
            esc(
                (string)(
                    $result['description']
                    ?? ''
                )
            )
        );

        return;
    }

    $data =
        $result['result']
        ?? [];

    $url =
        (string)(
            $data['url'] ?? ''
        );

    $lastError =
        (string)(
            $data['last_error_message']
            ?? 'None'
        );

    $pending =
        (int)(
            $data['pending_update_count']
            ?? 0
        );

    sendMsg(
        $uid,
        "<b>🔗 WEBHOOK INFO</b>\n\n" .
        "URL:\n<code>" .
        esc(
            $url !== ''
                ? $url
                : 'NOT SET'
        ) .
        "</code>\n\n" .
        "Pending updates: <b>" .
        $pending .
        "</b>\n" .
        "Last error:\n<code>" .
        esc($lastError) .
        "</code>"
    );
}

function setWebhookCommand(int $uid): void
{
    if (!isAdmin($uid)) {
        return;
    }

    $url =
        configWebAppUrl();

    if (
        $url === '' ||
        !str_starts_with(
            $url,
            'https://'
        )
    ) {
        sendMsg(
            $uid,
            "❌ WEBAPP_URL must be a public HTTPS URL."
        );

        return;
    }

    $result =
        tg(
            'setWebhook',
            [
                'url' => $url,
                'allowed_updates' =>
                    json_encode([
                        'message',
                        'callback_query',
                        'pre_checkout_query'
                    ])
            ]
        );

    if (
        $result['ok'] ?? false
    ) {
        sendMsg(
            $uid,
            "✅ <b>Webhook set successfully.</b>\n\n" .
            "<code>" .
            esc($url) .
            "</code>"
        );
    } else {
        sendMsg(
            $uid,
            "❌ Webhook failed:\n\n" .
            "<code>" .
            esc(
                (string)(
                    $result['description']
                    ?? 'Unknown'
                )
            ) .
            "</code>"
        );
    }
}

function deleteWebhookCommand(int $uid): void
{
    if (!isAdmin($uid)) {
        return;
    }

    $result =
        tg('deleteWebhook');

    if (
        $result['ok'] ?? false
    ) {
        sendMsg(
            $uid,
            "✅ Webhook deleted."
        );
    } else {
        sendMsg(
            $uid,
            "❌ Failed to delete webhook."
        );
    }
}


/* ============================================================
   MESSAGE PARSER
   ============================================================ */

function parseCommand(
    string $text
): array {
    $parts =
        preg_split(
            '/\s+/',
            trim($text),
            2
        );

    return [
        strtolower(
            $parts[0] ?? ''
        ),
        trim(
            $parts[1] ?? ''
        )
    ];
}


/* ============================================================
   HELP
   ============================================================ */

function sendHelp(
    int $uid
): void {
    sendMsg(
        $uid,
        "<b>🎵 MAYAMUSIC COMMANDS</b>\n\n" .

        "<b>Music</b>\n" .
        "<code>/search song name</code>\n" .
        "<code>/play song name</code>\n\n" .

        "<b>Account</b>\n" .
        "<code>/account</code>\n" .
        "<code>/premium</code>\n" .
        "<code>/redeem KEY</code>\n\n" .

        "<b>Payment</b>\n" .
        "<code>/utr PAYMENT_ID UTR</code>\n\n" .

        "<b>Group / Channel</b>\n" .
        "<code>/playcc song</code>\n" .
        "<code>/pausecc</code>\n" .
        "<code>/resumecc</code>\n" .
        "<code>/skipcc</code>\n" .
        "<code>/stopcc</code>\n" .
        "<code>/leavecc</code>"
    );
}


/* ============================================================
   GROUP / CHANNEL VC COMMANDS
   ============================================================ */

function channelCommand(
    array $chat,
    string $command,
    string $arg
): void {
    $chatId =
        (int)$chat['id'];

    $channels =
        readJson('channels');

    $key =
        (string)$chatId;

    if (
        !isset(
            $channels[$key]
        )
    ) {
        $channels[$key] = [
            'chat_id' => $chatId,
            'title' =>
                (string)(
                    $chat['title']
                    ?? ''
                ),
            'created_at' => now(),
            'status' => 'idle',
            'current' => null,
            'queue' => []
        ];
    }

    if ($command === '/playcc') {
        if (trim($arg) === '') {
            sendMsg(
                $chatId,
                "Usage:\n" .
                "<code>/playcc song name</code>"
            );

            return;
        }

        $results =
            searchMusic($arg);

        if (!$results) {
            sendMsg(
                $chatId,
                "❌ No song found."
            );

            return;
        }

        /*
         * The supplied API has no rating field.
         * Therefore first API result is selected.
         */

        $song =
            $results[0];

        $channels[$key]['current'] =
            $song;

        $channels[$key]['queue'] =
            $results;

        $channels[$key]['status'] =
            'play_requested';

        writeJson(
            'channels',
            $channels
        );

        sendMsg(
            $chatId,
            "▶️ <b>VC PLAY REQUEST</b>\n\n" .
            "<b>" .
            esc($song['title']) .
            "</b>\n" .
            esc($song['artists']) .
            "\n\n" .
            "Song selected successfully.\n\n" .
            "⚠️ Actual Telegram Voice Chat audio requires a separate MTProto/voice engine. PHP Bot API itself cannot join and stream audio into a VC."
        );

        return;
    }

    $status =
        match ($command) {
            '/pausecc' =>
                'paused',

            '/resumecc' =>
                'playing',

            '/skipcc' =>
                'skip',

            '/stopcc' =>
                'stopped',

            '/leavecc' =>
                'leave',

            default =>
                'idle'
        };

    $channels[$key]['status'] =
        $status;

    writeJson(
        'channels',
        $channels
    );

    sendMsg(
        $chatId,
        "🎛 <b>" .
        strtoupper(
            ltrim(
                $command,
                '/'
            )
        ) .
        "</b>\n\n" .
        "Control event recorded."
    );
}


/* ============================================================
   MESSAGE HANDLER
   ============================================================ */

function handleMessage(
    array $message
): void {
    $chat =
        $message['chat']
        ?? [];

    $from =
        $message['from']
        ?? [];

    $uid =
        (int)(
            $from['id']
            ?? 0
        );

    if ($uid <= 0) {
        return;
    }

    $chatId =
        $chat['id']
        ?? $uid;

    $chatType =
        (string)(
            $chat['type']
            ?? 'private'
        );

    $text =
        trim(
            (string)(
                $message['text']
                ?? ''
            )
        );

    userRecord($uid);

    updateUser(
        $uid,
        [
            'username' =>
                (string)(
                    $from['username']
                    ?? ''
                ),
            'first_name' =>
                (string)(
                    $from['first_name']
                    ?? ''
                )
        ]
    );

    if (
        handleAdminCommand(
            $uid,
            $text
        )
    ) {
        return;
    }

    [
        $command,
        $args
    ] =
        parseCommand($text);

    if ($command === '/start') {
        if ($chatType !== 'private') {
            sendMsg(
                $chatId,
                "🎵 <b>" .
                BOT_NAME .
                "</b>\n\n" .
                "This group/channel has FREE access."
            );

            return;
        }

        sendMsg(
            $uid,
            "<b>🎵 WELCOME TO MAYAMUSIC</b>\n\n" .
            "Search music, open Mini Player, lyrics, queue, premium and redeem keys.",
            [
                'reply_markup' =>
                    mainKeyboard($uid)
            ]
        );

        return;
    }

    if ($command === '/help') {
        sendHelp($uid);
        return;
    }

    if (
        $command === '/search' ||
        $command === '/play'
    ) {
        showSearch(
            $uid,
            $args
        );

        return;
    }

    if ($command === '/premium') {
        if ($chatType !== 'private') {
            sendMsg(
                $chatId,
                "🎵 This group/channel is FREE.\n" .
                "Premium is for private users."
            );
        } else {
            sendPremium($uid);
        }

        return;
    }

    if ($command === '/account') {
        sendMsg(
            $uid,
            accountText($uid),
            [
                'reply_markup' =>
                    kb([
                        [
                            [
                                'text' =>
                                    '⭐ Premium',
                                'callback_data' =>
                                    'premium'
                            ],
                            [
                                'text' =>
                                    '🏠 Home',
                                'callback_data' =>
                                    'home'
                            ]
                        ]
                    ])
            ]
        );

        return;
    }

    if ($command === '/redeem') {
        redeemKey(
            $uid,
            $args
        );

        return;
    }

    if ($command === '/utr') {
        handleUtrCommand(
            $uid,
            $args
        );

        return;
    }

    if (
        in_array(
            $command,
            [
                '/playcc',
                '/pausecc',
                '/resumecc',
                '/skipcc',
                '/stopcc',
                '/leavecc'
            ],
            true
        )
    ) {
        if ($chatType === 'private') {
            sendMsg(
                $uid,
                "Use this command in a group/channel."
            );

            return;
        }

        if (!isAdmin($uid)) {
            sendMsg(
                $chatId,
                "🔒 Only the bot owner/admin can control VC commands."
            );

            return;
        }

        channelCommand(
            $chat,
            $command,
            $args
        );

        return;
    }

    /*
     * Plain text in private chat
     * works as music search.
     */

    if (
        $chatType === 'private' &&
        $text !== ''
    ) {
        showSearch(
            $uid,
            $text
        );
    }
}


/* ============================================================
   CALLBACK HANDLER
   ============================================================ */

function handleCallback(
    array $callback
): void {
    $id =
        (string)(
            $callback['id']
            ?? ''
        );

    $uid =
        (int)(
            $callback['from']['id']
            ?? 0
        );

    $data =
        (string)(
            $callback['data']
            ?? ''
        );

    userRecord($uid);

    if ($data === 'home') {
        answerCb($id);

        sendMsg(
            $uid,
            "<b>🎵 MAYAMUSIC</b>",
            [
                'reply_markup' =>
                    mainKeyboard($uid)
            ]
        );

        return;
    }

    if ($data === 'account') {
        answerCb($id);

        sendMsg(
            $uid,
            accountText($uid)
        );

        return;
    }

    if ($data === 'premium') {
        answerCb($id);

        sendPremium($uid);

        return;
    }

    if ($data === 'search') {
        answerCb($id);

        sendMsg(
            $uid,
            "🔎 Send:\n\n" .
            "<code>/search song name</code>"
        );

        return;
    }

    if ($data === 'redeem') {
        answerCb($id);

        sendMsg(
            $uid,
            "🎟 Send your key:\n\n" .
            "<code>/redeem MAYA-XXXXXX-XXXXXX-XXXXXX</code>"
        );

        return;
    }

    if ($data === 'player') {
        answerCb($id);

        if (!privateAccess($uid)) {
            sendMsg(
                $uid,
                "🔒 Premium required."
            );

            return;
        }

        sendMsg(
            $uid,
            "▶️ <b>Mini Player</b>\n\n" .
            "Search a song first and then press the Player button."
        );

        return;
    }

    if ($data === 'admin') {
        answerCb($id);

        if (isAdmin($uid)) {
            adminPanel($uid);
        }

        return;
    }

    if ($data === 'akey') {
        answerCb($id);

        if (!isAdmin($uid)) {
            return;
        }

        $key =
            createRedeemKey(
                30,
                $uid
            );

        sendMsg(
            $uid,
            "🎟 <b>NEW 30 DAY KEY</b>\n\n" .
            "<code>" .
            esc($key) .
            "</code>"
        );

        return;
    }

    if ($data === 'akeys') {
        answerCb($id);

        adminKeys($uid);

        return;
    }

    if ($data === 'apays') {
        answerCb($id);

        adminPayments($uid);

        return;
    }

    if ($data === 'ausers') {
        answerCb($id);

        adminUsers($uid);

        return;
    }

    if ($data === 'astats') {
        answerCb($id);

        adminStats($uid);

        return;
    }

    /*
     * MUSIC SELECTION
     */

    if (
        str_starts_with(
            $data,
            'pick:'
        )
    ) {
        answerCb(
            $id,
            'Preparing player…'
        );

        $parts =
            explode(
                ':',
                $data
            );

        $session =
            $parts[1] ?? '';

        $index =
            (int)(
                $parts[2] ?? -1
            );

        $searches =
            readJson('searches');

        $item =
            $searches[$session]
            ?? null;

        if (
            !is_array($item)
        ) {
            sendMsg(
                $uid,
                "❌ Search session expired. Search again."
            );

            return;
        }

        if (
            (int)(
                $item['user_id']
                ?? 0
            ) !== $uid
        ) {
            sendMsg(
                $uid,
                "❌ This result does not belong to you."
            );

            return;
        }

        if (
            (int)(
                $item['expires_at']
                ?? 0
            ) < now()
        ) {
            sendMsg(
                $uid,
                "❌ Search session expired."
            );

            return;
        }

        if (!privateAccess($uid)) {
            sendMsg(
                $uid,
                "🔒 Premium required."
            );

            return;
        }

        $queue =
            $item['results']
            ?? [];

        $song =
            $queue[$index]
            ?? null;

        if (
            !is_array($song) ||
            empty(
                $song['download_url']
            )
        ) {
            sendMsg(
                $uid,
                "❌ Invalid song."
            );

            return;
        }

        /*
         * Artwork is resolved separately
         * because supplied API has no thumbnail.
         */

        $art =
            artwork(
                $song['title'],
                $song['artists']
            );

        /*
         * Add artwork to every queue item
         */

        foreach (
            $queue as $qIndex => $queueSong
        ) {
            if (
                empty(
                    $queueSong['artwork']
                )
            ) {
                $queue[$qIndex]['artwork'] =
                    artwork(
                        $queueSong['title'],
                        $queueSong['artists']
                    );
            }
        }

        $song =
            $queue[$index];

        $token =
            createPlayerToken(
                $song,
                $queue
            );

        $caption =
            "<b>▶️ " .
            esc($song['title']) .
            "</b>\n" .
            esc($song['artists']);

        if (
            !empty(
                $song['album']
            )
        ) {
            $caption .=
                "\n💿 " .
                esc($song['album']);
        }

        if (
            !empty(
                $song['duration']
            )
        ) {
            $caption .=
                "\n⏱ " .
                esc($song['duration']);
        }

        $buttons = [
            [
                [
                    'text' =>
                        '🎧 OPEN MINI PLAYER',
                    'web_app' => [
                        'url' =>
                            playerUrl($token)
                    ]
                ]
            ]
        ];

        /*
         * If artwork exists send photo.
         * Otherwise normal message.
         */

        if ($art !== '') {
            tg(
                'sendPhoto',
                [
                    'chat_id' => $uid,
                    'photo' => $art,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                    'reply_markup' =>
                        kb($buttons)
                ]
            );
        } else {
            sendMsg(
                $uid,
                $caption,
                [
                    'reply_markup' =>
                        kb($buttons)
                ]
            );
        }

        return;
    }

    /*
     * UTR BUTTON
     */

    if (
        str_starts_with(
            $data,
            'utr:'
        )
    ) {
        answerCb($id);

        $paymentId =
            substr(
                $data,
                4
            );

        sendMsg(
            $uid,
            "🧾 <b>SUBMIT UTR</b>\n\n" .
            "Payment ID:\n" .
            "<code>" .
            esc($paymentId) .
            "</code>\n\n" .
            "Send:\n" .
            "<code>/utr " .
            esc($paymentId) .
            " YOUR_UTR</code>"
        );

        return;
    }

    /*
     * PAYMENT APPROVAL
     */

    if (
        str_starts_with(
            $data,
            'approve:'
        )
    ) {
        if (!isAdmin($uid)) {
            answerCb(
                $id,
                'Access denied',
                true
            );

            return;
        }

        answerCb(
            $id,
            'Approved'
        );

        processPaymentDecision(
            $uid,
            substr(
                $data,
                8
            ),
            true
        );

        return;
    }

    if (
        str_starts_with(
            $data,
            'decline:'
        )
    ) {
        if (!isAdmin($uid)) {
            answerCb(
                $id,
                'Access denied',
                true
            );

            return;
        }

        answerCb(
            $id,
            'Declined'
        );

        processPaymentDecision(
            $uid,
            substr(
                $data,
                8
            ),
            false
        );

        return;
    }
}


/* ============================================================
   MINI APP PLAYER
   ============================================================ */

function miniApp(): void
{
    $token =
        trim(
            (string)(
                $_GET['token']
                ?? ''
            )
        );

    $players =
        readJson('players');

    $player =
        $players[$token]
        ?? null;

    header(
        'Content-Type: text/html; charset=UTF-8'
    );

    if (
        !is_array($player)
    ) {
        http_response_code(404);

        echo '
        <!doctype html>
        <html>
        <head>
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>MAYAMUSIC</title>
        <style>
        body{
            margin:0;
            background:#08090d;
            color:white;
            font-family:system-ui;
            display:flex;
            min-height:100vh;
            align-items:center;
            justify-content:center;
        }
        </style>
        </head>
        <body>
        <h2>Player expired</h2>
        </body>
        </html>';

        return;
    }

    if (
        (int)(
            $player['expires_at']
            ?? 0
        ) < now()
    ) {
        http_response_code(410);

        echo '
        <!doctype html>
        <html>
        <body style="
            background:#08090d;
            color:white;
            font-family:system-ui;
            text-align:center;
            padding:60px;
        ">
        <h2>Player expired</h2>
        <p>Search the song again.</p>
        </body>
        </html>';

        return;
    }

    $song =
        $player['song']
        ?? [];

    $queue =
        $player['queue']
        ?? [];

    $songTitle =
        json_encode(
            (string)(
                $song['title']
                ?? ''
            ),
            JSON_UNESCAPED_UNICODE
        );

    $songArtist =
        json_encode(
            (string)(
                $song['artists']
                ?? ''
            ),
            JSON_UNESCAPED_UNICODE
        );

    $songUrl =
        json_encode(
            (string)(
                $song['download_url']
                ?? ''
            ),
            JSON_UNESCAPED_SLASHES
        );

    $songArt =
        json_encode(
            (string)(
                $song['artwork']
                ?? artwork(
                    (string)(
                        $song['title']
                        ?? ''
                    ),
                    (string)(
                        $song['artists']
                        ?? ''
                    )
                )
            ),
            JSON_UNESCAPED_SLASHES
        );

    $queueJson =
        json_encode(
            $queue,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

    echo '<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width,initial-scale=1,viewport-fit=cover"
>

<title>MAYAMUSIC</title>

<style>

*{
    box-sizing:border-box;
    -webkit-tap-highlight-color:transparent;
}

body{
    margin:0;
    min-height:100vh;
    background:
        radial-gradient(
            circle at 50% 0%,
            #292d3a 0%,
            #0c0d12 48%,
            #07080b 100%
        );
    color:#fff;
    font-family:
        Inter,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        sans-serif;
}

.app{
    min-height:100vh;
    padding:
        calc(20px + env(safe-area-inset-top))
        20px
        calc(28px + env(safe-area-inset-bottom));
    display:flex;
    flex-direction:column;
}

.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:12px;
}

.brand{
    font-weight:900;
    letter-spacing:.5px;
}

.badge{
    font-size:11px;
    opacity:.5;
}

.cover{
    width:min(84vw,360px);
    aspect-ratio:1;
    object-fit:cover;
    border-radius:28px;
    margin:24px auto;
    display:block;
    background:#171920;
    box-shadow:
        0 24px 80px rgba(0,0,0,.55);
}

.title{
    text-align:center;
    font-size:24px;
    font-weight:900;
    line-height:1.2;
    margin-top:4px;
}

.artist{
    text-align:center;
    opacity:.62;
    margin-top:8px;
    font-size:14px;
}

.progressArea{
    margin-top:26px;
}

.progress{
    width:100%;
    height:5px;
    background:#292c34;
    border-radius:100px;
    overflow:hidden;
    cursor:pointer;
}

.progressFill{
    width:0%;
    height:100%;
    background:#fff;
    border-radius:100px;
}

.times{
    display:flex;
    justify-content:space-between;
    margin-top:8px;
    font-size:11px;
    opacity:.5;
}

.controls{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:18px;
    margin-top:24px;
}

.control{
    width:58px;
    height:58px;
    border:0;
    border-radius:50%;
    background:#1c1f27;
    color:#fff;
    font-size:20px;
    box-shadow:
        0 10px 30px rgba(0,0,0,.2);
}

.control:active{
    transform:scale(.92);
}

.play{
    width:74px;
    height:74px;
    background:#fff;
    color:#000;
    font-size:25px;
}

.panelButtons{
    display:flex;
    gap:10px;
    margin-top:24px;
}

.panelButton{
    flex:1;
    border:0;
    border-radius:15px;
    background:#171a21;
    color:#fff;
    padding:14px 10px;
    font-size:13px;
}

.status{
    text-align:center;
    font-size:11px;
    opacity:.45;
    margin-top:14px;
}

.lyrics{
    margin-top:24px;
    padding:18px;
    background:rgba(255,255,255,.035);
    border-radius:20px;
    max-height:31vh;
    overflow:auto;
    white-space:pre-wrap;
    text-align:center;
    line-height:1.8;
    font-size:14px;
}

.queue{
    display:none;
    margin-top:18px;
    padding:16px;
    background:rgba(255,255,255,.04);
    border-radius:20px;
    max-height:30vh;
    overflow:auto;
}

.queueItem{
    padding:12px 8px;
    border-bottom:1px solid rgba(255,255,255,.07);
    font-size:13px;
}

.queueItem:last-child{
    border-bottom:0;
}

</style>

</head>

<body>

<div class="app">

<div class="top">
    <div class="brand">MAYAMUSIC</div>
    <div class="badge">MINI PLAYER</div>
</div>

<img
id="cover"
class="cover"
src=""
alt="Artwork"
>

<div
id="title"
class="title"
></div>

<div
id="artist"
class="artist"
></div>

<div class="progressArea">

<div
id="progress"
class="progress"
>
<div
id="progressFill"
class="progressFill"
></div>
</div>

<div class="times">
<span id="current">0:00</span>
<span id="duration">0:00</span>
</div>

</div>

<div class="controls">

<button
id="prev"
class="control"
>
⏮
</button>

<button
id="play"
class="control play"
>
▶
</button>

<button
id="next"
class="control"
>
⏭
</button>

</div>

<div class="panelButtons">

<button
id="lyricsButton"
class="panelButton"
>
🎤 Lyrics
</button>

<button
id="queueButton"
class="panelButton"
>
☰ Queue
</button>

</div>

<div
id="lyrics"
class="lyrics"
>
Loading lyrics…
</div>

<div
id="queue"
class="queue"
></div>

<div
id="status"
class="status"
>
Ready
</div>

<audio
id="audio"
preload="auto"
></audio>

</div>

<script src="https://telegram.org/js/telegram-web-app.js"></script>

<script>

const tg =
    window.Telegram &&
    window.Telegram.WebApp
        ? window.Telegram.WebApp
        : null;

if(tg){
    tg.ready();
    tg.expand();
}

let queue =
    ' . $queueJson . ';

if(!Array.isArray(queue)){
    queue = [];
}

let currentIndex = 0;

let currentSong =
    ' . $songUrl . ';

let currentTitle =
    ' . $songTitle . ';

let currentArtist =
    ' . $songArtist . ';

let currentArtwork =
    ' . $songArt . ';

const audio =
    document.getElementById("audio");

const cover =
    document.getElementById("cover");

const title =
    document.getElementById("title");

const artist =
    document.getElementById("artist");

const playButton =
    document.getElementById("play");

const previousButton =
    document.getElementById("prev");

const nextButton =
    document.getElementById("next");

const progress =
    document.getElementById("progress");

const progressFill =
    document.getElementById("progressFill");

const currentTime =
    document.getElementById("current");

const duration =
    document.getElementById("duration");

const lyrics =
    document.getElementById("lyrics");

const queueBox =
    document.getElementById("queue");

const status =
    document.getElementById("status");

function timeFormat(seconds){

    seconds =
        Math.floor(
            Number(seconds) || 0
        );

    const minutes =
        Math.floor(
            seconds / 60
        );

    const secs =
        seconds % 60;

    return (
        minutes +
        ":" +
        String(secs).padStart(
            2,
            "0"
        )
    );
}

function renderQueue(){

    queueBox.innerHTML = "";

    queue.forEach(
        (song,index) => {

            const item =
                document.createElement(
                    "div"
                );

            item.className =
                "queueItem";

            item.textContent =
                (
                    index + 1
                ) +
                ". " +
                (
                    song.title ||
                    "Unknown"
                ) +
                " — " +
                (
                    song.artists ||
                    "Unknown Artist"
                );

            item.onclick = () => {

                currentIndex =
                    index;

                loadSong(
                    queue[
                        currentIndex
                    ],
                    true
                );
            };

            queueBox.appendChild(
                item
            );
        }
    );
}

function loadLyrics(
    songTitle,
    songArtist
){

    lyrics.textContent =
        "Loading lyrics…";

    const url =
        location.pathname +
        "?action=lyrics" +
        "&title=" +
        encodeURIComponent(
            songTitle
        ) +
        "&artist=" +
        encodeURIComponent(
            songArtist
        );

    fetch(url)
        .then(
            response =>
                response.json()
        )
        .then(
            data => {

                if(
                    data.synced &&
                    data.synced.trim()
                ){
                    lyrics.textContent =
                        data.synced;
                    return;
                }

                if(
                    data.plain &&
                    data.plain.trim()
                ){
                    lyrics.textContent =
                        data.plain;
                    return;
                }

                lyrics.textContent =
                    "Lyrics not found";
            }
        )
        .catch(
            () => {
                lyrics.textContent =
                    "Lyrics unavailable";
            }
        );
}

function loadSong(
    song,
    autoPlay
){

    if(!song){
        return;
    }

    currentSong =
        song.download_url || "";

    currentTitle =
        song.title || "Unknown";

    currentArtist =
        song.artists ||
        "Unknown Artist";

    currentArtwork =
        song.artwork ||
        "";

    title.textContent =
        currentTitle;

    artist.textContent =
        currentArtist;

    if(currentArtwork){
        cover.src =
            currentArtwork;
    }else{
        cover.removeAttribute(
            "src"
        );
    }

    audio.pause();

    audio.src =
        currentSong;

    audio.load();

    status.textContent =
        "Ready";

    loadLyrics(
        currentTitle,
        currentArtist
    );

    renderQueue();

    if(autoPlay){
        startPlayback();
    }
}

function startPlayback(){

    const result =
        audio.play();

    if(result){

        result.then(
            () => {
                playButton.textContent =
                    "⏸";

                status.textContent =
                    "Playing";
            }
        ).catch(
            () => {

                playButton.textContent =
                    "▶";

                status.textContent =
                    "Tap Play to start";
            }
        );

    }else{
        playButton.textContent =
            "⏸";
    }
}

function pausePlayback(){

    audio.pause();

    playButton.textContent =
        "▶";

    status.textContent =
        "Paused";
}

playButton.onclick = () => {

    if(audio.paused){
        startPlayback();
    }else{
        pausePlayback();
    }

};

audio.addEventListener(
    "timeupdate",
    () => {

        const current =
            audio.currentTime || 0;

        const total =
            audio.duration || 0;

        currentTime.textContent =
            timeFormat(current);

        duration.textContent =
            timeFormat(total);

        if(total > 0){

            progressFill.style.width =
                (
                    current /
                    total *
                    100
                ) +
                "%";
        }
    }
);

audio.addEventListener(
    "loadedmetadata",
    () => {

        duration.textContent =
            timeFormat(
                audio.duration
            );
    }
);

audio.addEventListener(
    "playing",
    () => {

        playButton.textContent =
            "⏸";

        status.textContent =
            "Playing";
    }
);

audio.addEventListener(
    "pause",
    () => {

        if(
            !audio.ended
        ){
            playButton.textContent =
                "▶";
        }
    }
);

audio.addEventListener(
    "error",
    () => {

        status.textContent =
            "Audio could not be played";

        playButton.textContent =
            "▶";
    }
);

audio.addEventListener(
    "ended",
    () => {

        /*
         * AUTO NEXT
         */

        if(
            currentIndex + 1 <
            queue.length
        ){

            currentIndex++;

            loadSong(
                queue[
                    currentIndex
                ],
                true
            );

        }else{

            playButton.textContent =
                "▶";

            status.textContent =
                "Queue finished";
        }

    }
);

previousButton.onclick =
    () => {

        if(
            audio.currentTime > 5
        ){

            audio.currentTime =
                0;

            return;
        }

        if(
            currentIndex > 0
        ){

            currentIndex--;

            loadSong(
                queue[
                    currentIndex
                ],
                true
            );

        }else{

            audio.currentTime =
                0;
        }
    };

nextButton.onclick =
    () => {

        if(
            currentIndex + 1 <
            queue.length
        ){

            currentIndex++;

            loadSong(
                queue[
                    currentIndex
                ],
                true
            );

        }else{

            status.textContent =
                "No next song";
        }
    };

progress.onclick =
    event => {

        const rect =
            progress.getBoundingClientRect();

        const percentage =
            (
                event.clientX -
                rect.left
            ) /
            rect.width;

        if(
            audio.duration
        ){

            audio.currentTime =
                percentage *
                audio.duration;
        }
    };

document
    .getElementById(
        "lyricsButton"
    )
    .onclick = () => {

        lyrics.scrollIntoView({
            behavior:"smooth",
            block:"center"
        });

    };

document
    .getElementById(
        "queueButton"
    )
    .onclick = () => {

        queueBox.style.display =
            queueBox.style.display ===
            "block"
                ? "none"
                : "block";

    };

if(queue.length === 0){

    queue = [
        {
            title:
                currentTitle,

            artists:
                currentArtist,

            download_url:
                currentSong,

            artwork:
                currentArtwork
        }
    ];

}

let startingIndex = 0;

for(
    let i = 0;
    i < queue.length;
    i++
){

    if(
        queue[i].download_url ===
        currentSong
    ){

        startingIndex = i;
        break;
    }
}

currentIndex =
    startingIndex;

loadSong(
    queue[currentIndex],
    false
);

renderQueue();

</script>

</body>

</html>';
}


/* ============================================================
   MINI APP API
   ============================================================ */

function miniApi(): void
{
    $action =
        (string)(
            $_GET['action']
            ?? ''
        );

    if ($action === 'lyrics') {
        $title =
            trim(
                (string)(
                    $_GET['title']
                    ?? ''
                )
            );

        $artist =
            trim(
                (string)(
                    $_GET['artist']
                    ?? ''
                )
            );

        jsonReply(
            getLyrics(
                $title,
                $artist
            )
        );
    }

    jsonReply([
        'ok' => false,
        'error' => 'Unknown action'
    ]);
}


/* ============================================================
   HEALTH PAGE
   ============================================================ */

function healthPage(): void
{
    header(
        'Content-Type: text/plain; charset=UTF-8'
    );

    echo
        "MAYAMUSIC ONLINE\n" .
        "PHP: " .
        PHP_VERSION .
        "\n" .
        "Bot token: " .
        (
            configBotToken() !== ''
                ? 'configured'
                : 'missing'
        ) .
        "\n" .
        "WebApp: " .
        configWebAppUrl() .
        "\n" .
        "Time: " .
        date('c') .
        "\n";
}


/* ============================================================
   HTTP ROUTER
   ============================================================ */

function handleHttp(): bool
{
    if (
        isset($_GET['health'])
    ) {
        healthPage();
        return true;
    }

    if (
        isset($_GET['mini'])
    ) {
        miniApp();
        return true;
    }

    if (
        isset($_GET['action'])
    ) {
        miniApi();
        return true;
    }

    return false;
}


/* ============================================================
   TELEGRAM WEBHOOK UPDATE
   ============================================================ */

function processWebhook(): void
{
    $raw =
        file_get_contents(
            'php://input'
        );

    if (
        $raw === false ||
        trim($raw) === ''
    ) {
        return;
    }

    $update =
        json_decode(
            $raw,
            true
        );

    if (
        !is_array($update)
    ) {
        return;
    }

    /*
     * CALLBACK
     */

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

    /*
     * TELEGRAM STARS CHECK
     *
     * MAYAMUSIC uses UPI instead.
     */

    if (
        isset(
            $update['pre_checkout_query']
        )
    ) {
        $query =
            $update[
                'pre_checkout_query'
            ];

        tg(
            'answerPreCheckoutQuery',
            [
                'pre_checkout_query_id' =>
                    $query['id'],
                'ok' => 'false',
                'error_message' =>
                    'Stars payments are not used. Please use UPI Premium.'
            ]
        );

        return;
    }

    /*
     * MESSAGE
     */

    if (
        isset(
            $update['message']
        )
    ) {
        handleMessage(
            $update['message']
        );
    }
}


/* ============================================================
   BOOT
   ============================================================ */

cleanExpiredData();

if (
    handleHttp()
) {
    exit;
}

if (
    php_sapi_name() !== 'cli'
) {
    processWebhook();
}

?>
