<?php
/**
 * ============================================================
 * MAYAMUSIC v2.0 - COMPLETE SINGLE FILE TELEGRAM MUSIC BOT
 * PHP 8.1+
 * ============================================================
 * FEATURES:
 *  ✅ 3-Step Captcha Verification (Mini App)
 *  ✅ Image Captcha (GD generated)
 *  ✅ Music Search (15 results + related playlist)
 *  ✅ Infinite Autoplay (never stops)
 *  ✅ Stream Proxy (hides real API domain)
 *  ✅ Mini App HTML5 Player (real app feel)
 *  ✅ MP3 Download (song name se file)
 *  ✅ Lyrics (LRCLIB)
 *  ✅ Premium ₹49/30 days + Trial 3 days
 *  ✅ UTR + Admin approve/decline
 *  ✅ Redeem Keys
 *  ✅ Multi-Admin support
 *  ✅ Rate Limiting
 *  ✅ Webhook Secret Verification
 *  ✅ Artwork Cache
 *  ✅ Favorites + History
 *  ✅ Broadcast + Full Stats
 * ============================================================
 */

declare(strict_types=1);

/* ============================================================
   CORE CONFIG (ALREADY CONFIGURED - KEEP AS IS)
   ============================================================ */

const BOT_TOKEN = '8817347840:AAFpsNeTkzHqjnlqkV_18AjMEgIX-FXHmQo';
const ADMIN_ID  = 8897821078;

const BOT_NAME     = 'MAYAMUSIC';
const BOT_USERNAME = 'MayaMusicDownload_BOT';
const SUPPORT_USERNAME = 'HyperxVicky';

const WEBAPP_URL =
    'https://hosting-production-aacd.up.railway.app/index.php';

const MUSIC_API =
    'https://music-search-api-frnb.vercel.app/search?song=';

const ITUNES_API = 'https://itunes.apple.com/search';
const LRCLIB_API = 'https://lrclib.net/api/search';

const MONTHLY_PRICE = 49;
const ACCESS_DAYS   = 30;
const TRIAL_DAYS    = 3;

const UPI_ID   = 'vickybanna8674@ybl';
const UPI_NAME = 'MAYAMUSIC';

const DATA_DIR = __DIR__ . '/data';

const HTTP_TIMEOUT         = 18;
const API_CONNECT_TIMEOUT  = 8;

const WEBHOOK_SECRET = 'MAYA_WEBHOOK_SECRET_CHANGE_ME_1234567890';

const RATE_SEARCH_PER_MIN  = 15;
const CAPTCHA_MAX_ATTEMPTS = 3;
const CAPTCHA_EXPIRY_SEC   = 300;


/* ============================================================
   ENV OVERRIDE
   ============================================================ */

function configBotToken(): string {
    $e = getenv('BOT_TOKEN');
    return ($e !== false && trim($e) !== '') ? trim($e) : trim(BOT_TOKEN);
}

function configWebAppUrl(): string {
    $e = getenv('WEBAPP_URL');
    return ($e !== false && trim($e) !== '') ? rtrim(trim($e), '/') : rtrim(WEBAPP_URL, '/');
}

function configAdminIds(): array {
    $e = getenv('ADMIN_IDS');
    if ($e !== false && trim($e) !== '') {
        return array_values(array_filter(array_map('intval', explode(',', $e))));
    }
    return [ADMIN_ID];
}

function configWebhookSecret(): string {
    $e = getenv('WEBHOOK_SECRET');
    return ($e !== false && trim($e) !== '') ? trim($e) : WEBHOOK_SECRET;
}

function dataPath(): string { return DATA_DIR; }

if (!is_dir(dataPath())) @mkdir(dataPath(), 0775, true);

$htaccess = dataPath() . '/.htaccess';
if (!is_file($htaccess)) {
    @file_put_contents($htaccess,
        "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
        "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n" .
        "Options -Indexes\n");
}


/* ============================================================
   JSON STORAGE
   ============================================================ */

function filePath(string $name): string { return dataPath() . '/' . $name . '.json'; }

function readJson(string $name, array $default = []): array {
    $path = filePath($name);
    if (!is_file($path)) return $default;
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return $default;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function writeJson(string $name, array $data): bool {
    $path = filePath($name);
    $tmp  = $path . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $path);
}


/* ============================================================
   HELPERS
   ============================================================ */

function now(): int { return time(); }

function esc(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmtDate(int $timestamp): string {
    return $timestamp <= 0 ? 'Not active' : date('d M Y, h:i A', $timestamp);
}

function jsonReply(array $data): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function truncate(string $text, int $len = 38): string {
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len - 1) . '…' : $text;
}


/* ============================================================
   RATE LIMITING
   ============================================================ */

function checkRateLimit(int $uid, string $action, int $max, int $window): bool {
    $file = readJson('ratelimit');
    $key  = $uid . ':' . $action;
    $now  = now();
    $bucket = array_values(array_filter($file[$key] ?? [], fn($t) => $t > $now - $window));
    if (count($bucket) >= $max) return false;
    $bucket[] = $now;
    $file[$key] = $bucket;
    if (count($file) > 5000) {
        foreach ($file as $k => $v) {
            $file[$k] = array_values(array_filter($v, fn($t) => $t > $now - 3600));
            if (empty($file[$k])) unset($file[$k]);
        }
    }
    writeJson('ratelimit', $file);
    return true;
}


/* ============================================================
   TELEGRAM API
   ============================================================ */

function tg(string $method, array $params = []): array {
    $token = configBotToken();
    if ($token === '') return ['ok' => false, 'description' => 'BOT_TOKEN missing'];
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $ch = curl_init($url);
    if ($ch === false) return ['ok' => false, 'description' => 'cURL init failed'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'MAYAMUSIC/2.0'
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'http_code' => $http, 'description' => $error ?: 'Telegram request failed'];
    $data = json_decode($body, true);
    if (!is_array($data)) return ['ok' => false, 'http_code' => $http, 'description' => 'Invalid JSON'];
    $data['http_code'] = $http;
    return $data;
}

function sendMsg(int|string $chatId, string $text, array $extra = []): array {
    return tg('sendMessage', array_merge([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ], $extra));
}

function answerCb(string $id, string $text = '', bool $alert = false): void {
    tg('answerCallbackQuery', [
        'callback_query_id' => $id,
        'text' => $text,
        'show_alert' => $alert ? 'true' : 'false'
    ]);
}

function kb(array $rows): string {
    return json_encode(['inline_keyboard' => $rows],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}


/* ============================================================
   USER SYSTEM
   ============================================================ */

function isAdmin(int $uid): bool {
    return in_array($uid, configAdminIds(), true);
}

function userRecord(int $uid): array {
    $users = readJson('users');
    $key = (string)$uid;
    if (!isset($users[$key])) {
        $users[$key] = [
            'id' => $uid, 'created_at' => now(), 'last_seen' => now(),
            'premium_until' => 0, 'username' => '', 'first_name' => '',
            'verified' => false, 'trial_used' => false, 'favorites' => [], 'history' => []
        ];
    }
    $users[$key]['last_seen'] = now();
    writeJson('users', $users);
    return $users[$key];
}

function updateUser(int $uid, array $patch): array {
    $users = readJson('users');
    $key = (string)$uid;
    $user = $users[$key] ?? ['id' => $uid, 'created_at' => now(), 'premium_until' => 0, 'verified' => false];
    $user = array_merge($user, $patch, ['last_seen' => now()]);
    $users[$key] = $user;
    writeJson('users', $users);
    return $user;
}

function premiumUntil(int $uid): int {
    return (int)(userRecord($uid)['premium_until'] ?? 0);
}

function premiumActive(int $uid): bool {
    return isAdmin($uid) || premiumUntil($uid) > now();
}

function privateAccess(int $uid): bool { return premiumActive($uid); }

function isVerified(int $uid): bool {
    if (isAdmin($uid)) return true;
    return !empty(userRecord($uid)['verified']);
}

function needsCaptcha(int $uid): bool { return !isVerified($uid); }

function addToHistory(int $uid, array $song): void {
    $users = readJson('users');
    $key = (string)$uid;
    if (!isset($users[$key])) return;
    $history = $users[$key]['history'] ?? [];
    $history = array_values(array_filter($history,
        fn($h) => ($h['title'] ?? '') !== ($song['title'] ?? '')));
    array_unshift($history, [
        'title' => $song['title'] ?? '',
        'artists' => $song['artists'] ?? '',
        'artwork' => $song['artwork'] ?? '',
        'download_url' => $song['download_url'] ?? '',
        'played_at' => now()
    ]);
    $history = array_slice($history, 0, 20);
    $users[$key]['history'] = $history;
    writeJson('users', $users);
}

function toggleFavorite(int $uid, array $song): bool {
    $users = readJson('users');
    $key = (string)$uid;
    if (!isset($users[$key])) return false;
    $favs = $users[$key]['favorites'] ?? [];
    $title = $song['title'] ?? '';
    $exists = false;
    $newFavs = [];
    foreach ($favs as $f) {
        if (($f['title'] ?? '') === $title) { $exists = true; continue; }
        $newFavs[] = $f;
    }
    if (!$exists) {
        array_unshift($newFavs, [
            'title' => $title,
            'artists' => $song['artists'] ?? '',
            'artwork' => $song['artwork'] ?? '',
            'download_url' => $song['download_url'] ?? '',
            'added_at' => now()
        ]);
        $newFavs = array_slice($newFavs, 0, 50);
    }
    $users[$key]['favorites'] = $newFavs;
    writeJson('users', $users);
    return !$exists;
}


/* ============================================================
   MAIN KEYBOARD
   ============================================================ */

function mainKeyboard(int $uid): string {
    $rows = [
        [['text' => '🎧  Search Music', 'callback_data' => 'search']],
        [
            ['text' => '🎵  Mini Player', 'callback_data' => 'player'],
            ['text' => '☰  Queue',       'callback_data' => 'queue']
        ],
        [
            ['text' => '💎  Premium',    'callback_data' => 'premium'],
            ['text' => '🎫  Redeem',     'callback_data' => 'redeem']
        ],
        [
            ['text' => '👤  Profile',    'callback_data' => 'account'],
            ['text' => '💬  Support',    'url' => 'https://t.me/' . ltrim(SUPPORT_USERNAME, '@')]
        ]
    ];
    if (isAdmin($uid)) {
        $rows[] = [['text' => '⚡  Admin Control', 'callback_data' => 'admin']];
    }
    return kb($rows);
}


/* ============================================================
   CAPTCHA SYSTEM
   ============================================================ */

function generateCaptchaCode(int $length = 4): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    return $code;
}

function createCaptchaSession(int $uid): array {
    $captchas = readJson('captchas');
    $sessionId = bin2hex(random_bytes(16));
    $code = generateCaptchaCode(4);
    foreach ($captchas as $k => $v) {
        if (($v['expires_at'] ?? 0) < now()) unset($captchas[$k]);
    }
    $captchas[$sessionId] = [
        'user_id' => $uid, 'code' => strtoupper($code), 'attempts' => 0,
        'created_at' => now(), 'expires_at' => now() + CAPTCHA_EXPIRY_SEC, 'verified' => false
    ];
    writeJson('captchas', $captchas);
    return ['session' => $sessionId];
}

function verifyCaptchaCode(string $session, string $input): array {
    $captchas = readJson('captchas');
    if (!isset($captchas[$session])) return ['ok' => false, 'error' => 'Session expired. Reopen app.'];
    $item = &$captchas[$session];
    if ($item['expires_at'] < now()) {
        unset($captchas[$session]);
        writeJson('captchas', $captchas);
        return ['ok' => false, 'error' => 'Session expired. Reopen app.'];
    }
    if ($item['verified']) return ['ok' => true, 'already' => true];
    if ($item['attempts'] >= CAPTCHA_MAX_ATTEMPTS) {
        unset($captchas[$session]);
        writeJson('captchas', $captchas);
        return ['ok' => false, 'error' => 'Too many attempts. Reopen app.'];
    }
    $item['attempts']++;
    if (strtoupper(trim($input)) === $item['code']) {
        $item['verified'] = true;
        $item['verified_at'] = now();
        writeJson('captchas', $captchas);
        updateUser($item['user_id'], ['verified' => true, 'verified_at' => now()]);
        sendMsg($item['user_id'],
            "✅ <b>Verification Complete!</b>\n\n🎵 Welcome to <b>MAYAMUSIC</b>\n\nAb aap music search kar sakte hain.",
            ['reply_markup' => mainKeyboard($item['user_id'])]
        );
        return ['ok' => true];
    }
    $remaining = CAPTCHA_MAX_ATTEMPTS - $item['attempts'];
    writeJson('captchas', $captchas);
    return ['ok' => false, 'error' => "Wrong code. $remaining attempt(s) left.", 'remaining' => $remaining];
}

function generateCaptchaImage(string $code): void {
    if (!extension_loaded('gd')) {
        http_response_code(500); header('Content-Type: text/plain'); exit('GD required');
    }
    $width = 320; $height = 140;
    $img = imagecreatetruecolor($width, $height);
    for ($y = 0; $y < $height; $y++) {
        $ratio = $y / $height;
        $color = imagecolorallocate($img, (int)(30 + $ratio * 20), (int)(15 + $ratio * 15), (int)(45 + $ratio * 40));
        imageline($img, 0, $y, $width, $y, $color);
    }
    for ($i = 0; $i < 800; $i++) {
        $c = imagecolorallocate($img, random_int(80, 200), random_int(60, 150), random_int(120, 240));
        imagesetpixel($img, random_int(0, $width - 1), random_int(0, $height - 1), $c);
    }
    for ($i = 0; $i < 5; $i++) {
        $c = imagecolorallocate($img, random_int(100, 200), random_int(80, 160), random_int(150, 240));
        imageline($img, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $c);
    }
    $font = 5;
    $charW = imagefontwidth($font);
    $charH = imagefontheight($font);
    $gap = 12;
    $totalW = strlen($code) * ($charW + $gap);
    $startX = (int)(($width - $totalW) / 2);
    $baseY = (int)(($height - $charH) / 2);
    for ($i = 0; $i < strlen($code); $i++) {
        $c = imagecolorallocate($img, random_int(180, 255), random_int(160, 240), random_int(200, 255));
        $yOffset = random_int(-6, 6);
        $x = $startX + $i * ($charW + $gap);
        $y = $baseY + $yOffset;
        $shadow = imagecolorallocate($img, 0, 0, 0);
        imagestring($img, $font, $x + 2, $y + 2, $code[$i], $shadow);
        imagestring($img, $font, $x, $y, $code[$i], $c);
    }
    $border = imagecolorallocate($img, 139, 92, 246);
    imagerectangle($img, 0, 0, $width - 1, $height - 1, $border);
    imagerectangle($img, 1, 1, $width - 2, $height - 2, $border);
    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    imagepng($img);
    imagedestroy($img);
    exit;
}


/* ============================================================
   MUSIC API
   ============================================================ */

function httpGetJson(string $url, int $timeout = HTTP_TIMEOUT): array {
    $ch = curl_init($url);
    if ($ch === false) return ['ok' => false, 'http_code' => 0, 'error' => 'cURL init failed', 'data' => null];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => API_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'MAYAMUSIC/2.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'http_code' => $httpCode, 'error' => $error ?: 'HTTP failed', 'data' => null];
    $data = json_decode($body, true);
    if (!is_array($data)) return ['ok' => false, 'http_code' => $httpCode, 'error' => 'Invalid JSON', 'data' => null];
    return ['ok' => true, 'http_code' => $httpCode, 'error' => '', 'data' => $data];
}

function searchMusic(string $query): array {
    $query = trim($query);
    if ($query === '') return [];
    $url = MUSIC_API . rawurlencode($query);
    $response = httpGetJson($url);
    if (!($response['ok'] ?? false) || !is_array($response['data'] ?? null)) return [];
    $data = $response['data'];
    if (empty($data['results']) || !is_array($data['results'])) return [];
    $results = [];
    foreach ($data['results'] as $item) {
        if (!is_array($item)) continue;
        $download = trim((string)($item['download_url'] ?? ''));
        if ($download === '') continue;
        $results[] = [
            'title' => trim((string)($item['title'] ?? 'Unknown Title')),
            'artists' => trim((string)($item['artists'] ?? 'Unknown Artist')),
            'album' => trim((string)($item['album'] ?? '')),
            'duration' => trim((string)($item['duration'] ?? '')),
            'download_url' => $download
        ];
    }
    return $results;
}

function artworkCached(string $title, string $artist): string {
    $cache = readJson('artwork_cache');
    $key = md5($title . '|' . $artist);
    if (isset($cache[$key])) return $cache[$key];
    $url = artworkFetch($title, $artist);
    $cache[$key] = $url;
    if (count($cache) > 2000) $cache = array_slice($cache, -1500, null, true);
    writeJson('artwork_cache', $cache);
    return $url;
}

function artworkFetch(string $title, string $artist): string {
    $term = rawurlencode(trim($title . ' ' . $artist));
    $url = ITUNES_API . '?term=' . $term . '&entity=song&limit=1';
    $response = httpGetJson($url, 10);
    if (!($response['ok'] ?? false)) return '';
    $data = $response['data'] ?? [];
    if (!is_array($data)) return '';
    $image = $data['results'][0]['artworkUrl100'] ?? '';
    if ($image === '') return '';
    return str_replace('100x100bb', '600x600bb', $image);
}

function getLyrics(string $title, string $artist): array {
    $url = LRCLIB_API . '?track_name=' . rawurlencode($title) . '&artist_name=' . rawurlencode($artist);
    $response = httpGetJson($url, 12);
    if (!($response['ok'] ?? false)) return ['synced' => '', 'plain' => ''];
    $data = $response['data'];
    if (!is_array($data)) return ['synced' => '', 'plain' => ''];
    foreach ($data as $item) {
        if (!is_array($item)) continue;
        $synced = trim((string)($item['syncedLyrics'] ?? ''));
        $plain = trim((string)($item['plainLyrics'] ?? ''));
        if ($synced !== '' || $plain !== '') return ['synced' => $synced, 'plain' => $plain];
    }
    return ['synced' => '', 'plain' => ''];
}

function getRelatedSongs(string $title, string $artist): array {
    $query = trim($artist !== '' ? $artist : $title);
    if ($query === '') return [];
    $results = searchMusic($query);
    $filtered = [];
    foreach ($results as $song) {
        if (($song['title'] ?? '') === $title) continue;
        $filtered[] = $song;
    }
    shuffle($filtered);
    $withArt = [];
    foreach (array_slice($filtered, 0, 8) as $song) {
        $song['artwork'] = artworkCached($song['title'], $song['artists']);
        $withArt[] = $song;
    }
    return $withArt;
}


/* ============================================================
   SESSIONS & TOKENS
   ============================================================ */

function createSearchSession(int $uid, array $results): string {
    $searches = readJson('searches');
    $id = bin2hex(random_bytes(12));
    $searches[$id] = [
        'user_id' => $uid, 'results' => $results,
        'created_at' => now(), 'expires_at' => now() + 7200
    ];
    writeJson('searches', $searches);
    return $id;
}

function createPlayerToken(array $song, array $queue, int $uid): string {
    $players = readJson('players');
    $token = bin2hex(random_bytes(18));
    $players[$token] = [
        'song' => $song, 'queue' => $queue, 'user_id' => $uid,
        'created_at' => now(), 'expires_at' => now() + 604800
    ];
    writeJson('players', $players);
    return $token;
}

function playerUrl(string $token): string {
    return configWebAppUrl() . '?mini=1&token=' . rawurlencode($token);
}

function createStreamToken(string $url, int $uid): string {
    $streams = readJson('streams');
    $token = bin2hex(random_bytes(16));
    $streams[$token] = [
        'url' => $url, 'user_id' => $uid,
        'created_at' => now(), 'expires_at' => now() + 21600
    ];
    if (count($streams) > 3000) {
        $now = now();
        foreach ($streams as $k => $v) {
            if (($v['expires_at'] ?? 0) < $now) unset($streams[$k]);
        }
    }
    writeJson('streams', $streams);
    return $token;
}

function streamProxy(): void {
    $token = (string)($_GET['stream'] ?? '');
    if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        http_response_code(404); exit;
    }
    $streams = readJson('streams');
    $item = $streams[$token] ?? null;
    if (!$item || ($item['expires_at'] ?? 0) < now()) {
        http_response_code(404); exit('Stream expired');
    }
    $url = (string)($item['url'] ?? '');
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400); exit;
    }
    $headers = ['Accept: audio/*, */*'];
    if (isset($_SERVER['HTTP_RANGE'])) $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_USERAGENT => 'MAYAMUSIC/2.0',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function($ch, $header) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                if (in_array($name, ['content-type','content-length','accept-ranges','content-range','cache-control'])) {
                    header(trim($header));
                }
            }
            return $len;
        },
        CURLOPT_WRITEFUNCTION => function($ch, $data) { echo $data; return strlen($data); }
    ]);
    curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode >= 400) http_response_code($httpCode);
    exit;
}

function cleanExpiredData(): void {
    $now = now();
    foreach (['players', 'searches', 'captchas', 'streams'] as $file) {
        $data = readJson($file);
        $changed = false;
        foreach ($data as $key => $item) {
            if (isset($item['expires_at']) && (int)$item['expires_at'] < $now) {
                unset($data[$key]);
                $changed = true;
            }
        }
        if ($changed) writeJson($file, $data);
    }
}


/* ============================================================
   DOWNLOAD — MP3 with song name
   ============================================================ */

function sendAudioFile(int $uid, array $song): void {
    $downloadUrl = (string)($song['download_url'] ?? '');
    $title = (string)($song['title'] ?? 'Unknown');
    $artist = (string)($song['artists'] ?? 'Unknown Artist');
    if ($downloadUrl === '') { sendMsg($uid, "❌ Download URL missing."); return; }

    $caption = "🎵 <b>" . esc($title) . "</b>\n🎤 " . esc($artist);
    if (!empty($song['album'])) $caption .= "\n💿 " . esc($song['album']);
    $caption .= "\n\n📥 Downloaded from <b>MAYAMUSIC</b>";

    $result = tg('sendAudio', [
        'chat_id' => $uid,
        'audio' => $downloadUrl,
        'caption' => $caption,
        'parse_mode' => 'HTML',
        'title' => $title,
        'performer' => $artist
    ]);

    if (!($result['ok'] ?? false)) {
        $result2 = tg('sendDocument', [
            'chat_id' => $uid, 'document' => $downloadUrl,
            'caption' => $caption, 'parse_mode' => 'HTML'
        ]);
        if (!($result2['ok'] ?? false)) {
            sendMsg($uid, "❌ Download failed. Try Mini Player.");
        }
    }
}


/* ============================================================
   SEARCH COMMAND
   ============================================================ */

function showSearch(int $uid, string $query): void {
    if (!privateAccess($uid)) {
        sendMsg($uid, "💎 <b>Premium Required</b>\n\nUse 💎 Premium to activate access.",
            ['reply_markup' => kb([[['text' => '💎  Get Premium', 'callback_data' => 'premium']]])]);
        return;
    }
    if (!checkRateLimit($uid, 'search', RATE_SEARCH_PER_MIN, 60)) {
        sendMsg($uid, "⚠️ Too many searches. Wait a minute.");
        return;
    }
    $query = trim($query);
    if ($query === '') {
        sendMsg($uid, "🎧 <b>Search Music</b>\n\nExample:\n<code>/search Tum Hi Ho</code>");
        return;
    }
    $results = searchMusic($query);
    if (!$results) { sendMsg($uid, "❌ No results found."); return; }

    $results = array_slice($results, 0, 15);
    foreach ($results as $i => $song) {
        $results[$i]['artwork'] = artworkCached($song['title'], $song['artists']);
    }
    $session = createSearchSession($uid, $results);

    $buttons = [];
    foreach ($results as $index => $song) {
        $label = truncate($song['title'], 42);
        $buttons[] = [['text' => '▶️  ' . $label, 'callback_data' => 'pick:' . $session . ':' . $index]];
    }
    sendMsg($uid,
        "🎧 <b>SEARCH RESULTS</b>\n\nQuery: <code>" . esc($query) . "</code>\n" .
        "Found: <b>" . count($results) . "</b> songs\n\n<i>Select a song:</i>",
        ['reply_markup' => kb($buttons)]
    );
}


/* ============================================================
   PREMIUM
   ============================================================ */

function premiumText(): string {
    return "💎 <b>MAYAMUSIC PREMIUM</b>\n\n<b>₹" . MONTHLY_PRICE . " / " . ACCESS_DAYS . " Days</b>\n\n" .
        "🎧 Unlimited music search\n🎨 Album artwork\n🎤 Synced lyrics\n" .
        "♾️ Infinite autoplay\n▶️ Mini Player\n☰ Queue management\n" .
        "💚 Favorites + History\n📥 MP3 download\n🎫 Redeem key support\n\n" .
        "<b>UPI ID:</b>\n<code>" . esc(UPI_ID) . "</code>\n\nPayment ke baad UTR submit karein.";
}

function createPayment(int $uid): string {
    $payments = readJson('payments');
    $id = 'PAY-' . date('ymdHis') . '-' . strtoupper(bin2hex(random_bytes(5)));
    $payments[$id] = [
        'id' => $id, 'user_id' => $uid, 'amount' => MONTHLY_PRICE,
        'status' => 'created', 'utr' => '', 'created_at' => now(),
        'utr_submitted_at' => 0, 'approved_at' => 0, 'expires_at' => 0
    ];
    writeJson('payments', $payments);
    return $id;
}

function sendPremium(int $uid): void {
    $paymentId = createPayment($uid);
    $upi = 'upi://pay?pa=' . rawurlencode(UPI_ID) .
        '&pn=' . rawurlencode(UPI_NAME) .
        '&am=' . number_format(MONTHLY_PRICE, 2, '.', '') .
        '&cu=INR&tn=' . rawurlencode(BOT_NAME . ' ' . $paymentId);
    $buttons = [
        [['text' => '💳  Pay ₹' . MONTHLY_PRICE, 'url' => $upi]],
        [['text' => '🧾  Submit UTR', 'callback_data' => 'utr:' . $paymentId]],
        [['text' => '👤  Account', 'callback_data' => 'account']]
    ];
    sendMsg($uid, premiumText() . "\n\n<b>Payment ID:</b>\n<code>" . esc($paymentId) . "</code>",
        ['reply_markup' => kb($buttons)]);
}


/* ============================================================
   ACCOUNT
   ============================================================ */

function accountText(int $uid): string {
    $user = userRecord($uid);
    if (isAdmin($uid)) {
        return "👑 <b>ADMIN ACCOUNT</b>\n\n<b>User ID:</b> <code>" . $uid . "</code>\n\n" .
            "<b>Status:</b> 👑 PERMANENT ACCESS\n\nPremium: <b>DISABLED</b>\nAdmin: <b>ACTIVE</b>";
    }
    $until = (int)($user['premium_until'] ?? 0);
    $active = $until > now();
    $status = $active ? '🟢 ACTIVE' : '🔴 INACTIVE';
    $valid = $active ? fmtDate($until) : 'Not active';
    $favCount = count($user['favorites'] ?? []);
    $histCount = count($user['history'] ?? []);
    return "👤 <b>ACCOUNT</b>\n\n<b>User ID:</b> <code>" . $uid . "</code>\n" .
        "<b>Status:</b> " . $status . "\n<b>Valid until:</b> " . $valid . "\n\n" .
        "💚 Favorites: <b>" . $favCount . "</b>\n🕒 History: <b>" . $histCount . "</b>";
}


/* ============================================================
   REDEEM & TRIAL
   ============================================================ */

function generateRedeemKey(): string {
    $keys = readJson('keys');
    do {
        $key = 'MAYA-' .
            strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)) . '-' .
            strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)) . '-' .
            strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    } while (isset($keys[$key]));
    return $key;
}

function createRedeemKey(int $days, int $adminId): string {
    $keys = readJson('keys');
    $key = generateRedeemKey();
    $keys[$key] = [
        'key' => $key, 'status' => 'unused', 'duration_days' => $days,
        'created_at' => now(), 'expires_at' => now() + ($days * 86400),
        'created_by' => $adminId, 'redeemed_by' => 0, 'redeemed_at' => 0
    ];
    writeJson('keys', $keys);
    return $key;
}

function redeemKey(int $uid, string $input): void {
    $key = strtoupper(trim($input));
    if ($key === '') {
        sendMsg($uid, "🎫 <b>Redeem Key</b>\n\nUse:\n<code>/redeem MAYA-XXXXXX-XXXXXX-XXXXXX</code>");
        return;
    }
    $keys = readJson('keys');
    if (!isset($keys[$key])) { sendMsg($uid, "❌ Invalid redeem key."); return; }
    $item = $keys[$key];
    if (($item['status'] ?? '') !== 'unused') { sendMsg($uid, "❌ This key has already been used."); return; }
    if ((int)($item['expires_at'] ?? 0) <= now()) { sendMsg($uid, "❌ This key has expired."); return; }

    $current = premiumUntil($uid);
    $until = max($current, (int)$item['expires_at']);
    updateUser($uid, ['premium_until' => $until]);
    $keys[$key]['status'] = 'used';
    $keys[$key]['redeemed_by'] = $uid;
    $keys[$key]['redeemed_at'] = now();
    writeJson('keys', $keys);

    sendMsg($uid,
        "✅ <b>Key Redeemed!</b>\n\n💎 Premium active until:\n<b>" . fmtDate($until) . "</b>",
        ['reply_markup' => kb([[
            ['text' => '🎧  Search', 'callback_data' => 'search'],
            ['text' => '👤  Account', 'callback_data' => 'account']
        ]])]);
}

function sendTrial(int $uid): void {
    $user = userRecord($uid);
    if (!empty($user['trial_used'])) { sendMsg($uid, "⚠️ Trial already used."); return; }
    if (premiumActive($uid)) { sendMsg($uid, "✅ You already have active premium."); return; }
    $until = now() + (TRIAL_DAYS * 86400);
    updateUser($uid, ['premium_until' => $until, 'trial_used' => true]);
    sendMsg($uid,
        "🎁 <b>FREE TRIAL ACTIVATED!</b>\n\nDuration: <b>" . TRIAL_DAYS . " days</b>\n" .
        "Valid until: <b>" . fmtDate($until) . "</b>\n\nEnjoy MAYAMUSIC! 🎵",
        ['reply_markup' => mainKeyboard($uid)]);
}


/* ============================================================
   UTR
   ============================================================ */

function submitUtr(int $uid, string $paymentId, string $utr): void {
    $payments = readJson('payments');
    if (!isset($payments[$paymentId])) { sendMsg($uid, "❌ Payment ID not found."); return; }
    if ((int)($payments[$paymentId]['user_id'] ?? 0) !== $uid) { sendMsg($uid, "❌ Access denied."); return; }
    $utr = trim($utr);
    if ($utr === '' || strlen($utr) > 120) { sendMsg($uid, "❌ Invalid UTR."); return; }

    $payments[$paymentId]['utr'] = $utr;
    $payments[$paymentId]['status'] = 'pending';
    $payments[$paymentId]['utr_submitted_at'] = now();
    writeJson('payments', $payments);

    sendMsg($uid, "🧾 <b>UTR Submitted</b>\n\nPayment ID:\n<code>" . esc($paymentId) . "</code>\n\n⏳ Admin approval pending.");

    foreach (configAdminIds() as $adminId) {
        sendMsg($adminId,
            "💳 <b>NEW PAYMENT</b>\n\nPayment: <code>" . esc($paymentId) . "</code>\n" .
            "User: <code>" . $uid . "</code>\nAmount: ₹" . MONTHLY_PRICE . "\n" .
            "UTR: <code>" . esc($utr) . "</code>",
            ['reply_markup' => kb([[
                ['text' => '✅  Approve', 'callback_data' => 'approve:' . $paymentId],
                ['text' => '❌  Decline', 'callback_data' => 'decline:' . $paymentId]
            ]])]);
    }
}

function handleUtrCommand(int $uid, string $args): void {
    $parts = preg_split('/\s+/', trim($args), 2);
    $paymentId = trim($parts[0] ?? '');
    $utr = trim($parts[1] ?? '');
    if ($paymentId === '' || $utr === '') {
        sendMsg($uid, "Usage:\n<code>/utr PAYMENT_ID UTR</code>");
        return;
    }
    submitUtr($uid, $paymentId, $utr);
}

function processPaymentDecision(int $adminId, string $paymentId, bool $approve): void {
    if (!isAdmin($adminId)) return;
    $payments = readJson('payments');
    if (!isset($payments[$paymentId])) { sendMsg($adminId, "❌ Payment not found."); return; }
    $payment = $payments[$paymentId];
    if (($payment['status'] ?? '') !== 'pending') { sendMsg($adminId, "⚠️ Already processed."); return; }
    $uid = (int)$payment['user_id'];

    if ($approve) {
        $base = max(now(), premiumUntil($uid));
        $until = $base + (ACCESS_DAYS * 86400);
        $payment['status'] = 'approved';
        $payment['approved_at'] = now();
        $payment['expires_at'] = $until;
        $payments[$paymentId] = $payment;
        writeJson('payments', $payments);
        updateUser($uid, ['premium_until' => $until]);
        sendMsg($uid, "✅ <b>Payment Approved!</b>\n\n💎 Premium activated.\n\nValid until:\n<b>" . fmtDate($until) . "</b>",
            ['reply_markup' => mainKeyboard($uid)]);
        sendMsg($adminId, "✅ Approved.\nUser: <code>" . $uid . "</code>\nUntil: <b>" . fmtDate($until) . "</b>");
    } else {
        $payment['status'] = 'declined';
        $payments[$paymentId] = $payment;
        writeJson('payments', $payments);
        sendMsg($uid, "❌ <b>Payment Declined</b>\n\nContact @" . SUPPORT_USERNAME);
        sendMsg($adminId, "❌ Declined: <code>" . esc($paymentId) . "</code>");
    }
}


/* ============================================================
   ADMIN
   ============================================================ */

function adminPanel(int $uid): void {
    if (!isAdmin($uid)) return;
    $users = readJson('users');
    $keys = readJson('keys');
    $payments = readJson('payments');
    $active = 0; $verified = 0;
    foreach ($users as $user) {
        if ((int)($user['premium_until'] ?? 0) > now()) $active++;
        if (!empty($user['verified'])) $verified++;
    }
    $pending = 0;
    foreach ($payments as $p) if (($p['status'] ?? '') === 'pending') $pending++;

    sendMsg($uid,
        "⚡ <b>MAYAMUSIC ADMIN</b>\n\n👥 Users: <b>" . count($users) . "</b>\n" .
        "✅ Verified: <b>" . $verified . "</b>\n💎 Active: <b>" . $active . "</b>\n" .
        "🎫 Keys: <b>" . count($keys) . "</b>\n💳 Pending: <b>" . $pending . "</b>\n\n" .
        "<b>Commands</b>\n<code>/genkey 30</code>\n<code>/give USER_ID 30</code>\n" .
        "<code>/revoke USER_ID</code>\n<code>/broadcast msg</code>\n" .
        "<code>/stats</code>\n<code>/apitest</code>\n<code>/webhookinfo</code>",
        ['reply_markup' => kb([
            [['text' => '🎫  Generate Key', 'callback_data' => 'akey']],
            [
                ['text' => '💳  Payments', 'callback_data' => 'apays'],
                ['text' => '🎫  Keys', 'callback_data' => 'akeys']
            ],
            [
                ['text' => '👥  Users', 'callback_data' => 'ausers'],
                ['text' => '📊  Stats', 'callback_data' => 'astats']
            ],
            [['text' => '🏠  Home', 'callback_data' => 'home']]
        ])]);
}

function handleAdminCommand(int $uid, string $text): bool {
    if (!isAdmin($uid)) return false;
    $parts = preg_split('/\s+/', trim($text));
    $cmd = strtolower($parts[0] ?? '');

    if ($cmd === '/admin') { adminPanel($uid); return true; }

    if ($cmd === '/genkey') {
        $days = (int)($parts[1] ?? 30);
        if ($days < 1) $days = 30;
        if ($days > 3650) $days = 3650;
        $key = createRedeemKey($days, $uid);
        sendMsg($uid, "🎫 <b>KEY GENERATED</b>\n\n<code>" . esc($key) . "</code>\n\nDuration: <b>" . $days . " days</b>");
        return true;
    }

    if ($cmd === '/give') {
        $target = (int)($parts[1] ?? 0);
        $days = (int)($parts[2] ?? 30);
        if ($target < 1 || $days < 1) { sendMsg($uid, "Usage:\n<code>/give USER_ID DAYS</code>"); return true; }
        $base = max(now(), premiumUntil($target));
        $until = $base + ($days * 86400);
        updateUser($target, ['premium_until' => $until]);
        sendMsg($uid, "✅ Premium granted.\n\nUser: <code>" . $target . "</code>\nDays: <b>" . $days . "</b>");
        sendMsg($target, "💎 <b>Premium Activated!</b>\n\nValid until:\n<b>" . fmtDate($until) . "</b>");
        return true;
    }

    if ($cmd === '/revoke') {
        $target = (int)($parts[1] ?? 0);
        if ($target < 1) { sendMsg($uid, "Usage:\n<code>/revoke USER_ID</code>"); return true; }
        if (isAdmin($target)) { sendMsg($uid, "👑 Admin cannot be revoked."); return true; }
        updateUser($target, ['premium_until' => 0]);
        sendMsg($uid, "✅ Revoked: <code>" . $target . "</code>");
        sendMsg($target, "⚠️ Your premium has been revoked.");
        return true;
    }

    if ($cmd === '/broadcast') {
        $message = trim(preg_replace('/^\S+\s*/', '', $text));
        if ($message === '') { sendMsg($uid, "Usage:\n<code>/broadcast Your message</code>"); return true; }
        $users = readJson('users');
        $sent = 0; $failed = 0;
        foreach ($users as $id => $user) {
            $r = sendMsg((int)$id, $message);
            if ($r['ok'] ?? false) $sent++; else $failed++;
            usleep(60000);
        }
        sendMsg($uid, "📢 <b>Broadcast complete</b>\n\nSent: <b>" . $sent . "</b>\nFailed: <b>" . $failed . "</b>");
        return true;
    }

    if ($cmd === '/stats') { adminStats($uid); return true; }
    if ($cmd === '/apitest') { apiTest($uid); return true; }
    if ($cmd === '/webhookinfo') { webhookInfo($uid); return true; }
    if ($cmd === '/setwebhook') { setWebhookCommand($uid); return true; }
    if ($cmd === '/delwebhook') { deleteWebhookCommand($uid); return true; }
    return false;
}

function adminStats(int $uid): void {
    if (!isAdmin($uid)) return;
    $users = readJson('users'); $payments = readJson('payments'); $keys = readJson('keys');
    $approved = 0; $revenue = 0; $pending = 0;
    foreach ($payments as $p) {
        $s = $p['status'] ?? '';
        if ($s === 'approved') { $approved++; $revenue += (int)($p['amount'] ?? 0); }
        if ($s === 'pending') $pending++;
    }
    sendMsg($uid, "📊 <b>STATISTICS</b>\n\n👥 Users: <b>" . count($users) . "</b>\n" .
        "🎫 Keys: <b>" . count($keys) . "</b>\n💳 Approved: <b>" . $approved . "</b>\n" .
        "⏳ Pending: <b>" . $pending . "</b>\n💰 Revenue: <b>₹" . $revenue . "</b>");
}

function adminUsers(int $uid): void {
    if (!isAdmin($uid)) return;
    $users = readJson('users');
    $text = "👥 <b>USERS</b>\n\n"; $count = 0;
    foreach (array_reverse($users, true) as $id => $user) {
        $until = (int)($user['premium_until'] ?? 0);
        $status = $until > now() ? '🟢' : '🔴';
        $verified = !empty($user['verified']) ? '✅' : '⏳';
        $text .= "<code>" . (int)$id . "</code> " . $status . " " . $verified . " " .
            esc((string)($user['first_name'] ?? '')) . "\n";
        $count++;
        if ($count >= 30) break;
    }
    sendMsg($uid, $text, ['reply_markup' => kb([[['text' => '⬅️  Admin', 'callback_data' => 'admin']]])]);
}

function adminKeys(int $uid): void {
    if (!isAdmin($uid)) return;
    $keys = readJson('keys');
    $text = "🎫 <b>REDEEM KEYS</b>\n\n";
    if (!$keys) $text .= "No keys generated.";
    else {
        $count = 0;
        foreach (array_reverse($keys, true) as $key => $item) {
            $text .= "<code>" . esc($key) . "</code>\nStatus: <b>" .
                esc((string)($item['status'] ?? '')) . "</b>\nExpires: " .
                fmtDate((int)($item['expires_at'] ?? 0)) . "\n\n";
            $count++;
            if ($count >= 10) break;
        }
    }
    sendMsg($uid, $text, ['reply_markup' => kb([
        [['text' => '🎫  Generate 30D', 'callback_data' => 'akey']],
        [['text' => '⬅️  Admin', 'callback_data' => 'admin']]
    ])]);
}

function adminPayments(int $uid): void {
    if (!isAdmin($uid)) return;
    $payments = readJson('payments');
    $text = "💳 <b>PENDING PAYMENTS</b>\n\n"; $buttons = []; $count = 0;
    foreach (array_reverse($payments, true) as $id => $payment) {
        if (($payment['status'] ?? '') !== 'pending') continue;
        $text .= "<code>" . esc($id) . "</code>\nUser: <code>" . (int)$payment['user_id'] .
            "</code>\nAmount: ₹" . (int)$payment['amount'] . "\nUTR: <code>" .
            esc((string)($payment['utr'] ?? '')) . "</code>\n\n";
        $buttons[] = [
            ['text' => '✅  Approve', 'callback_data' => 'approve:' . $id],
            ['text' => '❌  Decline', 'callback_data' => 'decline:' . $id]
        ];
        $count++;
        if ($count >= 10) break;
    }
    if ($count === 0) $text .= "No pending payments.";
    $buttons[] = [['text' => '⬅️  Admin', 'callback_data' => 'admin']];
    sendMsg($uid, $text, ['reply_markup' => kb($buttons)]);
}

function apiTest(int $uid): void {
    if (!isAdmin($uid)) return;
    $response = httpGetJson(MUSIC_API . rawurlencode('Tum Hi Ho'), 20);
    if (!($response['ok'] ?? false)) {
        sendMsg($uid, "❌ <b>API TEST FAILED</b>\n\nHTTP: <code>" . (int)($response['http_code'] ?? 0) .
            "</code>\nError: <code>" . esc((string)($response['error'] ?? 'Unknown')) . "</code>");
        return;
    }
    $data = $response['data'] ?? [];
    $count = is_array($data['results'] ?? null) ? count($data['results']) : 0;
    $first = $data['results'][0] ?? null;
    if (is_array($first)) {
        sendMsg($uid, "✅ <b>API WORKING</b>\n\nHTTP: <b>" . (int)$response['http_code'] .
            "</b>\nResults: <b>" . $count . "</b>\n\nTitle: <b>" . esc((string)($first['title'] ?? '')) .
            "</b>\nArtist: <b>" . esc((string)($first['artists'] ?? '')) . "</b>\nDownload: <b>" .
            (!empty($first['download_url']) ? 'YES' : 'NO') . "</b>");
    } else sendMsg($uid, "⚠️ No valid results.");
}

function webhookInfo(int $uid): void {
    if (!isAdmin($uid)) return;
    $result = tg('getWebhookInfo');
    if (!($result['ok'] ?? false)) { sendMsg($uid, "❌ Check failed."); return; }
    $data = $result['result'] ?? [];
    sendMsg($uid, "🔗 <b>WEBHOOK INFO</b>\n\nURL:\n<code>" .
        esc((string)($data['url'] ?? 'NOT SET')) . "</code>\n\nPending: <b>" .
        (int)($data['pending_update_count'] ?? 0) . "</b>\nLast error:\n<code>" .
        esc((string)($data['last_error_message'] ?? 'None')) . "</code>");
}

function setWebhookCommand(int $uid): void {
    if (!isAdmin($uid)) return;
    $url = configWebAppUrl();
    if ($url === '' || !str_starts_with($url, 'https://')) { sendMsg($uid, "❌ WEBAPP_URL must be HTTPS."); return; }
    $result = tg('setWebhook', [
        'url' => $url,
        'secret_token' => configWebhookSecret(),
        'allowed_updates' => json_encode(['message', 'callback_query'])
    ]);
    sendMsg($uid, ($result['ok'] ?? false)
        ? "✅ <b>Webhook set</b>\n\n<code>" . esc($url) . "</code>\n\nSecret: <b>ACTIVE</b>"
        : "❌ Failed: <code>" . esc((string)($result['description'] ?? 'Unknown')) . "</code>");
}

function deleteWebhookCommand(int $uid): void {
    if (!isAdmin($uid)) return;
    $result = tg('deleteWebhook');
    sendMsg($uid, ($result['ok'] ?? false) ? "✅ Webhook deleted." : "❌ Failed.");
}


/* ============================================================
   HELP
   ============================================================ */

function sendHelp(int $uid): void {
    sendMsg($uid,
        "🎵 <b>MAYAMUSIC COMMANDS</b>\n\n" .
        "🎧 <b>Music</b>\n<code>/search song name</code>\n<code>/play song name</code>\n" .
        "<code>/queue</code>\n<code>/history</code>\n<code>/favorites</code>\n\n" .
        "👤 <b>Account</b>\n<code>/account</code>\n<code>/premium</code>\n" .
        "<code>/trial</code> — " . TRIAL_DAYS . " days free\n<code>/redeem KEY</code>\n\n" .
        "💳 <b>Payment</b>\n<code>/utr PAYMENT_ID UTR</code>\n\n" .
        "💬 <b>Support</b>\nContact @" . SUPPORT_USERNAME);
}


/* ============================================================
   PARSER
   ============================================================ */

function parseCommand(string $text): array {
    $parts = preg_split('/\s+/', trim($text), 2);
    return [strtolower($parts[0] ?? ''), trim($parts[1] ?? '')];
}


/* ============================================================
   CAPTCHA PROMPTS
   ============================================================ */

function sendStartWithCaptcha(int $uid): void {
    $captchaUrl = configWebAppUrl() . '?captcha_app=1&uid=' . $uid;
    sendMsg($uid,
        "🎭 <b>Welcome to MAYAMUSIC</b>\n\n" .
        "🔐 <b>Quick Verification Required</b>\n\n" .
        "Tap the button below to verify:\n\n" .
        "① Device check\n② Image captcha\n③ Access granted ✅\n\n" .
        "<i>Ye 15 seconds me complete hoga</i>",
        ['reply_markup' => kb([[['text' => '🎭  VERIFY NOW', 'web_app' => ['url' => $captchaUrl]]]])]);
}

function sendCaptchaPrompt(int $uid): void {
    $captchaUrl = configWebAppUrl() . '?captcha_app=1&uid=' . $uid;
    sendMsg($uid, "🔐 <b>Verification Required</b>\n\nPlease verify to use the bot.",
        ['reply_markup' => kb([[['text' => '🎭  VERIFY NOW', 'web_app' => ['url' => $captchaUrl]]]])]);
}


/* ============================================================
   MESSAGE HANDLER
   ============================================================ */

function handleMessage(array $message): void {
    $chat = $message['chat'] ?? [];
    $from = $message['from'] ?? [];
    $uid = (int)($from['id'] ?? 0);
    if ($uid <= 0) return;

    $chatId = $chat['id'] ?? $uid;
    $chatType = (string)($chat['type'] ?? 'private');
    $text = trim((string)($message['text'] ?? ''));

    if ($chatType !== 'private') {
        if (str_starts_with($text, '/start') || str_starts_with($text, '/help')) {
            sendMsg($chatId, "🎵 <b>" . BOT_NAME . "</b>\n\nI work only in private chat.\n👉 Open: @" . BOT_USERNAME);
        }
        return;
    }

    userRecord($uid);
    updateUser($uid, [
        'username' => (string)($from['username'] ?? ''),
        'first_name' => (string)($from['first_name'] ?? '')
    ]);

    if (handleAdminCommand($uid, $text)) return;

    [$command, $args] = parseCommand($text);

    // CAPTCHA GATE
    if (needsCaptcha($uid)) {
        if ($command === '/start') { sendStartWithCaptcha($uid); return; }
        sendCaptchaPrompt($uid);
        return;
    }

    if ($command === '/start') {
        sendMsg($uid, "🎵 <b>WELCOME TO MAYAMUSIC</b>\n\nSearch music, open Mini Player, get lyrics, and more.",
            ['reply_markup' => mainKeyboard($uid)]);
        return;
    }
    if ($command === '/help') { sendHelp($uid); return; }
    if ($command === '/search' || $command === '/play') { showSearch($uid, $args); return; }
    if ($command === '/premium') { sendPremium($uid); return; }
    if ($command === '/trial')   { sendTrial($uid);   return; }

    if ($command === '/account') {
        sendMsg($uid, accountText($uid), ['reply_markup' => kb([[
            ['text' => '💎  Premium', 'callback_data' => 'premium'],
            ['text' => '🏠  Home', 'callback_data' => 'home']
        ]])]);
        return;
    }
    if ($command === '/redeem') { redeemKey($uid, $args); return; }
    if ($command === '/utr')    { handleUtrCommand($uid, $args); return; }

    if ($command === '/history') {
        $user = userRecord($uid);
        $history = $user['history'] ?? [];
        if (!$history) { sendMsg($uid, "🕒 No history yet."); return; }
        $t = "🕒 <b>RECENT HISTORY</b>\n\n";
        foreach (array_slice($history, 0, 10) as $i => $song) {
            $t .= ($i + 1) . ". <b>" . esc($song['title']) . "</b>\n   " . esc($song['artists']) . "\n\n";
        }
        sendMsg($uid, $t, ['reply_markup' => kb([[['text' => '🎧  Search', 'callback_data' => 'search']]])]);
        return;
    }

    if ($command === '/favorites') {
        $user = userRecord($uid);
        $favs = $user['favorites'] ?? [];
        if (!$favs) { sendMsg($uid, "💚 No favorites yet."); return; }
        $t = "💚 <b>FAVORITES</b>\n\n";
        foreach (array_slice($favs, 0, 10) as $i => $song) {
            $t .= ($i + 1) . ". <b>" . esc($song['title']) . "</b>\n   " . esc($song['artists']) . "\n\n";
        }
        sendMsg($uid, $t, ['reply_markup' => kb([[['text' => '🎧  Search', 'callback_data' => 'search']]])]);
        return;
    }

    if ($command === '/queue') {
        sendMsg($uid, "☰ <b>Queue</b>\n\nOpen Mini Player to view queue.",
            ['reply_markup' => kb([[['text' => '🎵  Open Player', 'callback_data' => 'player']]])]);
        return;
    }

    if ($text !== '') showSearch($uid, $text);
}


/* ============================================================
   CALLBACK HANDLER
   ============================================================ */

function handleCallback(array $callback): void {
    $id = (string)($callback['id'] ?? '');
    $uid = (int)($callback['from']['id'] ?? 0);
    $data = (string)($callback['data'] ?? '');
    if ($uid <= 0) { answerCb($id); return; }

    userRecord($uid);

    if (needsCaptcha($uid) && !isAdmin($uid)) {
        answerCb($id, 'Verify first', true);
        sendCaptchaPrompt($uid);
        return;
    }

    if ($data === 'home')    { answerCb($id); sendMsg($uid, "🎵 <b>MAYAMUSIC</b>", ['reply_markup' => mainKeyboard($uid)]); return; }
    if ($data === 'account') { answerCb($id); sendMsg($uid, accountText($uid)); return; }
    if ($data === 'premium') { answerCb($id); sendPremium($uid); return; }
    if ($data === 'search')  { answerCb($id); sendMsg($uid, "🎧 Send:\n\n<code>/search song name</code>"); return; }
    if ($data === 'redeem')  { answerCb($id); sendMsg($uid, "🎫 Send:\n\n<code>/redeem MAYA-XXXXXX-XXXXXX-XXXXXX</code>"); return; }
    if ($data === 'queue')   {
        answerCb($id);
        sendMsg($uid, "☰ <b>Queue</b>\n\nOpen Mini Player.",
            ['reply_markup' => kb([[['text' => '🎵  Open Player', 'callback_data' => 'player']]])]);
        return;
    }
    if ($data === 'player')  { answerCb($id); sendMsg($uid, "🎵 <b>Mini Player</b>\n\nSearch a song first."); return; }
    if ($data === 'admin')   { answerCb($id); if (isAdmin($uid)) adminPanel($uid); return; }

    if ($data === 'akey') {
        answerCb($id);
        if (!isAdmin($uid)) return;
        $key = createRedeemKey(30, $uid);
        sendMsg($uid, "🎫 <b>NEW 30 DAY KEY</b>\n\n<code>" . esc($key) . "</code>");
        return;
    }
    if ($data === 'akeys')  { answerCb($id); adminKeys($uid);     return; }
    if ($data === 'apays')  { answerCb($id); adminPayments($uid); return; }
    if ($data === 'ausers') { answerCb($id); adminUsers($uid);    return; }
    if ($data === 'astats') { answerCb($id); adminStats($uid);    return; }

    if (str_starts_with($data, 'pick:')) { handlePickSong($uid, $id, $data); return; }

    if (str_starts_with($data, 'download:')) {
        answerCb($id, 'Downloading...');
        $session = substr($data, 9);
        $searches = readJson('searches');
        $item = $searches[$session] ?? null;
        if (!is_array($item) || (int)($item['user_id'] ?? 0) !== $uid) {
            sendMsg($uid, "❌ Session expired.");
            return;
        }
        sendMsg($uid, "⏳ Preparing your MP3 file...");
        return;
    }

    if (str_starts_with($data, 'utr:')) {
        answerCb($id);
        $paymentId = substr($data, 4);
        sendMsg($uid, "🧾 <b>SUBMIT UTR</b>\n\nPayment ID:\n<code>" . esc($paymentId) .
            "</code>\n\nSend:\n<code>/utr " . esc($paymentId) . " YOUR_UTR</code>");
        return;
    }

    if (str_starts_with($data, 'approve:')) {
        if (!isAdmin($uid)) { answerCb($id, 'Access denied', true); return; }
        answerCb($id, 'Approved');
        processPaymentDecision($uid, substr($data, 8), true);
        return;
    }

    if (str_starts_with($data, 'decline:')) {
        if (!isAdmin($uid)) { answerCb($id, 'Access denied', true); return; }
        answerCb($id, 'Declined');
        processPaymentDecision($uid, substr($data, 8), false);
        return;
    }

    if (str_starts_with($data, 'dl:')) {
        $parts = explode(':', $data, 3);
        $session = $parts[1] ?? '';
        $index = (int)($parts[2] ?? -1);
        answerCb($id, 'Downloading...');

        $searches = readJson('searches');
        $item = $searches[$session] ?? null;
        if (!is_array($item) || (int)($item['user_id'] ?? 0) !== $uid) {
            sendMsg($uid, "❌ Session expired.");
            return;
        }
        $song = $item['results'][$index] ?? null;
        if (!is_array($song)) { sendMsg($uid, "❌ Song not found."); return; }

        sendMsg($uid, "⏳ Downloading <b>" . esc($song['title']) . "</b>...");
        sendAudioFile($uid, $song);
        return;
    }
}


function handlePickSong(int $uid, string $cbId, string $data): void {
    answerCb($cbId, 'Preparing player…');
    $parts = explode(':', $data);
    $session = $parts[1] ?? '';
    $index = (int)($parts[2] ?? -1);

    $searches = readJson('searches');
    $item = $searches[$session] ?? null;
    if (!is_array($item)) { sendMsg($uid, "❌ Search expired."); return; }
    if ((int)($item['user_id'] ?? 0) !== $uid) { sendMsg($uid, "❌ Access denied."); return; }
    if ((int)($item['expires_at'] ?? 0) < now()) { sendMsg($uid, "❌ Search expired."); return; }
    if (!privateAccess($uid)) { sendMsg($uid, "💎 Premium required."); return; }

    $queue = $item['results'] ?? [];
    $song = $queue[$index] ?? null;
    if (!is_array($song) || empty($song['download_url'])) { sendMsg($uid, "❌ Invalid song."); return; }

    if (empty($song['artwork'])) {
        $queue[$index]['artwork'] = artworkCached($song['title'], $song['artists']);
        $song = $queue[$index];
    }
    foreach ($queue as $i => $s) {
        if (empty($s['stream_token'])) {
            $queue[$i]['stream_token'] = createStreamToken($s['download_url'], $uid);
        }
    }
    $song = $queue[$index];
    addToHistory($uid, $song);

    $token = createPlayerToken($song, $queue, $uid);

    $caption = "🎵 <b>" . esc($song['title']) . "</b>\n🎤 " . esc($song['artists']);
    if (!empty($song['album'])) $caption .= "\n💿 " . esc($song['album']);
    if (!empty($song['duration'])) $caption .= "\n⏱ " . esc($song['duration']);
    $caption .= "\n\n♾️ <i>Infinite autoplay enabled</i>";

    $buttons = [
        [['text' => '🎧  PLAY NOW', 'web_app' => ['url' => playerUrl($token)]]],
        [
            ['text' => '📥  Download MP3', 'callback_data' => 'dl:' . $session . ':' . $index],
            ['text' => '💚  Favorite',     'callback_data' => 'fav:' . $session . ':' . $index]
        ]
    ];

    $art = $song['artwork'] ?? '';
    if ($art !== '') {
        tg('sendPhoto', [
            'chat_id' => $uid, 'photo' => $art, 'caption' => $caption,
            'parse_mode' => 'HTML', 'reply_markup' => kb($buttons)
        ]);
    } else {
        sendMsg($uid, $caption, ['reply_markup' => kb($buttons)]);
    }
}


/* ============================================================
   CAPTCHA MINI APP
   ============================================================ */

function captchaApp(): void {
    $uid = (int)($_GET['uid'] ?? 0);
    if ($uid <= 0) { http_response_code(400); exit('Invalid user'); }
    $session = createCaptchaSession($uid);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,user-scalable=no">
<meta name="theme-color" content="#0a0b0f">
<title>Verify · MAYAMUSIC</title>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{--bg:#0a0b0f;--card:#1a1d28;--purple:#8b5cf6;--pink:#ec4899;--cyan:#06b6d4;--green:#10b981;--red:#ef4444;--muted:#8b92a7}
html,body{min-height:100vh;background:var(--bg);color:white;font-family:-apple-system,BlinkMacSystemFont,"SF Pro Display","Inter",system-ui,sans-serif;overflow:hidden;position:relative}
body::before{content:"";position:fixed;inset:-50%;background:radial-gradient(circle at 20% 30%,rgba(139,92,246,0.3),transparent 45%),radial-gradient(circle at 80% 70%,rgba(236,72,153,0.25),transparent 45%),radial-gradient(circle at 50% 50%,rgba(6,182,212,0.2),transparent 50%);animation:aurora 20s ease-in-out infinite;z-index:0;pointer-events:none}
@keyframes aurora{0%,100%{transform:translate(0,0) rotate(0)}33%{transform:translate(-5%,5%) rotate(120deg)}66%{transform:translate(5%,-5%) rotate(240deg)}}
.wrap{position:relative;z-index:1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{width:100%;max-width:380px;background:rgba(26,29,40,0.85);backdrop-filter:blur(30px);-webkit-backdrop-filter:blur(30px);border:1px solid rgba(255,255,255,0.06);border-radius:28px;padding:32px 24px;box-shadow:0 40px 100px rgba(0,0,0,0.6);position:relative;overflow:hidden}
.card::before{content:"";position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:conic-gradient(from 0deg,transparent,var(--purple),transparent,var(--pink),transparent);animation:rotate 8s linear infinite;opacity:0.15;z-index:-1}
@keyframes rotate{to{transform:rotate(360deg)}}
.steps{display:flex;justify-content:center;gap:12px;margin-bottom:32px}
.stepDot{width:36px;height:4px;background:rgba(255,255,255,0.1);border-radius:100px;transition:all 0.4s cubic-bezier(0.16,1,0.3,1)}
.stepDot.active{background:linear-gradient(90deg,var(--purple),var(--pink));box-shadow:0 0 20px rgba(139,92,246,0.6);width:60px}
.stepDot.done{background:var(--green);box-shadow:0 0 12px var(--green)}
.step{display:none;text-align:center;animation:fadeIn 0.5s cubic-bezier(0.16,1,0.3,1)}
.step.active{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.iconCircle{width:100px;height:100px;margin:0 auto 24px;border-radius:50%;background:linear-gradient(135deg,rgba(139,92,246,0.15),rgba(236,72,153,0.15));border:2px solid rgba(139,92,246,0.3);display:flex;align-items:center;justify-content:center;position:relative}
.iconCircle::after{content:"";position:absolute;inset:-8px;border-radius:50%;border:2px solid transparent;border-top-color:var(--purple);animation:spin 2s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.iconCircle svg{width:44px;height:44px;fill:white}
.loader{width:100px;height:4px;background:rgba(255,255,255,0.08);border-radius:100px;margin:24px auto 0;overflow:hidden}
.loaderBar{width:0%;height:100%;background:linear-gradient(90deg,var(--purple),var(--pink),var(--cyan));background-size:200% 100%;border-radius:100px;animation:load 1.2s cubic-bezier(0.16,1,0.3,1) forwards,gradientShift 1s linear infinite}
@keyframes load{to{width:100%}}
@keyframes gradientShift{to{background-position:200% 0}}
h1{font-size:22px;font-weight:900;letter-spacing:-0.02em;margin-bottom:8px;background:linear-gradient(135deg,#fff,#c9c5ff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
p.subtitle{font-size:13px;color:var(--muted);line-height:1.5;margin-bottom:24px}
.captchaBox{background:rgba(0,0,0,0.4);border-radius:16px;padding:12px;margin-bottom:16px;display:flex;align-items:center;gap:12px}
.captchaImg{flex:1;height:90px;border-radius:10px;background:#05060a;object-fit:contain;display:block}
.refreshBtn{width:44px;height:44px;border-radius:12px;border:0;background:rgba(139,92,246,0.2);color:white;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s}
.refreshBtn:active{transform:scale(0.9) rotate(180deg)}
.refreshBtn svg{width:20px;height:20px;fill:currentColor}
input.captchaInput{width:100%;padding:18px;background:rgba(0,0,0,0.4);border:2px solid rgba(255,255,255,0.08);border-radius:14px;color:white;font-size:22px;font-weight:900;text-align:center;letter-spacing:8px;text-transform:uppercase;outline:none;transition:all 0.3s;font-family:ui-monospace,monospace}
input.captchaInput:focus{border-color:var(--purple);box-shadow:0 0 30px rgba(139,92,246,0.3)}
input.captchaInput.error{border-color:var(--red);animation:shake 0.4s}
@keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-10px)}75%{transform:translateX(10px)}}
.btn{width:100%;padding:18px;border:0;border-radius:16px;background:linear-gradient(135deg,var(--purple),var(--pink));color:white;font-size:15px;font-weight:900;letter-spacing:1px;text-transform:uppercase;cursor:pointer;margin-top:16px;transition:all 0.3s;box-shadow:0 12px 40px rgba(139,92,246,0.4);position:relative;overflow:hidden}
.btn:active{transform:scale(0.97)}
.btn::after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.2),transparent);transform:translateX(-100%);animation:shine 3s infinite}
@keyframes shine{0%,100%{transform:translateX(-100%)}50%{transform:translateX(100%)}}
.btn:disabled{opacity:0.4;cursor:not-allowed}
.errorMsg{color:var(--red);font-size:12px;font-weight:700;margin-top:12px;min-height:16px;text-align:center}
.successCircle{width:120px;height:120px;margin:0 auto 24px;border-radius:50%;background:linear-gradient(135deg,var(--green),#059669);display:flex;align-items:center;justify-content:center;box-shadow:0 0 60px rgba(16,185,129,0.5);animation:successPop 0.6s cubic-bezier(0.16,1,0.3,1)}
@keyframes successPop{0%{transform:scale(0)}70%{transform:scale(1.2)}100%{transform:scale(1)}}
.successCircle svg{width:60px;height:60px;stroke:white;stroke-width:3;stroke-linecap:round;stroke-linejoin:round;fill:none}
.successCircle svg path{stroke-dasharray:50;stroke-dashoffset:50;animation:drawCheck 0.6s 0.3s cubic-bezier(0.16,1,0.3,1) forwards}
@keyframes drawCheck{to{stroke-dashoffset:0}}
.confetti{position:fixed;width:10px;height:10px;top:-20px;z-index:9999;pointer-events:none;animation:fall linear forwards;border-radius:2px}
@keyframes fall{to{transform:translateY(105vh) rotate(720deg);opacity:0}}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="steps">
            <div class="stepDot active" id="dot1"></div>
            <div class="stepDot" id="dot2"></div>
            <div class="stepDot" id="dot3"></div>
        </div>
        <div class="step active" id="step1">
            <div class="iconCircle"><svg viewBox="0 0 24 24"><path d="M17 1H7c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-2-2-2zm0 18H7V5h10v14z"/></svg></div>
            <h1>Verifying Device</h1>
            <p class="subtitle">Please wait while we secure your session...</p>
            <div class="loader"><div class="loaderBar"></div></div>
        </div>
        <div class="step" id="step2">
            <div class="iconCircle"><svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1s3.1 1.39 3.1 3.1v2z"/></svg></div>
            <h1>Enter Captcha</h1>
            <p class="subtitle">Type the 4 characters you see below</p>
            <div class="captchaBox">
                <img id="captchaImg" class="captchaImg" src="" alt="captcha">
                <button class="refreshBtn" id="refreshBtn" type="button"><svg viewBox="0 0 24 24"><path d="M17.65 6.35A8 8 0 1019.73 14h-2.08A6 6 0 1112 6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg></button>
            </div>
            <input type="text" id="captchaInput" class="captchaInput" maxlength="4" autocomplete="off" autocorrect="off" autocapitalize="characters" spellcheck="false" placeholder="____">
            <div class="errorMsg" id="errorMsg"></div>
            <button class="btn" id="submitBtn" type="button">Verify</button>
        </div>
        <div class="step" id="step3">
            <div class="successCircle"><svg viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg></div>
            <h1>Verified!</h1>
            <p class="subtitle">Welcome to MAYAMUSIC 🎵<br>Opening bot...</p>
        </div>
    </div>
</div>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
const tg = window.Telegram?.WebApp;
if(tg){tg.ready();tg.expand();tg.setHeaderColor("#0a0b0f");tg.setBackgroundColor("#0a0b0f")}
const UID = ' . $uid . ';
const SESSION = ' . json_encode($session['session']) . ';
const BASE = ' . json_encode(configWebAppUrl()) . ';
const $ = id => document.getElementById(id);
const step1=$("step1"),step2=$("step2"),step3=$("step3");
const dot1=$("dot1"),dot2=$("dot2"),dot3=$("dot3");
const captchaImg=$("captchaImg"),captchaInput=$("captchaInput");
const submitBtn=$("submitBtn"),errorMsg=$("errorMsg"),refreshBtn=$("refreshBtn");
let currentCaptchaSession = SESSION;
setTimeout(()=>{
    step1.classList.remove("active");dot1.classList.remove("active");dot1.classList.add("done");
    step2.classList.add("active");dot2.classList.add("active");
    loadCaptcha();
    setTimeout(()=>captchaInput.focus(),300);
},1400);
function loadCaptcha(){captchaImg.src=BASE+"?captcha="+currentCaptchaSession+"&t="+Date.now()}
refreshBtn.onclick=()=>{errorMsg.textContent="";captchaInput.value="";loadCaptcha()};
captchaInput.addEventListener("input",e=>{
    e.target.value=e.target.value.toUpperCase().replace(/[^A-Z0-9]/g,"");
    errorMsg.textContent="";
    if(e.target.value.length===4)setTimeout(()=>submitBtn.click(),200);
});
submitBtn.onclick=async()=>{
    const code=captchaInput.value.trim();
    if(code.length!==4){errorMsg.textContent="Enter 4 characters";captchaInput.classList.add("error");setTimeout(()=>captchaInput.classList.remove("error"),400);return}
    submitBtn.disabled=true;submitBtn.textContent="Verifying...";
    try{
        const res=await fetch(BASE+"?verify=1&session="+encodeURIComponent(currentCaptchaSession)+"&code="+encodeURIComponent(code));
        const data=await res.json();
        if(data.ok){
            step2.classList.remove("active");dot2.classList.remove("active");dot2.classList.add("done");
            step3.classList.add("active");dot3.classList.add("active");
            fireConfetti();
            if(tg?.HapticFeedback)tg.HapticFeedback.notificationOccurred("success");
            setTimeout(()=>{tg?tg.close():window.close()},2500);
        }else{
            errorMsg.textContent=data.error||"Wrong code";
            captchaInput.classList.add("error");captchaInput.value="";
            if(tg?.HapticFeedback)tg.HapticFeedback.notificationOccurred("error");
            setTimeout(()=>captchaInput.classList.remove("error"),400);
            if(data.remaining>0)loadCaptcha();
            submitBtn.disabled=false;submitBtn.textContent="Verify";
        }
    }catch(e){errorMsg.textContent="Network error.";submitBtn.disabled=false;submitBtn.textContent="Verify"}
};
function fireConfetti(){
    const colors=["#8b5cf6","#ec4899","#06b6d4","#fbbf24","#10b981"];
    for(let i=0;i<80;i++){
        setTimeout(()=>{
            const c=document.createElement("div");
            c.className="confetti";c.style.left=Math.random()*100+"vw";
            c.style.background=colors[Math.floor(Math.random()*colors.length)];
            c.style.animationDuration=(2+Math.random()*2)+"s";
            document.body.appendChild(c);setTimeout(()=>c.remove(),4000);
        },i*25);
    }
}
</script>
</body>
</html>';
    exit;
}


/* ============================================================
   MUSIC PLAYER MINI APP
   ============================================================ */

function miniApp(): void {
    $token = trim((string)($_GET['token'] ?? ''));
    $players = readJson('players');
    $player = $players[$token] ?? null;

    header('Content-Type: text/html; charset=UTF-8');

    if (!is_array($player)) {
        http_response_code(404);
        echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Expired</title><style>body{margin:0;background:#08090d;color:#fff;font-family:system-ui;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center;padding:20px}</style></head><body><div><h2>⏱ Player Expired</h2><p style="opacity:.6;margin-top:12px">Search the song again.</p></div></body></html>';
        return;
    }
    if ((int)($player['expires_at'] ?? 0) < now()) {
        http_response_code(410);
        echo '<!doctype html><html><body style="background:#08090d;color:#fff;font-family:system-ui;text-align:center;padding:60px"><h2>Player expired</h2></body></html>';
        return;
    }

    $song = $player['song'] ?? [];
    $queue = $player['queue'] ?? [];
    $uid = (int)($player['user_id'] ?? 0);

    foreach ($queue as $i => $s) {
        if (empty($s['stream_token'])) {
            $queue[$i]['stream_token'] = createStreamToken((string)($s['download_url'] ?? ''), $uid);
        }
    }
    foreach ($queue as $s) {
        if (($s['download_url'] ?? '') === ($player['song']['download_url'] ?? '')) { $song = $s; break; }
    }

    $songTitle = json_encode((string)($song['title'] ?? ''), JSON_UNESCAPED_UNICODE);
    $songArtist = json_encode((string)($song['artists'] ?? ''), JSON_UNESCAPED_UNICODE);
    $songStream = json_encode((string)($song['stream_token'] ?? ''), JSON_UNESCAPED_SLASHES);
    $songArt = json_encode((string)($song['artwork'] ?? ''), JSON_UNESCAPED_SLASHES);
    $songOriginalUrl = json_encode((string)($song['download_url'] ?? ''), JSON_UNESCAPED_SLASHES);
    $queueJson = json_encode($queue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $streamBase = json_encode(configWebAppUrl() . '?stream=');

    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,user-scalable=no">
<meta name="theme-color" content="#0a0b0f">
<title>MAYAMUSIC</title>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{--bg:#0a0b0f;--card:#1a1d28;--elev:#232735;--purple:#8b5cf6;--pink:#ec4899;--cyan:#06b6d4;--gold:#fbbf24;--green:#10b981;--muted:#8b92a7}
html,body{min-height:100vh;background:var(--bg);color:#fff;font-family:-apple-system,BlinkMacSystemFont,"SF Pro Display","Inter",system-ui,sans-serif;letter-spacing:-0.01em;overflow-x:hidden}
body::before{content:"";position:fixed;inset:-50%;background:radial-gradient(circle at 20% 30%,rgba(139,92,246,0.25),transparent 45%),radial-gradient(circle at 80% 70%,rgba(236,72,153,0.2),transparent 45%),radial-gradient(circle at 50% 50%,rgba(6,182,212,0.15),transparent 50%);animation:aurora 20s ease-in-out infinite;z-index:0;pointer-events:none}
@keyframes aurora{0%,100%{transform:translate(0,0) rotate(0)}33%{transform:translate(-5%,5%) rotate(120deg)}66%{transform:translate(5%,-5%) rotate(240deg)}}
.app{position:relative;z-index:1;min-height:100vh;padding:calc(14px + env(safe-area-inset-top)) 20px calc(28px + env(safe-area-inset-bottom));display:flex;flex-direction:column;max-width:520px;margin:0 auto}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;animation:slideDown 0.6s cubic-bezier(0.16,1,0.3,1)}
@keyframes slideDown{from{opacity:0;transform:translateY(-20px)}to{opacity:1;transform:translateY(0)}}
.brand{display:flex;align-items:center;gap:10px;font-weight:900;font-size:14px;letter-spacing:1.5px}
.brandIcon{width:28px;height:28px;position:relative;display:flex;align-items:center;justify-content:center}
.brandIcon span{position:absolute;width:3px;background:linear-gradient(180deg,var(--purple),var(--pink));border-radius:2px;animation:eq 1.2s ease-in-out infinite}
.brandIcon span:nth-child(1){left:5px;height:12px;animation-delay:0s}
.brandIcon span:nth-child(2){left:12px;height:20px;animation-delay:0.15s}
.brandIcon span:nth-child(3){left:19px;height:14px;animation-delay:0.3s}
@keyframes eq{0%,100%{transform:scaleY(0.5)}50%{transform:scaleY(1.2)}}
.badge{font-size:10px;padding:5px 10px;background:linear-gradient(135deg,rgba(139,92,246,0.2),rgba(236,72,153,0.2));border:1px solid rgba(139,92,246,0.3);border-radius:100px;color:var(--purple);font-weight:700;letter-spacing:0.5px;text-transform:uppercase;display:flex;align-items:center;gap:5px}
.badge::before{content:"";width:6px;height:6px;background:var(--green);border-radius:50%;box-shadow:0 0 8px var(--green);animation:pulseDot 1.5s infinite}
@keyframes pulseDot{0%,100%{opacity:1}50%{opacity:0.4}}
.coverWrap{position:relative;width:min(76vw,320px);aspect-ratio:1;margin:20px auto 16px;animation:fadeIn 0.8s cubic-bezier(0.16,1,0.3,1) 0.1s both}
@keyframes fadeIn{from{opacity:0;transform:scale(0.9)}to{opacity:1;transform:scale(1)}}
.coverGlow{position:absolute;inset:-20px;background:radial-gradient(circle,rgba(139,92,246,0.5),transparent 70%);filter:blur(30px);animation:pulseGlow 3s ease-in-out infinite;z-index:0}
@keyframes pulseGlow{0%,100%{opacity:0.5;transform:scale(1)}50%{opacity:0.9;transform:scale(1.05)}}
.cover{position:relative;z-index:1;width:100%;height:100%;object-fit:cover;border-radius:24px;box-shadow:0 30px 80px rgba(0,0,0,0.7),0 0 0 1px rgba(255,255,255,0.05) inset;transition:opacity 0.4s;background:linear-gradient(135deg,#1a1d28,#232735)}
.songInfo{text-align:center;margin:8px 0 16px;padding:0 12px;animation:fadeIn 0.8s 0.2s both}
.title{font-size:21px;font-weight:900;line-height:1.25;background:linear-gradient(135deg,#fff,#c9c5ff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:5px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.artist{font-size:13px;color:var(--muted);font-weight:600}
.progressArea{margin:16px 0 12px;animation:fadeIn 0.8s 0.3s both}
.progress{position:relative;width:100%;height:6px;background:var(--elev);border-radius:100px;overflow:hidden;cursor:pointer;touch-action:none}
.progressFill{position:relative;width:0%;height:100%;background:linear-gradient(90deg,var(--purple),var(--pink),var(--cyan));background-size:200% 100%;border-radius:100px;transition:width 0.1s linear;animation:gradientShift 3s ease infinite}
@keyframes gradientShift{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
.progressFill::after{content:"";position:absolute;right:-6px;top:50%;width:12px;height:12px;background:white;border-radius:50%;transform:translateY(-50%);box-shadow:0 0 12px var(--purple)}
.times{display:flex;justify-content:space-between;margin-top:8px;font-size:11px;color:var(--muted);font-weight:600;font-variant-numeric:tabular-nums}
.controls{display:flex;align-items:center;justify-content:center;gap:16px;margin:12px 0 18px;animation:fadeIn 0.8s 0.4s both}
.control{position:relative;width:52px;height:52px;border:0;border-radius:50%;background:var(--card);color:white;cursor:pointer;transition:all 0.2s cubic-bezier(0.16,1,0.3,1);box-shadow:0 4px 20px rgba(0,0,0,0.3),0 0 0 1px rgba(255,255,255,0.04) inset;display:flex;align-items:center;justify-content:center;overflow:hidden}
.control:active{transform:scale(0.9)}
.control.active{background:linear-gradient(135deg,var(--purple),var(--pink))}
.control svg{width:20px;height:20px;fill:white;position:relative;z-index:1}
.play{width:70px;height:70px;background:linear-gradient(135deg,var(--purple),var(--pink));box-shadow:0 12px 40px rgba(139,92,246,0.5)}
.play svg{width:26px;height:26px}
.play::after{content:"";position:absolute;inset:-4px;border-radius:50%;background:linear-gradient(135deg,var(--purple),var(--pink));filter:blur(16px);opacity:0.6;z-index:-1;animation:playPulse 2s ease-in-out infinite}
@keyframes playPulse{0%,100%{opacity:0.4;transform:scale(1)}50%{opacity:0.8;transform:scale(1.1)}}
.actions{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px;animation:fadeIn 0.8s 0.5s both}
.actionBtn{border:1px solid rgba(255,255,255,0.06);background:var(--card);color:white;padding:12px 6px;border-radius:14px;font-size:11px;font-weight:700;cursor:pointer;transition:all 0.2s;display:flex;flex-direction:column;align-items:center;gap:5px}
.actionBtn:active{transform:scale(0.95);background:var(--elev)}
.actionBtn.active{background:linear-gradient(135deg,rgba(139,92,246,0.25),rgba(236,72,153,0.25));border-color:var(--purple)}
.actionBtn svg{width:20px;height:20px;fill:currentColor}
.panel{background:linear-gradient(180deg,var(--card),#12141c);border-radius:20px;padding:18px;max-height:30vh;overflow-y:auto;font-size:13px;line-height:1.9;color:var(--muted);border:1px solid rgba(255,255,255,0.04);margin-bottom:12px;display:none;scrollbar-width:thin}
.panel.show{display:block;animation:slideUp 0.4s cubic-bezier(0.16,1,0.3,1)}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.lyricsText{text-align:center;white-space:pre-wrap}
.queueItem{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:12px;cursor:pointer;transition:all 0.2s;font-size:13px}
.queueItem:active{background:var(--elev)}
.queueItem.active{background:linear-gradient(135deg,rgba(139,92,246,0.15),rgba(236,72,153,0.15));border:1px solid rgba(139,92,246,0.3)}
.queueNum{width:22px;height:22px;border-radius:50%;background:var(--elev);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--muted);flex-shrink:0}
.queueItem.active .queueNum{background:var(--purple);color:white}
.queueText{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:white}
.queueText span{font-size:11px;color:var(--muted);display:block}
.status{text-align:center;font-size:11px;color:var(--muted);font-weight:600;letter-spacing:0.5px;text-transform:uppercase;padding:8px;animation:fadeIn 0.8s 0.7s both;display:flex;align-items:center;justify-content:center;gap:6px}
.status::before{content:"";width:6px;height:6px;border-radius:50%;background:var(--muted);transition:all 0.3s}
.status.playing{color:var(--cyan)}
.status.playing::before{background:var(--cyan);box-shadow:0 0 12px var(--cyan);animation:statusPulse 1.5s infinite}
@keyframes statusPulse{0%,100%{opacity:1}50%{opacity:0.4}}
.dlFloat{position:fixed;bottom:calc(20px + env(safe-area-inset-bottom));right:20px;width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--green),#059669);color:white;border:0;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 12px 40px rgba(16,185,129,0.5);z-index:100;transition:all 0.2s;animation:fadeIn 0.8s 0.8s both}
.dlFloat:active{transform:scale(0.9)}
.dlFloat svg{width:24px;height:24px;fill:white}
.toast{position:fixed;bottom:100px;left:50%;transform:translateX(-50%) translateY(100px);background:rgba(26,29,40,0.95);backdrop-filter:blur(20px);color:white;padding:14px 20px;border-radius:14px;font-size:13px;font-weight:700;border:1px solid rgba(139,92,246,0.4);box-shadow:0 20px 60px rgba(0,0,0,0.5);z-index:200;opacity:0;transition:all 0.3s cubic-bezier(0.16,1,0.3,1);pointer-events:none}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
</style>
</head>
<body>
<div class="app">
    <div class="header">
        <div class="brand">
            <div class="brandIcon"><span></span><span></span><span></span></div>
            <span>MAYAMUSIC</span>
        </div>
        <div class="badge">LIVE</div>
    </div>

    <div class="coverWrap" id="coverWrap">
        <div class="coverGlow"></div>
        <img id="cover" class="cover" src="" alt="" onerror="this.style.opacity=0.3">
    </div>

    <div class="songInfo">
        <div id="title" class="title">Loading...</div>
        <div id="artist" class="artist"></div>
    </div>

    <div class="progressArea">
        <div id="progress" class="progress">
            <div id="progressFill" class="progressFill"></div>
        </div>
        <div class="times">
            <span id="current">0:00</span>
            <span id="duration">0:00</span>
        </div>
    </div>

    <div class="controls">
        <button id="shuffleBtn" class="control" title="Shuffle">
            <svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
        </button>
        <button id="prev" class="control" title="Previous">
            <svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
        </button>
        <button id="play" class="control play" title="Play">
            <svg id="playIcon" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
        </button>
        <button id="next" class="control" title="Next">
            <svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
        </button>
        <button id="repeatBtn" class="control" title="Repeat">
            <svg viewBox="0 0 24 24"><path d="M7 7h10v3l4-4-4-4v3H5v6h2V7zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4z"/></svg>
        </button>
    </div>

    <div class="actions">
        <button id="lyricsBtn" class="actionBtn">
            <svg viewBox="0 0 24 24"><path d="M12 3v10.55A4 4 0 1014 17V7h4V3h-6z"/></svg>
            <span>Lyrics</span>
        </button>
        <button id="queueBtn" class="actionBtn">
            <svg viewBox="0 0 24 24"><path d="M3 6h18v2H3zm0 5h18v2H3zm0 5h12v2H3z"/></svg>
            <span>Queue</span>
        </button>
        <button id="favBtn" class="actionBtn">
            <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
            <span>Favorite</span>
        </button>
        <button id="speedBtn" class="actionBtn">
            <svg viewBox="0 0 24 24"><path d="M10 8v8l6-4-6-4z"/></svg>
            <span id="speedLabel">1x</span>
        </button>
    </div>

    <div id="lyricsPanel" class="panel">
        <div id="lyricsText" class="lyricsText">Loading lyrics...</div>
    </div>

    <div id="queuePanel" class="panel"></div>

    <div id="status" class="status">Ready</div>
</div>

<button class="dlFloat" id="dlFloat" title="Download MP3">
    <svg viewBox="0 0 24 24"><path d="M5 20h14v-2H5v2zM19 9h-4V3H9v6H5l7 7 7-7z"/></svg>
</button>
<div class="toast" id="toast"></div>

<audio id="audio" preload="auto" crossorigin="anonymous"></audio>

<script src="https://telegram.org/js/telegram-web-app.js"></script>
<script>
const tg = window.Telegram?.WebApp;
if(tg){tg.ready();tg.expand();tg.setHeaderColor("#0a0b0f");tg.setBackgroundColor("#0a0b0f")}

let queue = ' . $queueJson . ';
if(!Array.isArray(queue)) queue = [];

let currentIndex = 0;
let currentTitle = ' . $songTitle . ';
let currentArtist = ' . $songArtist . ';
let currentStream = ' . $songStream . ';
let currentArtwork = ' . $songArt . ';
let currentOriginal = ' . $songOriginalUrl . ';

const STREAM_BASE = ' . $streamBase . ';

const $ = id => document.getElementById(id);
const audio = $("audio");
const cover = $("cover");
const coverWrap = $("coverWrap");
const titleEl = $("title");
const artistEl = $("artist");
const playBtn = $("play");
const playIcon = $("playIcon");
const prevBtn = $("prev");
const nextBtn = $("next");
const shuffleBtn = $("shuffleBtn");
const repeatBtn = $("repeatBtn");
const progress = $("progress");
const progressFill = $("progressFill");
const currentTimeEl = $("current");
const durationEl = $("duration");
const lyricsPanel = $("lyricsPanel");
const lyricsText = $("lyricsText");
const queuePanel = $("queuePanel");
const statusEl = $("status");
const favBtn = $("favBtn");
const speedBtn = $("speedBtn");
const speedLabel = $("speedLabel");
const dlFloat = $("dlFloat");
const toast = $("toast");

const ICON_PLAY = "M8 5v14l11-7z";
const ICON_PAUSE = "M6 5h4v14H6zm8 0h4v14h-4z";

let isShuffle = false;
let isRepeat = false;
let playbackRate = 1;
const rates = [1, 1.25, 1.5, 2, 0.5];
let rateIdx = 0;

function timeFormat(s){s=Math.floor(Number(s)||0);return Math.floor(s/60)+":"+String(s%60).padStart(2,"0")}
function setStatus(text,playing=false){statusEl.textContent=text;statusEl.classList.toggle("playing",playing)}
function showToast(msg){toast.textContent=msg;toast.classList.add("show");clearTimeout(toast._t);toast._t=setTimeout(()=>toast.classList.remove("show"),2000)}
function haptic(type){if(tg?.HapticFeedback){try{tg.HapticFeedback.impactOccurred(type||"light")}catch(e){}}}

function loadSong(song, autoPlay=true){
    if(!song || !song.download_url) return;
    currentTitle = song.title || "Unknown";
    currentArtist = song.artists || "Unknown Artist";
    currentArtwork = song.artwork || "";
    currentOriginal = song.download_url;
    
    const streamToken = song.stream_token || "";
    let playUrl = "";
    if(streamToken){
        playUrl = STREAM_BASE + streamToken;
    } else {
        playUrl = song.download_url;
    }

    titleEl.textContent = currentTitle;
    artistEl.textContent = currentArtist;

    if(currentArtwork){
        cover.src = currentArtwork;
        cover.style.opacity = "0";
        cover.onload = () => cover.style.opacity = "1";
    } else {
        cover.removeAttribute("src");
    }

    audio.pause();
    audio.src = playUrl;
    audio.playbackRate = playbackRate;
    audio.load();

    loadLyrics(currentTitle, currentArtist);
    renderQueue();

    if(autoPlay) startPlayback();
}

function startPlayback(){
    const p = audio.play();
    if(p){
        p.then(()=>{
            playIcon.innerHTML = '<path d="'+ICON_PAUSE+'"/>';
            setStatus("Playing", true);
        }).catch(err => {
            playIcon.innerHTML = '<path d="'+ICON_PLAY+'"/>';
            setStatus("Tap to play");
            console.error("Play error:", err);
        });
    }
}

function pausePlayback(){
    audio.pause();
    playIcon.innerHTML = '<path d="'+ICON_PLAY+'"/>';
    setStatus("Paused");
}

playBtn.onclick = () => audio.paused ? startPlayback() : pausePlayback();

audio.addEventListener("timeupdate", () => {
    const cur = audio.currentTime || 0;
    const total = audio.duration || 0;
    currentTimeEl.textContent = timeFormat(cur);
    durationEl.textContent = timeFormat(total);
    if(total > 0) progressFill.style.width = (cur / total * 100) + "%";
});

audio.addEventListener("loadedmetadata", () => {
    durationEl.textContent = timeFormat(audio.duration);
});

audio.addEventListener("playing", () => {
    playIcon.innerHTML = '<path d="'+ICON_PAUSE+'"/>';
    setStatus("Playing", true);
});

audio.addEventListener("pause", () => {
    if(!audio.ended){
        playIcon.innerHTML = '<path d="'+ICON_PLAY+'"/>';
        setStatus("Paused");
    }
});

audio.addEventListener("error", e => {
    setStatus("Error, skipping...");
    setTimeout(() => nextTrack(true), 1500);
});

audio.addEventListener("ended", () => {
    if(isRepeat){
        audio.currentTime = 0;
        startPlayback();
        return;
    }
    nextTrack(true);
});

async function nextTrack(autoTriggered){
    if(isShuffle){
        currentIndex = Math.floor(Math.random() * queue.length);
        loadSong(queue[currentIndex], true);
        return;
    }
    if(currentIndex + 1 < queue.length){
        currentIndex++;
        loadSong(queue[currentIndex], true);
        return;
    }
    if(autoTriggered){
        setStatus("Loading more...", true);
        try {
            const res = await fetch(location.pathname + "?action=related&title=" + 
                encodeURIComponent(currentTitle) + "&artist=" + encodeURIComponent(currentArtist));
            const data = await res.json();
            if(data && data.songs && data.songs.length){
                const newSongs = data.songs.map(s => s);
                queue = queue.concat(newSongs);
                currentIndex++;
                loadSong(queue[currentIndex], true);
                renderQueue();
                return;
            }
        } catch(e){ console.error("Related failed", e); }
        currentIndex = 0;
        loadSong(queue[0], true);
    }
}

prevBtn.onclick = () => {
    haptic();
    if(audio.currentTime > 3){ audio.currentTime = 0; return; }
    if(currentIndex > 0){ currentIndex--; loadSong(queue[currentIndex], true); }
    else { audio.currentTime = 0; }
};

nextBtn.onclick = () => {
    haptic();
    if(currentIndex + 1 < queue.length){ currentIndex++; loadSong(queue[currentIndex], true); }
    else nextTrack(true);
};

shuffleBtn.onclick = () => {
    haptic();
    isShuffle = !isShuffle;
    shuffleBtn.classList.toggle("active", isShuffle);
    showToast(isShuffle ? "🔀 Shuffle ON" : "🔀 Shuffle OFF");
};

repeatBtn.onclick = () => {
    haptic();
    isRepeat = !isRepeat;
    repeatBtn.classList.toggle("active", isRepeat);
    showToast(isRepeat ? "🔁 Repeat ON" : "🔁 Repeat OFF");
};

speedBtn.onclick = () => {
    haptic();
    rateIdx = (rateIdx + 1) % rates.length;
    playbackRate = rates[rateIdx];
    audio.playbackRate = playbackRate;
    speedLabel.textContent = playbackRate + "x";
    showToast("Speed: " + playbackRate + "x");
};

progress.onclick = e => {
    const rect = progress.getBoundingClientRect();
    const pct = (e.clientX - rect.left) / rect.width;
    if(audio.duration) audio.currentTime = pct * audio.duration;
};

progress.addEventListener("touchstart", e => {
    const rect = progress.getBoundingClientRect();
    const pct = (e.touches[0].clientX - rect.left) / rect.width;
    if(audio.duration) audio.currentTime = Math.max(0, Math.min(1, pct)) * audio.duration;
});

$("lyricsBtn").onclick = () => {
    haptic();
    lyricsPanel.classList.toggle("show");
    $("lyricsBtn").classList.toggle("active", lyricsPanel.classList.contains("show"));
    if(lyricsPanel.classList.contains("show")) queuePanel.classList.remove("show");
};

$("queueBtn").onclick = () => {
    haptic();
    queuePanel.classList.toggle("show");
    $("queueBtn").classList.toggle("active", queuePanel.classList.contains("show"));
    if(queuePanel.classList.contains("show")) lyricsPanel.classList.remove("show");
};

favBtn.onclick = () => {
    haptic("medium");
    favBtn.classList.toggle("active");
    showToast(favBtn.classList.contains("active") ? "💚 Added to favorites" : "Removed from favorites");
};

dlFloat.onclick = () => {
    haptic("heavy");
    showToast("📥 Preparing download...");
    const url = "https://t.me/' . BOT_USERNAME . '";
    setTimeout(() => {
        if(tg){ tg.close(); }
    }, 800);
};

function loadLyrics(title, artist){
    lyricsText.textContent = "Loading lyrics...";
    const url = location.pathname + "?action=lyrics&title=" + 
        encodeURIComponent(title) + "&artist=" + encodeURIComponent(artist);
    fetch(url)
        .then(r => r.json())
        .then(data => {
            if(data.synced && data.synced.trim()){ lyricsText.textContent = data.synced; return; }
            if(data.plain && data.plain.trim()){ lyricsText.textContent = data.plain; return; }
            lyricsText.textContent = "Lyrics not found";
        })
        .catch(() => { lyricsText.textContent = "Lyrics unavailable"; });
}

function renderQueue(){
    queuePanel.innerHTML = "";
    queue.forEach((song, idx) => {
        const item = document.createElement("div");
        item.className = "queueItem" + (idx === currentIndex ? " active" : "");
        const num = document.createElement("div");
        num.className = "queueNum";
        num.textContent = idx + 1;
        const txt = document.createElement("div");
        txt.className = "queueText";
        txt.innerHTML = "<strong>" + (song.title || "Unknown") + "</strong><span>" + 
            (song.artists || "Unknown Artist") + "</span>";
        item.appendChild(num);
        item.appendChild(txt);
        item.onclick = () => {
            haptic();
            currentIndex = idx;
            loadSong(queue[currentIndex], true);
        };
        queuePanel.appendChild(item);
    });
}

let startIdx = queue.findIndex(s => s.download_url === currentOriginal);
if(startIdx < 0) startIdx = 0;
currentIndex = startIdx;

if(queue.length > 0) loadSong(queue[currentIndex], false);
renderQueue();
</script>
</body>
</html>';
    exit;
}


/* ============================================================
   MINI APP API (lyrics + related)
   ============================================================ */

function miniApi(): void {
    $action = (string)($_GET['action'] ?? '');

    if ($action === 'lyrics') {
        $title = trim((string)($_GET['title'] ?? ''));
        $artist = trim((string)($_GET['artist'] ?? ''));
        jsonReply(getLyrics($title, $artist));
    }

    if ($action === 'related') {
        $title = trim((string)($_GET['title'] ?? ''));
        $artist = trim((string)($_GET['artist'] ?? ''));
        $songs = getRelatedSongs($title, $artist);
        foreach ($songs as $i => $s) {
            $songs[$i]['stream_token'] = createStreamToken((string)($s['download_url'] ?? ''), 0);
        }
        jsonReply(['ok' => true, 'songs' => $songs]);
    }

    jsonReply(['ok' => false, 'error' => 'Unknown action']);
}


/* ============================================================
   HEALTH PAGE
   ============================================================ */

function healthPage(): void {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "MAYAMUSIC v2.0 ONLINE\n";
    echo "PHP: " . PHP_VERSION . "\n";
    echo "Bot token: " . (configBotToken() !== '' ? 'configured' : 'missing') . "\n";
    echo "WebApp: " . configWebAppUrl() . "\n";
    echo "GD: " . (extension_loaded('gd') ? 'yes' : 'no') . "\n";
    echo "Time: " . date('c') . "\n";
}


/* ============================================================
   HTTP ROUTER
   ============================================================ */

function handleHttp(): bool {
    if (isset($_GET['health'])) { healthPage(); return true; }

    if (isset($_GET['captcha'])) {
        $sessionId = preg_replace('/[^a-f0-9]/', '', (string)$_GET['captcha']);
        $captchas = readJson('captchas');
        $item = $captchas[$sessionId] ?? null;
        if (!$item || $item['expires_at'] < now()) {
            http_response_code(404);
            header('Content-Type: text/plain');
            exit;
        }
        generateCaptchaImage($item['code']);
    }

    if (isset($_GET['verify'])) {
        $session = (string)($_GET['session'] ?? '');
        $code = (string)($_GET['code'] ?? '');
        jsonReply(verifyCaptchaCode($session, $code));
    }

    if (isset($_GET['captcha_app'])) { captchaApp(); }

    if (isset($_GET['mini'])) { miniApp(); }
    if (isset($_GET['action'])) { miniApi(); }
    if (isset($_GET['stream'])) { streamProxy(); }

    return false;
}


/* ============================================================
   WEBHOOK
   ============================================================ */

function processWebhook(): void {
    // Verify secret token (security)
    $secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    $expected = configWebhookSecret();
    if ($expected !== '' && !hash_equals($expected, $secret)) {
        http_response_code(403);
        error_log('MAYAMUSIC: Invalid webhook secret');
        exit;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return;

    $update = json_decode($raw, true);
    if (!is_array($update)) return;

    if (isset($update['callback_query'])) {
        handleCallback($update['callback_query']);
        return;
    }

    if (isset($update['message'])) {
        handleMessage($update['message']);
    }
}


/* ============================================================
   BOOT
   ============================================================ */

cleanExpiredData();

if (handleHttp()) exit;

if (php_sapi_name() !== 'cli') {
    processWebhook();
}

?>
