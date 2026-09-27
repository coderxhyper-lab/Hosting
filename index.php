<?php
declare(strict_types=1);

/*
=========================================================
                    MAYAMUSIC BOT
=========================================================
Single-file Telegram Music Bot
File: index.php

IMPORTANT:
- Never put your real bot token in public/chat.
- Use Railway environment variable BOT_TOKEN.
=========================================================
*/

/* =========================================================
   CONFIGURATION
   ========================================================= */

const BOT_TOKEN = 'PASTE_YOUR_NEW_BOT_TOKEN_HERE';

const ADMIN_ID = 8897821078;

const BOT_NAME = 'MAYAMUSIC';
const BOT_USERNAME = 'MayaMusicDownload_BOT';

const SUPPORT_USERNAME = 'HyperxVicky';

const WEBAPP_URL =
    'https://hosting-production-aacd.up.railway.app/index.php';

const MUSIC_API =
    'https://music-search-api-frnb.vercel.app/search?song=';

const UPI_ID =
    'vickybanna8674@ybl';

const PREMIUM_PRICE = 49;
const PREMIUM_DAYS = 30;

const HTTP_TIMEOUT = 25;

const DATA_DIR = __DIR__ . '/data';


/* =========================================================
   TOKEN
   ========================================================= */

function botToken(): string
{
    $env = trim((string)(getenv('BOT_TOKEN') ?: ''));

    if ($env !== '') {
        return $env;
    }

    return trim(BOT_TOKEN);
}


/* =========================================================
   BASIC HELPERS
   ========================================================= */

function now(): int
{
    return time();
}

function esc(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function jsonFlags(): int
{
    return JSON_PRETTY_PRINT |
           JSON_UNESCAPED_UNICODE |
           JSON_UNESCAPED_SLASHES;
}

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0775, true);
    }
}

function dataFile(string $name): string
{
    ensureDataDir();

    return DATA_DIR . '/' . $name . '.json';
}

function readJson(string $name): array
{
    $file = dataFile($name);

    if (!is_file($file)) {
        return [];
    }

    $raw = @file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function writeJson(string $name, array $data): bool
{
    ensureDataDir();

    $file = dataFile($name);

    $tmp = $file . '.tmp';

    $encoded = json_encode(
        $data,
        jsonFlags()
    );

    if ($encoded === false) {
        return false;
    }

    if (@file_put_contents(
        $tmp,
        $encoded,
        LOCK_EX
    ) === false) {
        return false;
    }

    return @rename($tmp, $file);
}


/* =========================================================
   BOT API
   ========================================================= */

function telegramApi(
    string $method,
    array $params = []
): array {

    $token = botToken();

    if ($token === '') {
        return [
            'ok' => false,
            'error' => 'BOT_TOKEN is not configured.'
        ];
    }

    $url =
        'https://api.telegram.org/bot' .
        $token .
        '/' .
        $method;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_USERAGENT => 'MAYAMUSIC/1.0'
    ]);

    $response = curl_exec($ch);

    $error = curl_error($ch);

    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'error' => $error ?: 'Telegram API request failed.'
        ];
    }

    $data = json_decode(
        $response,
        true
    );

    if (!is_array($data)) {
        return [
            'ok' => false,
            'error' => 'Invalid Telegram response.'
        ];
    }

    return $data;
}


/* =========================================================
   TELEGRAM SEND HELPERS
   ========================================================= */

function sendMsg(
    int $chatId,
    string $text,
    ?array $replyMarkup = null
): array {

    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];

    if ($replyMarkup !== null) {
        $params['reply_markup'] =
            json_encode(
                $replyMarkup,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );
    }

    return telegramApi(
        'sendMessage',
        $params
    );
}

function editMsg(
    int $chatId,
    int $messageId,
    string $text,
    ?array $replyMarkup = null
): array {

    $params = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];

    if ($replyMarkup !== null) {
        $params['reply_markup'] =
            json_encode(
                $replyMarkup,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );
    }

    return telegramApi(
        'editMessageText',
        $params
    );
}

function answerCb(
    string $callbackId,
    string $text = '',
    bool $alert = false
): array {

    return telegramApi(
        'answerCallbackQuery',
        [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $alert
        ]
    );
}


/* =========================================================
   INLINE KEYBOARD
   ========================================================= */

function kb(array $rows): array
{
    return [
        'inline_keyboard' => $rows
    ];
}

function mainKeyboard(int $uid): array
{
    $rows = [
        [
            [
                'text' => '🔎 Search Music',
                'callback_data' => 'search'
            ],
            [
                'text' => '🎵 Mini Player',
                'callback_data' => 'player'
            ]
        ],
        [
            [
                'text' => '⭐ Premium',
                'callback_data' => 'premium'
            ],
            [
                'text' => '👤 Account',
                'callback_data' => 'account'
            ]
        ],
        [
            [
                'text' => '🎟 Redeem Key',
                'callback_data' => 'redeem'
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


/* =========================================================
   USERS
   ========================================================= */

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
            'search_count' => 0,
            'download_count' => 0
        ];

        writeJson(
            'users',
            $users
        );
    }

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

    writeJson(
        'users',
        $users
    );

    return $user;
}

function isAdmin(int $uid): bool
{
    return $uid === ADMIN_ID;
}

function premiumUntil(int $uid): int
{
    return (int)(
        userRecord($uid)['premium_until'] ?? 0
    );
}

function premiumActive(int $uid): bool
{
    return
        isAdmin($uid) ||
        premiumUntil($uid) > now();
}

function hasMusicAccess(
    int $uid,
    string $chatType = 'private'
): bool {

    if (isAdmin($uid)) {
        return true;
    }

    if (
        $chatType === 'group' ||
        $chatType === 'supergroup' ||
        $chatType === 'channel'
    ) {
        return true;
    }

    return premiumUntil($uid) > now();
}

function accessMessage(
    int $uid,
    string $chatType = 'private'
): bool {

    if (
        hasMusicAccess(
            $uid,
            $chatType
        )
    ) {
        return true;
    }

    sendMsg(
        $uid,
        "🔒 <b>Premium Required</b>\n\n" .
        "Private music/player access requires an active Premium plan.\n\n" .
        "⭐ Premium: <b>₹" .
        PREMIUM_PRICE .
        " / " .
        PREMIUM_DAYS .
        " days</b>",
        kb([
            [
                [
                    'text' => '⭐ Activate Premium',
                    'callback_data' => 'premium'
                ]
            ]
        ])
    );

    return false;
}

function fmtDate(int $timestamp): string
{
    if ($timestamp <= 0) {
        return 'Not active';
    }

    return date(
        'd M Y, h:i A',
        $timestamp
    );
}


/* =========================================================
   MUSIC SEARCH
   ========================================================= */

function searchMusic(string $query): array
{
    $query = trim($query);

    if ($query === '') {
        return [];
    }

    $url =
        MUSIC_API .
        rawurlencode($query);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT,
        CURLOPT_USERAGENT => 'MAYAMUSIC/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json'
        ]
    ]);

    $body = curl_exec($ch);

    curl_close($ch);

    if ($body === false) {
        return [];
    }

    $data = json_decode(
        $body,
        true
    );

    if (
        !is_array($data) ||
        empty($data['results']) ||
        !is_array($data['results'])
    ) {
        return [];
    }

    $results = [];

    foreach (
        $data['results']
        as $item
    ) {

        if (!is_array($item)) {
            continue;
        }

        $download =
            trim(
                (string)(
                    $item['download_url'] ?? ''
                )
            );

        if ($download === '') {
            continue;
        }

        $results[] = [
            'title' =>
                (string)(
                    $item['title'] ??
                    'Unknown Title'
                ),

            'artists' =>
                (string)(
                    $item['artists'] ??
                    'Unknown Artist'
                ),

            'album' =>
                (string)(
                    $item['album'] ??
                    ''
                ),

            'duration' =>
                (string)(
                    $item['duration'] ??
                    ''
                ),

            'download_url' =>
                $download
        ];
    }

    return $results;
}


/* =========================================================
   ARTWORK
   ========================================================= */

function artwork(
    string $title,
    string $artist
): string {

    $query =
        rawurlencode(
            trim(
                $title .
                ' ' .
                $artist
            )
        );

    $url =
        'https://itunes.apple.com/search' .
        '?term=' .
        $query .
        '&media=music' .
        '&limit=1';

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'MAYAMUSIC/1.0'
    ]);

    $body = curl_exec($ch);

    curl_close($ch);

    if ($body === false) {
        return '';
    }

    $data = json_decode(
        $body,
        true
    );

    if (
        !is_array($data) ||
        empty($data['results'][0])
    ) {
        return '';
    }

    $image =
        (string)(
            $data['results'][0]['artworkUrl100']
            ?? ''
        );

    if ($image === '') {
        return '';
    }

    return str_replace(
        '100x100',
        '600x600',
        $image
    );
}


/* =========================================================
   SEARCH RESULT STORAGE
   ========================================================= */

function saveSearchResult(
    int $uid,
    array $song
): string {

    $searches =
        readJson('searches');

    $id =
        bin2hex(
            random_bytes(12)
        );

    $song['id'] = $id;

    $song['user_id'] = $uid;

    $song['created_at'] = now();

    $searches[$id] = $song;

    /*
     * Keep local storage bounded.
     */
    if (count($searches) > 5000) {

        uasort(
            $searches,
            function ($a, $b) {
                return
                    (int)($a['created_at'] ?? 0)
                    <=>
                    (int)($b['created_at'] ?? 0);
            }
        );

        $searches =
            array_slice(
                $searches,
                -4000,
                null,
                true
            );
    }

    writeJson(
        'searches',
        $searches
    );

    return $id;
}

function getSearchResult(
    string $id
): ?array {

    $searches =
        readJson('searches');

    if (
        !isset($searches[$id]) ||
        !is_array($searches[$id])
    ) {
        return null;
    }

    return $searches[$id];
}


/* =========================================================
   SONG RESULT KEYBOARD
   ========================================================= */

function songKeyboard(
    string $id
): array {

    return kb([
        [
            [
                'text' => '▶️ Open Mini Player',
                'url' =>
                    WEBAPP_URL .
                    '?mini=1&song=' .
                    rawurlencode($id)
            ]
        ],
        [
            [
                'text' => '⬇️ Download',
                'callback_data' =>
                    'download:' . $id
            ]
        ]
    ]);
}


/* =========================================================
   SEND SONG RESULT
   ========================================================= */

function sendSongResult(
    int $uid,
    array $song
): void {

    $id =
        saveSearchResult(
            $uid,
            $song
        );

    $title =
        esc(
            (string)(
                $song['title'] ??
                'Unknown Title'
            )
        );

    $artists =
        esc(
            (string)(
                $song['artists'] ??
                'Unknown Artist'
            )
        );

    $album =
        esc(
            (string)(
                $song['album'] ??
                ''
            )
        );

    $duration =
        esc(
            (string)(
                $song['duration'] ??
                ''
            )
        );

    $text =
        "🎵 <b>{$title}</b>\n\n" .
        "🎤 Artist: <b>{$artists}</b>\n" .
        "💿 Album: <b>{$album}</b>\n" .
        "⏱ Duration: <b>{$duration}</b>\n\n" .
        "Choose an action:";

    $image =
        artwork(
            (string)(
                $song['title'] ?? ''
            ),
            (string)(
                $song['artists'] ?? ''
            )
        );

    if ($image !== '') {

        telegramApi(
            'sendPhoto',
            [
                'chat_id' => $uid,
                'photo' => $image,
                'caption' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' =>
                    json_encode(
                        songKeyboard($id),
                        JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                    )
            ]
        );

    } else {

        sendMsg(
            $uid,
            $text,
            songKeyboard($id)
        );
    }
}


/* =========================================================
   PREMIUM
   ========================================================= */

function premiumText(): string
{
    return
        "⭐ <b>MAYAMUSIC PREMIUM</b>\n\n" .
        "🎵 Private music access\n" .
        "▶️ Mini Player\n" .
        "⬇️ Full audio download\n" .
        "🎤 Lyrics\n" .
        "🔁 Queue / Auto Next\n\n" .
        "💰 Price: <b>₹" .
        PREMIUM_PRICE .
        "</b>\n" .
        "📅 Validity: <b>" .
        PREMIUM_DAYS .
        " days</b>\n\n" .
        "Pay using UPI and submit your UTR for admin verification.";
}

function sendPremium(int $uid): void
{
    if (isAdmin($uid)) {

        sendMsg(
            $uid,
            premiumText() .
            "\n\n👑 <b>Admin access is permanent.</b>"
        );

        return;
    }

    $paymentId =
        'PAY-' .
        strtoupper(
            substr(
                bin2hex(
                    random_bytes(6)
                ),
                0,
                10
            )
        );

    $payments =
        readJson('payments');

    $payments[$paymentId] = [
        'id' => $paymentId,
        'user_id' => $uid,
        'amount' => PREMIUM_PRICE,
        'days' => PREMIUM_DAYS,
        'status' => 'pending',
        'created_at' => now(),
        'utr' => ''
    ];

    writeJson(
        'payments',
        $payments
    );

    $upi =
        'upi://pay?' .
        http_build_query([
            'pa' => UPI_ID,
            'pn' => BOT_NAME,
            'am' => number_format(
                PREMIUM_PRICE,
                2,
                '.',
                ''
            ),
            'cu' => 'INR',
            'tn' =>
                BOT_NAME .
                ' ' .
                $paymentId
        ]);

    sendMsg(
        $uid,
        premiumText() .
        "\n\n" .
        "🆔 Payment ID: <code>" .
        esc($paymentId) .
        "</code>\n\n" .
        "After payment send:\n" .
        "<code>/utr YOUR_UTR</code>",
        kb([
            [
                [
                    'text' => '💳 Pay ₹49',
                    'url' => $upi
                ]
            ],
            [
                [
                    'text' => '🔄 Account',
                    'callback_data' => 'account'
                ]
            ]
        ])
    );
}


/* =========================================================
   ACCOUNT
   ========================================================= */

function accountText(int $uid): string
{
    $u =
        userRecord($uid);

    $premium =
        premiumActive($uid);

    $expiry =
        premiumUntil($uid);

    return
        "👤 <b>MAYAMUSIC ACCOUNT</b>\n\n" .
        "🆔 Telegram ID: <code>" .
        $uid .
        "</code>\n\n" .
        "⭐ Premium: <b>" .
        (
            $premium
                ? 'ACTIVE'
                : 'INACTIVE'
        ) .
        "</b>\n" .
        "📅 Expiry: <b>" .
        fmtDate($expiry) .
        "</b>\n\n" .
        "🔎 Searches: " .
        (int)(
            $u['search_count'] ?? 0
        ) .
        "\n" .
        "⬇️ Downloads: " .
        (int)(
            $u['download_count'] ?? 0
        );
}


/* =========================================================
   REDEEM KEY
   ========================================================= */

function randomKey(): string
{
    $parts = [];

    for ($i = 0; $i < 3; $i++) {

        $parts[] =
            strtoupper(
                substr(
                    bin2hex(
                        random_bytes(4)
                    ),
                    0,
                    6
                )
            );
    }

    return
        'MAYA-' .
        implode(
            '-',
            $parts
        );
}

function uniqueRedeemKey(): string
{
    $keys =
        readJson('keys');

    do {

        $key =
            randomKey();

    } while (
        isset($keys[$key])
    );

    return $key;
}

function redeemKey(
    int $uid,
    string $key
): bool {

    $key =
        strtoupper(
            trim($key)
        );

    $keys =
        readJson('keys');

    if (
        !isset($keys[$key]) ||
        !is_array($keys[$key])
    ) {
        sendMsg(
            $uid,
            "❌ <b>Invalid redeem key.</b>"
        );

        return false;
    }

    $record =
        $keys[$key];

    if (
        !empty(
            $record['used']
        )
    ) {
        sendMsg(
            $uid,
            "❌ <b>This key has already been used.</b>"
        );

        return false;
    }

    $days =
        max(
            1,
            (int)(
                $record['days'] ?? 30
            )
        );

    $current =
        premiumUntil($uid);

    $start =
        max(
            now(),
            $current
        );

    $expiry =
        $start +
        ($days * 86400);

    updateUser(
        $uid,
        [
            'premium_until' => $expiry
        ]
    );

    $keys[$key]['used'] = true;
    $keys[$key]['used_by'] = $uid;
    $keys[$key]['used_at'] = now();

    writeJson(
        'keys',
        $keys
    );

    sendMsg(
        $uid,
        "✅ <b>Premium Activated</b>\n\n" .
        "📅 Days: <b>{$days}</b>\n" .
        "⏰ Expiry: <b>" .
        fmtDate($expiry) .
        "</b>"
    );

    return true;
}


/* =========================================================
   ADMIN PANEL
   ========================================================= */

function adminPanel(int $uid): void
{
    if (!isAdmin($uid)) {
        sendMsg(
            $uid,
            "⛔ Access denied."
        );
        return;
    }

    sendMsg(
        $uid,
        "🛠 <b>MAYAMUSIC ADMIN PANEL</b>\n\n" .
        "Select an administration function:",
        kb([
            [
                [
                    'text' => '📊 Statistics',
                    'callback_data' => 'admin_stats'
                ],
                [
                    'text' => '👥 Users',
                    'callback_data' => 'admin_users'
                ]
            ],
            [
                [
                    'text' => '🎟 Generate Key',
                    'callback_data' => 'admin_key'
                ],
                [
                    'text' => '💳 Payments',
                    'callback_data' => 'admin_payments'
                ]
            ],
            [
                [
                    'text' => '🧪 API Test',
                    'callback_data' => 'admin_api'
                ],
                [
                    'text' => '🌐 Webhook',
                    'callback_data' => 'admin_webhook'
                ]
            ]
        ])
    );
}


/* =========================================================
   ADMIN STATS
   ========================================================= */

function adminStats(): string
{
    $users =
        readJson('users');

    $payments =
        readJson('payments');

    $keys =
        readJson('keys');

    $activePremium = 0;

    foreach ($users as $user) {

        if (
            is_array($user) &&
            (int)(
                $user['premium_until'] ?? 0
            ) > now()
        ) {
            $activePremium++;
        }
    }

    $pending = 0;

    foreach ($payments as $payment) {

        if (
            is_array($payment) &&
            ($payment['status'] ?? '') ===
            'pending'
        ) {
            $pending++;
        }
    }

    return
        "📊 <b>MAYAMUSIC STATISTICS</b>\n\n" .
        "👥 Users: <b>" .
        count($users) .
        "</b>\n" .
        "⭐ Active Premium: <b>" .
        $activePremium .
        "</b>\n" .
        "💳 Pending Payments: <b>" .
        $pending .
        "</b>\n" .
        "🎟 Keys: <b>" .
        count($keys) .
        "</b>";
}


/* =========================================================
   ADMIN GENERATE KEY
   ========================================================= */

function adminGenerateKey(
    int $days = PREMIUM_DAYS
): string {

    $keys =
        readJson('keys');

    $key =
        uniqueRedeemKey();

    $keys[$key] = [
        'key' => $key,
        'days' => max(1, $days),
        'used' => false,
        'created_at' => now()
    ];

    writeJson(
        'keys',
        $keys
    );

    return $key;
}


/* =========================================================
   DOWNLOAD
   ========================================================= */

function downloadSong(
    int $uid,
    string $id
): void {

    if (
        !hasMusicAccess(
            $uid,
            'private'
        )
    ) {
        accessMessage($uid);
        return;
    }

    $song =
        getSearchResult($id);

    if ($song === null) {

        sendMsg(
            $uid,
            "❌ Song session expired. Please search again."
        );

        return;
    }

    $source =
        trim(
            (string)(
                $song['download_url'] ?? ''
            )
        );

    if (
        $source === '' ||
        !preg_match(
            '~^https?://~i',
            $source
        )
    ) {

        sendMsg(
            $uid,
            "❌ Invalid download source."
        );

        return;
    }

    /*
     * IMPORTANT:
     * API source is MP4 container containing AAC audio.
     * Do NOT rename it to MP3.
     */

    $title =
        preg_replace(
            '/[^A-Za-z0-9 _-]+/',
            '',
            (string)(
                $song['title'] ??
                BOT_NAME
            )
        );

    if (!$title) {
        $title = BOT_NAME;
    }

    $filename =
        trim($title) .
        ' - MAYAMUSIC.mp4';

    header(
        'Content-Type: audio/mp4'
    );

    header(
        'Content-Disposition: attachment; filename="' .
        str_replace(
            '"',
            '',
            $filename
        ) .
        '"'
    );

    header(
        'X-Content-Type-Options: nosniff'
    );

    $ch =
        curl_init($source);

    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_USERAGENT => 'MAYAMUSIC/1.0',
        CURLOPT_BUFFERSIZE => 65536,

        CURLOPT_WRITEFUNCTION =>
            function (
                $ch,
                $data
            ) {

                echo $data;

                return strlen($data);
            }
    ]);

    curl_exec($ch);

    curl_close($ch);

    $u =
        userRecord($uid);

    updateUser(
        $uid,
        [
            'download_count' =>
                (int)(
                    $u['download_count'] ?? 0
                ) + 1
        ]
    );

    exit;
}


/* =========================================================
   SEARCH COMMAND
   ========================================================= */

function handleSearch(
    int $uid,
    string $query,
    string $chatType
): void {

    if (
        !accessMessage(
            $uid,
            $chatType
        )
    ) {
        return;
    }

    $query =
        trim($query);

    if ($query === '') {

        sendMsg(
            $uid,
            "🔎 <b>Search Music</b>\n\n" .
            "Use:\n" .
            "<code>/search song name</code>"
        );

        return;
    }

    sendMsg(
        $uid,
        "🔎 Searching <b>" .
        esc($query) .
        "</b>..."
    );

    $results =
        searchMusic($query);

    if (!$results) {

        sendMsg(
            $uid,
            "❌ No song found.\n\n" .
            "Try another song/artist name."
        );

        return;
    }

    $u =
        userRecord($uid);

    updateUser(
        $uid,
        [
            'search_count' =>
                (int)(
                    $u['search_count'] ?? 0
                ) + 1
        ]
    );

    /*
     * Send multiple results,
     * but protect against excessive API output.
     */

    $results =
        array_slice(
            $results,
            0,
            5
        );

    foreach ($results as $song) {

        sendSongResult(
            $uid,
            $song
        );
    }
}


/* =========================================================
   CALLBACK HANDLER
   ========================================================= */

function handleCallback(
    array $callback
): void {

    $callbackId =
        (string)(
            $callback['id'] ?? ''
        );

    $uid =
        (int)(
            $callback['from']['id'] ?? 0
        );

    $data =
        (string)(
            $callback['data'] ?? ''
        );

    $message =
        $callback['message'] ?? [];

    $chatId =
        (int)(
            $message['chat']['id'] ??
            $uid
        );

    $messageId =
        (int)(
            $message['message_id'] ??
            0
        );

    if ($uid > 0) {
        userRecord($uid);
    }

    if ($data === 'home') {

        answerCb(
            $callbackId
        );

        sendMsg(
            $uid,
            "🎵 <b>" .
            BOT_NAME .
            "</b>",
            mainKeyboard($uid)
        );

        return;
    }

    if ($data === 'account') {

        answerCb(
            $callbackId
        );

        sendMsg(
            $uid,
            accountText($uid)
        );

        return;
    }

    if ($data === 'premium') {

        answerCb(
            $callbackId
        );

        sendPremium($uid);

        return;
    }

    if ($data === 'redeem') {

        answerCb(
            $callbackId
        );

        sendMsg(
            $uid,
            "🎟 <b>Redeem Premium Key</b>\n\n" .
            "Send:\n" .
            "<code>/redeem MAYA-XXXXXX-XXXXXX-XXXXXX</code>"
        );

        return;
    }

    if ($data === 'search') {

        answerCb(
            $callbackId
        );

        sendMsg(
            $uid,
            "🔎 Send:\n\n" .
            "<code>/search song name</code>"
        );

        return;
    }

    if ($data === 'player') {

        answerCb(
            $callbackId
        );

        if (
            !hasMusicAccess(
                $uid,
                (string)(
                    $message['chat']['type'] ??
                    'private'
                )
            )
        ) {

            sendMsg(
                $uid,
                "🔒 Premium required."
            );

            return;
        }

        sendMsg(
            $uid,
            "▶️ <b>MAYAMUSIC Mini Player</b>\n\n" .
            "Search a song first and then open its Mini Player.",
            kb([
                [
                    [
                        'text' => '🔎 Search Music',
                        'callback_data' => 'search'
                    ]
                ]
            ])
        );

        return;
    }

    if (
        str_starts_with(
            $data,
            'download:'
        )
    ) {

        answerCb(
            $callbackId,
            'Preparing download...'
        );

        $id =
            substr(
                $data,
                strlen('download:')
            );

        downloadSong(
            $uid,
            $id
        );

        return;
    }

    if ($data === 'admin') {

        answerCb(
            $callbackId
        );

        if (
            !isAdmin($uid)
        ) {

            sendMsg(
                $uid,
                "⛔ Access denied."
            );

            return;
        }

        adminPanel($uid);

        return;
    }

    if ($data === 'admin_stats') {

        answerCb(
            $callbackId
        );

        if (!isAdmin($uid)) {
            return;
        }

        sendMsg(
            $uid,
            adminStats()
        );

        return;
    }

    if ($data === 'admin_key') {

        answerCb(
            $callbackId
        );

        if (!isAdmin($uid)) {
            return;
        }

        $key =
            adminGenerateKey(
                PREMIUM_DAYS
            );

        sendMsg(
            $uid,
            "🎟 <b>Premium Key Generated</b>\n\n" .
            "<code>" .
            esc($key) .
            "</code>\n\n" .
            "Validity: <b>" .
            PREMIUM_DAYS .
            " days</b>"
        );

        return;
    }

    if ($data === 'admin_api') {

        answerCb(
            $callbackId
        );

        if (!isAdmin($uid)) {
            return;
        }

        $test =
            searchMusic('chandni');

        sendMsg(
            $uid,
            $test
                ? "✅ <b>Music API OK</b>\n\n" .
                  "Results: <b>" .
                  count($test) .
                  "</b>"
                : "❌ <b>Music API failed</b>"
        );

        return;
    }

    if ($data === 'admin_users') {

        answerCb(
            $callbackId
        );

        if (!isAdmin($uid)) {
            return;
        }

        $users =
            readJson('users');

        sendMsg(
            $uid,
            "👥 <b>Users</b>\n\n" .
            "Total: <b>" .
            count($users) .
            "</b>"
        );

        return;
    }

    if ($data === 'admin_payments') {

        answerCb(
            $callbackId
        );

        if (!isAdmin($uid)) {
            return;
        }

        $payments =
            readJson('payments');

        $pending = 0;

        foreach ($payments as $payment) {

            if (
                ($payment['status'] ?? '') ===
                'pending'
            ) {
                $pending++;
            }
        }

        sendMsg(
            $uid,
            "💳 <b>Payments</b>\n\n" .
            "Pending: <b>" .
            $pending .
            "</b>"
        );

        return;
    }

    if ($data === 'admin_webhook') {

        answerCb(
            $callbackId
        );

        if (!isAdmin($uid)) {
            return;
        }

        $info =
            telegramApi(
                'getWebhookInfo'
            );

        $url =
            (string)(
                $info['result']['url'] ??
                ''
            );

        sendMsg(
            $uid,
            "🌐 <b>Webhook Status</b>\n\n" .
            "URL:\n<code>" .
            esc(
                $url ?: 'Not configured'
            ) .
            "</code>"
        );

        return;
    }

    answerCb(
        $callbackId
    );
}


/* =========================================================
   COMMAND HANDLER
   ========================================================= */

function handleMessage(
    array $message
): void {

    $chat =
        $message['chat'] ?? [];

    $uid =
        (int)(
            $message['from']['id'] ?? 0
        );

    $chatId =
        (int)(
            $chat['id'] ?? 0
        );

    $chatType =
        (string)(
            $chat['type'] ?? 'private'
        );

    $text =
        trim(
            (string)(
                $message['text'] ?? ''
            )
        );

    if ($uid <= 0 || $chatId === 0) {
        return;
    }

    userRecord($uid);

    if ($text === '') {
        return;
    }


    /* ================= /start ================= */

    if (
        preg_match(
            '~^/start(?:@\w+)?(?:\s+.*)?$~i',
            $text
        )
    ) {

        sendMsg(
            $chatId,
            "🎵 <b>Welcome to " .
            BOT_NAME .
            "</b>\n\n" .
            "Search songs, open Mini Player, " .
            "download audio and manage your Premium account.\n\n" .
            "Choose an option below:",
            mainKeyboard($uid)
        );

        return;
    }


    /* ================= /help ================= */

    if (
        preg_match(
            '~^/help(?:@\w+)?$~i',
            $text
        )
    ) {

        sendMsg(
            $chatId,
            "📚 <b>MAYAMUSIC Commands</b>\n\n" .
            "/start - Open bot\n" .
            "/search song - Search music\n" .
            "/premium - Premium plan\n" .
            "/account - Account\n" .
            "/redeem KEY - Redeem key\n" .
            "/utr UTR - Submit payment UTR\n\n" .
            "Admin:\n" .
            "/admin\n" .
            "/genkey DAYS\n" .
            "/give USER_ID DAYS\n" .
            "/revoke USER_ID\n" .
            "/stats\n" .
            "/broadcast MESSAGE"
        );

        return;
    }


    /* ================= /search ================= */

    if (
        preg_match(
            '~^/search(?:@\w+)?\s+(.+)$~is',
            $text,
            $match
        )
    ) {

        handleSearch(
            $uid,
            trim($match[1]),
            $chatType
        );

        return;
    }


    /* ================= /premium ================= */

    if (
        preg_match(
            '~^/premium(?:@\w+)?$~i',
            $text
        )
    ) {

        sendPremium($uid);

        return;
    }


    /* ================= /account ================= */

    if (
        preg_match(
            '~^/account(?:@\w+)?$~i',
            $text
        )
    ) {

        sendMsg(
            $chatId,
            accountText($uid)
        );

        return;
    }


    /* ================= /redeem ================= */

    if (
        preg_match(
            '~^/redeem(?:@\w+)?\s+(.+)$~is',
            $text,
            $match
        )
    ) {

        redeemKey(
            $uid,
            trim($match[1])
        );

        return;
    }


    /* ================= /utr ================= */

    if (
        preg_match(
            '~^/utr(?:@\w+)?\s+(.+)$~is',
            $text,
            $match
        )
    ) {

        $utr =
            trim($match[1]);

        $payments =
            readJson('payments');

        $found = false;

        foreach (
            $payments as $id => $payment
        ) {

            if (
                !is_array($payment)
            ) {
                continue;
            }

            if (
                (int)(
                    $payment['user_id'] ?? 0
                ) !== $uid
            ) {
                continue;
            }

            if (
                ($payment['status'] ?? '') !==
                'pending'
            ) {
                continue;
            }

            $payments[$id]['utr'] =
                $utr;

            $payments[$id]['utr_submitted_at'] =
                now();

            writeJson(
                'payments',
                $payments
            );

            $found = true;

            sendMsg(
                $uid,
                "✅ <b>UTR submitted.</b>\n\n" .
                "Payment ID: <code>" .
                esc($id) .
                "</code>\n\n" .
                "Admin verification pending."
            );

            sendMsg(
                ADMIN_ID,
                "💳 <b>New UTR Submitted</b>\n\n" .
                "User ID: <code>" .
                $uid .
                "</code>\n" .
                "Payment ID: <code>" .
                esc($id) .
                "</code>\n" .
                "UTR: <code>" .
                esc($utr) .
                "</code>",
                kb([
                    [
                        [
                            'text' => '✅ Approve',
                            'callback_data' =>
                                'approve:' . $id
                        ],
                        [
                            'text' => '❌ Decline',
                            'callback_data' =>
                                'decline:' . $id
                        ]
                    ]
                ])
            );

            break;
        }

        if (!$found) {

            sendMsg(
                $uid,
                "❌ No pending payment found."
            );
        }

        return;
    }


    /* ================= /admin ================= */

    if (
        preg_match(
            '~^/admin(?:@\w+)?$~i',
            $text
        )
    ) {

        if (
            isAdmin($uid)
        ) {
            adminPanel($uid);
        } else {
            sendMsg(
                $chatId,
                "⛔ Access denied."
            );
        }

        return;
    }


    /* ================= /genkey ================= */

    if (
        preg_match(
            '~^/genkey(?:@\w+)?(?:\s+(\d+))?$~i',
            $text,
            $match
        )
    ) {

        if (!isAdmin($uid)) {
            sendMsg(
                $chatId,
                "⛔ Access denied."
            );
            return;
        }

        $days =
            isset($match[1]) &&
            $match[1] !== ''
                ? max(
                    1,
                    (int)$match[1]
                )
                : PREMIUM_DAYS;

        $key =
            adminGenerateKey(
                $days
            );

        sendMsg(
            $chatId,
            "🎟 <b>New Premium Key</b>\n\n" .
            "<code>" .
            esc($key) .
            "</code>\n\n" .
            "Validity: <b>" .
            $days .
            " days</b>"
        );

        return;
    }


    /* ================= /give ================= */

    if (
        preg_match(
            '~^/give(?:@\w+)?\s+(\d+)\s+(\d+)$~i',
            $text,
            $match
        )
    ) {

        if (!isAdmin($uid)) {
            sendMsg(
                $chatId,
                "⛔ Access denied."
            );
            return;
        }

        $target =
            (int)$match[1];

        $days =
            max(
                1,
                (int)$match[2]
            );

        $current =
            premiumUntil($target);

        $start =
            max(
                now(),
                $current
            );

        $expiry =
            $start +
            ($days * 86400);

        updateUser(
            $target,
            [
                'premium_until' =>
                    $expiry
            ]
        );

        sendMsg(
            $chatId,
            "✅ Premium granted.\n\n" .
            "User: <code>" .
            $target .
            "</code>\n" .
            "Days: <b>" .
            $days .
            "</b>\n" .
            "Expiry: <b>" .
            fmtDate($expiry) .
            "</b>"
        );

        sendMsg(
            $target,
            "⭐ <b>Premium Activated</b>\n\n" .
            "Validity: <b>" .
            $days .
            " days</b>\n" .
            "Expiry: <b>" .
            fmtDate($expiry) .
            "</b>"
        );

        return;
    }


    /* ================= /revoke ================= */

    if (
        preg_match(
            '~^/revoke(?:@\w+)?\s+(\d+)$~i',
            $text,
            $match
        )
    ) {

        if (!isAdmin($uid)) {
            sendMsg(
                $chatId,
                "⛔ Access denied."
            );
            return;
        }

        $target =
            (int)$match[1];

        updateUser(
            $target,
            [
                'premium_until' => 0
            ]
        );

        sendMsg(
            $chatId,
            "✅ Premium revoked for <code>" .
            $target .
            "</code>."
        );

        return;
    }


    /* ================= /stats ================= */

    if (
        preg_match(
            '~^/stats(?:@\w+)?$~i',
            $text
        )
    ) {

        if (!isAdmin($uid)) {
            sendMsg(
                $chatId,
                "⛔ Access denied."
            );
            return;
        }

        sendMsg(
            $chatId,
            adminStats()
        );

        return;
    }


    /* ================= /broadcast ================= */

    if (
        preg_match(
            '~^/broadcast(?:@\w+)?\s+(.+)$~is',
            $text,
            $match
        )
    ) {

        if (!isAdmin($uid)) {
            sendMsg(
                $chatId,
                "⛔ Access denied."
            );
            return;
        }

        $broadcast =
            trim($match[1]);

        $users =
            readJson('users');

        $sent = 0;
        $failed = 0;

        foreach ($users as $user) {

            $target =
                (int)(
                    $user['id'] ?? 0
                );

            if ($target <= 0) {
                continue;
            }

            $result =
                sendMsg(
                    $target,
                    $broadcast
                );

            if (
                ($result['ok'] ?? false)
            ) {
                $sent++;
            } else {
                $failed++;
            }

            usleep(50000);
        }

        sendMsg(
            $chatId,
            "📢 <b>Broadcast completed</b>\n\n" .
            "✅ Sent: <b>{$sent}</b>\n" .
            "❌ Failed: <b>{$failed}</b>"
        );

        return;
    }
}


/* =========================================================
   PAYMENT CALLBACK
   ========================================================= */

function handlePaymentCallback(
    string $callbackId,
    int $adminId,
    string $action,
    string $paymentId
): void {

    if (
        !isAdmin($adminId)
    ) {

        answerCb(
            $callbackId,
            'Access denied.',
            true
        );

        return;
    }

    $payments =
        readJson('payments');

    if (
        !isset($payments[$paymentId])
    ) {

        answerCb(
            $callbackId,
            'Payment not found.',
            true
        );

        return;
    }

    $payment =
        $payments[$paymentId];

    if (
        !is_array($payment)
    ) {
        return;
    }

    if (
        ($payment['status'] ?? '') !==
        'pending'
    ) {

        answerCb(
            $callbackId,
            'Already processed.',
            true
        );

        return;
    }

    $userId =
        (int)(
            $payment['user_id'] ?? 0
        );

    if ($action === 'approve') {

        $current =
            premiumUntil($userId);

        $start =
            max(
                now(),
                $current
            );

        $days =
            max(
                1,
                (int)(
                    $payment['days'] ??
                    PREMIUM_DAYS
                )
            );

        $expiry =
            $start +
            ($days * 86400);

        updateUser(
            $userId,
            [
                'premium_until' =>
                    $expiry
            ]
        );

        $payments[$paymentId]['status'] =
            'approved';

        $payments[$paymentId]['approved_at'] =
            now();

        $payments[$paymentId]['approved_by'] =
            $adminId;

        writeJson(
            'payments',
            $payments
        );

        answerCb(
            $callbackId,
            'Payment approved.'
        );

        sendMsg(
            $adminId,
            "✅ Payment approved.\n\n" .
            "User: <code>" .
            $userId .
            "</code>\n" .
            "Expiry: <b>" .
            fmtDate($expiry) .
            "</b>"
        );

        sendMsg(
            $userId,
            "🎉 <b>Premium Activated</b>\n\n" .
            "Your payment has been approved.\n\n" .
            "📅 Expiry: <b>" .
            fmtDate($expiry) .
            "</b>"
        );

        return;
    }

    if ($action === 'decline') {

        $payments[$paymentId]['status'] =
            'declined';

        $payments[$paymentId]['declined_at'] =
            now();

        $payments[$paymentId]['declined_by'] =
            $adminId;

        writeJson(
            'payments',
            $payments
        );

        answerCb(
            $callbackId,
            'Payment declined.'
        );

        sendMsg(
            $adminId,
            "❌ Payment declined.\n\n" .
            "User: <code>" .
            $userId .
            "</code>"
        );

        sendMsg(
            $userId,
            "❌ <b>Payment declined.</b>\n\n" .
            "Please contact @" .
            esc(SUPPORT_USERNAME) .
            " if you believe this was an error."
        );
    }
}


/* =========================================================
   WEBAPP INIT DATA VALIDATION
   ========================================================= */

function validateWebAppInitData(
    string $initData
): array|false {

    $token =
        botToken();

    if (
        $token === '' ||
        trim($initData) === ''
    ) {
        return false;
    }

    parse_str(
        $initData,
        $data
    );

    $hash =
        (string)(
            $data['hash'] ?? ''
        );

    if ($hash === '') {
        return false;
    }

    unset(
        $data['hash']
    );

    ksort(
        $data,
        SORT_STRING
    );

    $pairs = [];

    foreach (
        $data as $key => $value
    ) {

        $pairs[] =
            $key .
            '=' .
            $value;
    }

    $checkString =
        implode(
            "\n",
            $pairs
        );

    $secret =
        hash_hmac(
            'sha256',
            'WebAppData',
            $token,
            true
        );

    $calculated =
        hash_hmac(
            'sha256',
            $checkString,
            $secret
        );

    if (
        !hash_equals(
            $calculated,
            $hash
        )
    ) {
        return false;
    }

    $authDate =
        (int)(
            $data['auth_date'] ?? 0
        );

    if (
        $authDate > 0 &&
        $authDate <
        now() - 86400
    ) {
        return false;
    }

    $user =
        json_decode(
            (string)(
                $data['user'] ??
                '{}'
            ),
            true
        );

    if (
        !is_array($user) ||
        (int)(
            $user['id'] ?? 0
        ) < 1
    ) {
        return false;
    }

    return [
        'id' =>
            (int)$user['id'],

        'user' =>
            $user,

        'auth_date' =>
            $authDate
    ];
}


/* =========================================================
   MINI APP API
   ========================================================= */

function lyrics(
    string $title,
    string $artist
): array {

    $title =
        trim($title);

    $artist =
        trim($artist);

    if ($title === '') {
        return [
            'ok' => false,
            'error' => 'Missing title'
        ];
    }

    $url =
        'https://lrclib.net/api/search?' .
        http_build_query([
            'track_name' => $title,
            'artist_name' => $artist
        ]);

    $ch =
        curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'MAYAMUSIC/1.0'
    ]);

    $body =
        curl_exec($ch);

    curl_close($ch);

    if ($body === false) {

        return [
            'ok' => false,
            'lyrics' => ''
        ];
    }

    $data =
        json_decode(
            $body,
            true
        );

    if (
        !is_array($data) ||
        empty($data[0])
    ) {

        return [
            'ok' => false,
            'lyrics' => ''
        ];
    }

    $item =
        $data[0];

    return [
        'ok' => true,
        'lyrics' =>
            (string)(
                $item['plainLyrics'] ??
                $item['syncedLyrics'] ??
                ''
            )
    ];
}


/* =========================================================
   MINI API ROUTER
   ========================================================= */

function apiMini(): void
{
    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    $action =
        (string)(
            $_GET['action'] ?? ''
        );

    if (
        $action === 'lyrics'
    ) {

        echo json_encode(
            lyrics(
                (string)(
                    $_GET['title'] ?? ''
                ),
                (string)(
                    $_GET['artist'] ?? ''
                )
            ),
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        return;
    }

    if (
        $action === 'song'
    ) {

        $id =
            (string)(
                $_GET['id'] ?? ''
            );

        $song =
            getSearchResult($id);

        if ($song === null) {

            echo json_encode([
                'ok' => false,
                'error' => 'Song not found'
            ]);

            return;
        }

        $song['artwork'] =
            artwork(
                (string)(
                    $song['title'] ?? ''
                ),
                (string)(
                    $song['artists'] ?? ''
                )
            );

        echo json_encode([
            'ok' => true,
            'song' => $song
        ], JSON_UNESCAPED_UNICODE |
           JSON_UNESCAPED_SLASHES);

        return;
    }

    echo json_encode([
        'ok' => false,
        'error' => 'Unknown action'
    ]);
}


/* =========================================================
   MINI PLAYER HTML
   ========================================================= */

function miniPlayerHtml(
    string $songId
): void {

    $safeId =
        htmlspecialchars(
            $songId,
            ENT_QUOTES,
            'UTF-8'
        );

    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta
    name="viewport"
    content="width=device-width,
             initial-scale=1,
             maximum-scale=1,
             user-scalable=no"
>
<title>MAYAMUSIC</title>

<style>

:root{
    --bg:#07070b;
    --panel:#111118;
    --panel2:#181821;
    --text:#ffffff;
    --muted:#a7a7b3;
    --accent:#ffffff;
    --border:rgba(255,255,255,.10);
}

*{
    box-sizing:border-box;
    -webkit-tap-highlight-color:transparent;
}

html,
body{
    margin:0;
    width:100%;
    min-height:100%;
    background:var(--bg);
    color:var(--text);
    font-family:
        Inter,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
}

body{
    overflow-x:hidden;
}

.app{
    min-height:100vh;
    padding:
        env(safe-area-inset-top)
        18px
        env(safe-area-inset-bottom)
        18px;
    display:flex;
    flex-direction:column;
}

.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:16px 0;
}

.brand{
    font-size:18px;
    font-weight:800;
    letter-spacing:-.4px;
}

.status{
    width:9px;
    height:9px;
    border-radius:50%;
    background:#fff;
    box-shadow:0 0 15px rgba(255,255,255,.7);
}

.cover-wrap{
    width:min(86vw,390px);
    aspect-ratio:1;
    margin:24px auto;
    border-radius:28px;
    overflow:hidden;
    background:var(--panel2);
    border:1px solid var(--border);
    box-shadow:
        0 30px 80px rgba(0,0,0,.45);
}

.cover{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}

.title{
    text-align:center;
    font-size:25px;
    line-height:1.15;
    font-weight:800;
    letter-spacing:-.7px;
    margin-top:10px;
}

.artist{
    text-align:center;
    color:var(--muted);
    font-size:15px;
    margin-top:8px;
}

.progress{
    margin-top:30px;
}

input[type=range]{
    width:100%;
    accent-color:white;
}

.times{
    display:flex;
    justify-content:space-between;
    color:var(--muted);
    font-size:12px;
    margin-top:5px;
}

.controls{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:28px;
    margin-top:22px;
}

button{
    border:0;
    color:white;
    background:transparent;
    cursor:pointer;
    font:inherit;
}

.small{
    width:46px;
    height:46px;
    border-radius:50%;
    background:var(--panel);
    border:1px solid var(--border);
}

.play{
    width:68px;
    height:68px;
    border-radius:50%;
    background:white;
    color:black;
    font-size:25px;
    display:flex;
    justify-content:center;
    align-items:center;
}

.bottom{
    margin-top:auto;
    padding:22px 0 10px;
    display:grid;
    grid-template-columns:1fr 1fr 1fr;
    gap:10px;
}

.action{
    padding:14px 10px;
    border-radius:16px;
    background:var(--panel);
    border:1px solid var(--border);
    text-align:center;
    color:var(--muted);
    font-size:13px;
}

</style>
</head>

<body>

<div class="app">

    <div class="top">
        <div class="brand">MAYAMUSIC</div>
        <div class="status"></div>
    </div>

    <div class="cover-wrap">
        <img
            id="cover"
            class="cover"
            alt="Artwork"
        >
    </div>

    <div
        id="title"
        class="title"
    >
        Loading...
    </div>

    <div
        id="artist"
        class="artist"
    >
        MAYAMUSIC
    </div>

    <div class="progress">

        <input
            id="seek"
            type="range"
            min="0"
            max="100"
            value="0"
        >

        <div class="times">

            <span id="current">
                0:00
            </span>

            <span id="duration">
                0:00
            </span>

        </div>

    </div>

    <div class="controls">

        <button
            class="small"
            id="prev"
        >
            ⏮
        </button>

        <button
            class="play"
            id="play"
        >
            ▶
        </button>

        <button
            class="small"
            id="next"
        >
            ⏭
        </button>

    </div>

    <div class="bottom">

        <button
            class="action"
            id="lyrics"
        >
            🎤 Lyrics
        </button>

        <button
            class="action"
            id="download"
        >
            ⬇ Download
        </button>

        <button
            class="action"
            id="queue"
        >
            ☰ Queue
        </button>

    </div>

</div>

<audio
    id="audio"
    preload="auto"
></audio>

<script>

const SONG_ID =
    <?= json_encode(
        $safeId,
        JSON_UNESCAPED_SLASHES
    ) ?>;

const API =
    location.pathname;

const audio =
    document.getElementById('audio');

const cover =
    document.getElementById('cover');

const title =
    document.getElementById('title');

const artist =
    document.getElementById('artist');

const play =
    document.getElementById('play');

const seek =
    document.getElementById('seek');

const current =
    document.getElementById('current');

const duration =
    document.getElementById('duration');

const download =
    document.getElementById('download');

let song = null;

function formatTime(seconds){

    if(!Number.isFinite(seconds)){
        return '0:00';
    }

    seconds =
        Math.max(
            0,
            Math.floor(seconds)
        );

    const m =
        Math.floor(
            seconds / 60
        );

    const s =
        seconds % 60;

    return (
        m +
        ':' +
        String(s).padStart(
            2,
            '0'
        )
    );
}

async function loadSong(){

    try{

        const response =
            await fetch(
                API +
                '?action=api_song&id=' +
                encodeURIComponent(
                    SONG_ID
                )
            );

        const data =
            await response.json();

        if(
            !data.ok ||
            !data.song
        ){
            throw new Error(
                'Song unavailable'
            );
        }

        song =
            data.song;

        title.textContent =
            song.title ||
            'Unknown Title';

        artist.textContent =
            song.artists ||
            'Unknown Artist';

        cover.src =
            song.artwork ||
            '';

        audio.src =
            song.download_url;

    }catch(error){

        title.textContent =
            'Song unavailable';

        artist.textContent =
            'Search again from MAYAMUSIC';
    }
}

play.addEventListener(
    'click',
    async () => {

        if(
            audio.paused
        ){

            try{

                await audio.play();

                play.textContent =
                    '❚❚';

            }catch(e){

                play.textContent =
                    '▶';
            }

        }else{

            audio.pause();

            play.textContent =
                '▶';
        }
    }
);

audio.addEventListener(
    'timeupdate',
    () => {

        if(
            !audio.duration
        ){
            return;
        }

        seek.value =
            (
                audio.currentTime /
                audio.duration
            ) * 100;

        current.textContent =
            formatTime(
                audio.currentTime
            );

        duration.textContent =
            formatTime(
                audio.duration
            );
    }
);

seek.addEventListener(
    'input',
    () => {

        if(
            !audio.duration
        ){
            return;
        }

        audio.currentTime =
            (
                Number(seek.value) /
                100
            ) *
            audio.duration;
    }
);

audio.addEventListener(
    'ended',
    () => {

        play.textContent =
            '▶';

        /*
         * Queue / auto-next engine can
         * consume the next track here.
         */
    }
);

download.addEventListener(
    'click',
    () => {

        if(
            !song ||
            !song.download_url
        ){
            return;
        }

        const a =
            document.createElement('a');

        a.href =
            song.download_url;

        a.download =
            (
                song.title ||
                'MAYAMUSIC'
            ) +
            ' - MAYAMUSIC.mp4';

        document.body.appendChild(a);

        a.click();

        a.remove();
    }
);

document
    .getElementById('lyrics')
    .addEventListener(
        'click',
        async () => {

            if(!song){
                return;
            }

            const response =
                await fetch(
                    API +
                    '?action=lyrics' +
                    '&title=' +
                    encodeURIComponent(
                        song.title || ''
                    ) +
                    '&artist=' +
                    encodeURIComponent(
                        song.artists || ''
                    )
                );

            const data =
                await response.json();

            if(
                data.ok &&
                data.lyrics
            ){

                alert(
                    data.lyrics
                );

            }else{

                alert(
                    'Lyrics not available.'
                );
            }
        }
    );

loadSong();

</script>

</body>
</html>
<?php
}


/* =========================================================
   WEB APP ROUTING
   ========================================================= */

if (
    isset($_GET['action'])
) {

    $action =
        (string)$_GET['action'];

    if (
        $action === 'api_song'
    ) {

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        $id =
            (string)(
                $_GET['id'] ?? ''
            );

        $song =
            getSearchResult($id);

        if ($song === null) {

            echo json_encode([
                'ok' => false,
                'error' => 'Song not found'
            ]);

            exit;
        }

        $song['artwork'] =
            artwork(
                (string)(
                    $song['title'] ?? ''
                ),
                (string)(
                    $song['artists'] ?? ''
                )
            );

        echo json_encode([
            'ok' => true,
            'song' => $song
        ], JSON_UNESCAPED_UNICODE |
           JSON_UNESCAPED_SLASHES);

        exit;
    }

    if (
        $action === 'lyrics'
    ) {

        apiMini();

        exit;
    }

    if (
        $action === 'download'
    ) {

        $uid =
            (int)(
                $_GET['user_id'] ?? 0
            );

        $id =
            (string)(
                $_GET['id'] ?? ''
            );

        if ($uid > 0) {

            downloadSong(
                $uid,
                $id
            );
        }

        exit;
    }
}


/* =========================================================
   MINI PLAYER
   ========================================================= */

if (
    isset($_GET['mini'])
) {

    $songId =
        (string)(
            $_GET['song'] ?? ''
        );

    if ($songId === '') {

        http_response_code(400);

        echo 'Missing song.';

        exit;
    }

    miniPlayerHtml(
        $songId
    );

    exit;
}


/* =========================================================
   TELEGRAM UPDATE
   ========================================================= */

$raw =
    file_get_contents(
        'php://input'
    );

if (
    $raw === false ||
    trim($raw) === ''
) {

    /*
     * Direct browser health response.
     */

    header(
        'Content-Type: text/plain; charset=UTF-8'
    );

    echo
        BOT_NAME .
        " is running.";

    exit;
}

$update =
    json_decode(
        $raw,
        true
    );

if (
    !is_array($update)
) {

    http_response_code(400);

    echo 'Invalid update.';

    exit;
}


/* =========================================================
   CALLBACK
   ========================================================= */

if (
    isset(
        $update['callback_query']
    )
) {

    $callback =
        $update['callback_query'];

    $data =
        (string)(
            $callback['data'] ?? ''
        );

    if (
        str_starts_with(
            $data,
            'approve:'
        )
    ) {

        handlePaymentCallback(
            (string)(
                $callback['id'] ?? ''
            ),
            (int)(
                $callback['from']['id'] ??
                0
            ),
            'approve',
            substr(
                $data,
                strlen('approve:')
            )
        );

    } elseif (
        str_starts_with(
            $data,
            'decline:'
        )
    ) {

        handlePaymentCallback(
            (string)(
                $callback['id'] ?? ''
            ),
            (int)(
                $callback['from']['id'] ??
                0
            ),
            'decline',
            substr(
                $data,
                strlen('decline:')
            )
        );

    } else {

        handleCallback(
            $callback
        );
    }

    echo 'OK';

    exit;
}


/* =========================================================
   MESSAGE
   ========================================================= */

if (
    isset(
        $update['message']
    )
) {

    handleMessage(
        $update['message']
    );

    echo 'OK';

    exit;
}


/* =========================================================
   FALLBACK
   ========================================================= */

echo 'OK';
