<?php
/**
 * MAYAMUSIC
 * Single-file Telegram Bot + Telegram Mini App
 * PHP 8.1+
 *
 * Features:
 * - Music search API
 * - Album artwork preview
 * - Mini App player
 * - Previous / Next
 * - Auto-next queue
 * - Lyrics
 * - Download
 * - Premium ₹49 / 30 days
 * - UPI payment + UTR approval
 * - Redeem keys
 * - Admin permanent access
 * - Admin ON/OFF
 * - Admin statistics
 * - Admin broadcast
 * - Telegram WebApp CAPTCHA
 * - Telegram WebApp initData verification
 * - Group/channel free access
 *
 * IMPORTANT:
 * Telegram Bot API alone cannot join/stream Telegram Voice Chats.
 * /playcc commands below only store/control the requested state.
 * Actual VC audio requires a separate MTProto/voice engine.
 */

declare(strict_types=1);

/* =========================================================
   CONFIG
========================================================= */

const BOT_TOKEN = '8817347840:AAFpsNeTkzHqjnlqkV_18AjMEgIX-FXHmQo';

const ADMIN_ID = 8897821078;

const BOT_NAME = 'MAYAMUSIC';

const SUPPORT_USERNAME = 'HyperxVicky';

const WEBAPP_URL =
    'https://hosting-production-aacd.up.railway.app/index.php';

const MUSIC_API =
    'https://music-search-api-frnb.vercel.app/search?song=';

const MONTHLY_PRICE = 49;

const ACCESS_DAYS = 30;

const UPI_ID = 'vickybanna8674@ybl';

const UPI_NAME = 'MAYAMUSIC';

const DATA_DIR = __DIR__ . '/data';

const HTTP_TIMEOUT = 15;

const PROFILE_IMAGE_URL = '';

const PLAYER_TTL = 86400;


/* =========================================================
   INITIALIZE
========================================================= */

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}


/* =========================================================
   STORAGE
========================================================= */

function filePath(string $name): string
{
    return DATA_DIR . '/' . $name . '.json';
}


function readJson(
    string $name,
    array $default = []
): array {

    $path = filePath($name);

    if (!is_file($path)) {
        return $default;
    }

    $raw = @file_get_contents($path);

    if (
        $raw === false ||
        trim($raw) === ''
    ) {
        return $default;
    }

    $data = json_decode($raw, true);

    return is_array($data)
        ? $data
        : $default;
}


function writeJson(
    string $name,
    array $data
): bool {

    $path = filePath($name);

    $tmp = $path . '.tmp';

    $raw = json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($raw === false) {
        return false;
    }

    if (
        @file_put_contents(
            $tmp,
            $raw,
            LOCK_EX
        ) === false
    ) {
        return false;
    }

    return @rename($tmp, $path);
}


/* =========================================================
   HELPERS
========================================================= */

function now(): int
{
    return time();
}


function esc(string $s): string
{
    return htmlspecialchars(
        $s,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/* =========================================================
   TELEGRAM API
========================================================= */

function tg(
    string $method,
    array $params = []
): array {

    if (
        BOT_TOKEN ===
        'PASTE_NEW_BOT_TOKEN_HERE'
    ) {
        return [
            'ok' => false,
            'description' => 'BOT_TOKEN not configured'
        ];
    }

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
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => HTTP_TIMEOUT,
        ]
    );

    $body = curl_exec($ch);

    $error = curl_error($ch);

    curl_close($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'description' =>
                $error ?: 'curl error'
        ];
    }

    $data = json_decode(
        $body,
        true
    );

    return is_array($data)
        ? $data
        : [
            'ok' => false,
            'description' =>
                'Invalid Telegram response'
        ];
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
            'show_alert' =>
                $alert ? 'true' : 'false'
        ]
    );
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


function sendPhoto(
    int|string $chatId,
    string $photo,
    string $caption = '',
    array $extra = []
): array {

    return tg(
        'sendPhoto',
        array_merge(
            [
                'chat_id' => $chatId,
                'photo' => $photo,
                'caption' => $caption,
                'parse_mode' => 'HTML'
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


function kb(array $rows): string
{
    return json_encode(
        [
            'inline_keyboard' => $rows
        ],
        JSON_UNESCAPED_UNICODE
    );
}


/* =========================================================
   USERS / PREMIUM
========================================================= */

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
            'premium_until' => 0,
            'last_seen' => now(),
            'username' => '',
            'first_name' => '',
            'verified' => false,
            'verified_at' => 0
        ];

        writeJson(
            'users',
            $users
        );
    }

    $u = $users[$key];

    $u['last_seen'] = now();

    $users[$key] = $u;

    writeJson(
        'users',
        $users
    );

    return $u;
}


function updateUser(
    int $uid,
    array $patch
): array {

    $users = readJson('users');

    $key = (string)$uid;

    $u =
        $users[$key]
        ??
        [
            'id' => $uid,
            'created_at' => now(),
            'premium_until' => 0
        ];

    $u = array_merge(
        $u,
        $patch,
        [
            'last_seen' => now()
        ]
    );

    $users[$key] = $u;

    writeJson(
        'users',
        $users
    );

    return $u;
}


function premiumUntil(int $uid): int
{
    return (int)(
        userRecord($uid)['premium_until']
        ?? 0
    );
}


function premiumActive(int $uid): bool
{
    return
        isAdmin($uid)
        ||
        premiumUntil($uid) > now();
}


function musicEnabled(): bool
{
    $settings =
        readJson(
            'settings',
            ['enabled' => true]
        );

    return
        ($settings['enabled'] ?? true)
        !== false;
}


function setMusicEnabled(
    bool $enabled
): void {

    $settings =
        readJson(
            'settings',
            ['enabled' => true]
        );

    $settings['enabled'] =
        $enabled;

    $settings['updated_at'] =
        now();

    writeJson(
        'settings',
        $settings
    );
}


function hasMusicAccess(
    int $uid,
    string $chatType = 'private'
): bool {

    if (
        !musicEnabled() &&
        !isAdmin($uid)
    ) {
        return false;
    }

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
        "🔒 <b>Premium required.</b>\n\n" .
        "Private music/player access needs an active Premium plan.\n\n" .
        "Tap ⭐ Premium to activate it."
    );

    return false;
}


function fmtDate(int $ts): string
{
    return
        $ts > 0
        ? date(
            'd M Y, h:i A',
            $ts
        )
        : 'Not active';
}


/* =========================================================
   REDEEM KEYS
========================================================= */

function randomKey(): string
{
    $parts = [];

    for (
        $i = 0;
        $i < 3;
        $i++
    ) {
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
        isset(
            $keys[$key]
        )
    );

    return $key;
}


/* =========================================================
   MUSIC SEARCH
========================================================= */

function searchMusic(
    string $q
): array {

    $url =
        MUSIC_API .
        rawurlencode($q);

    $ch =
        curl_init($url);

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT =>
                HTTP_TIMEOUT,
            CURLOPT_USERAGENT =>
                'MAYAMUSIC/1.0'
        ]
    );

    $body =
        curl_exec($ch);

    curl_close($ch);

    if ($body === false) {
        return [];
    }

    $data =
        json_decode(
            $body,
            true
        );

    if (
        !is_array($data) ||
        empty($data['results']) ||
        !is_array(
            $data['results']
        )
    ) {
        return [];
    }

    $out = [];

    foreach (
        $data['results']
        as $r
    ) {

        if (
            !is_array($r) ||
            empty(
                $r['download_url']
            )
        ) {
            continue;
        }

        $out[] = [
            'title' =>
                (string)(
                    $r['title']
                    ??
                    'Unknown Title'
                ),

            'artists' =>
                (string)(
                    $r['artists']
                    ??
                    'Unknown Artist'
                ),

            'album' =>
                (string)(
                    $r['album']
                    ??
                    ''
                ),

            'duration' =>
                (string)(
                    $r['duration']
                    ??
                    ''
                ),

            'download_url' =>
                (string)
                $r['download_url']
        ];
    }

    return $out;
}


/* =========================================================
   ARTWORK
========================================================= */

function artwork(
    string $title,
    string $artist
): string {

    $q =
        rawurlencode(
            $title .
            ' ' .
            $artist
        );

    $url =
        'https://itunes.apple.com/search' .
        '?term=' .
        $q .
        '&entity=song&limit=1';

    $ch =
        curl_init($url);

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5
        ]
    );

    $body =
        curl_exec($ch);

    curl_close($ch);

    if ($body !== false) {

        $data =
            json_decode(
                $body,
                true
            );

        $image =
            $data['results'][0]
            ['artworkUrl100']
            ?? '';

        if ($image !== '') {

            return str_replace(
                '100x100bb',
                '600x600bb',
                $image
            );
        }
    }

    return '';
}


/* =========================================================
   LYRICS
========================================================= */

function lyrics(
    string $title,
    string $artist
): array {

    $url =
        'https://lrclib.net/api/search' .
        '?track_name=' .
        rawurlencode($title) .
        '&artist_name=' .
        rawurlencode($artist);

    $ch =
        curl_init($url);

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT =>
                'MAYAMUSIC/1.0'
        ]
    );

    $body =
        curl_exec($ch);

    curl_close($ch);

    if ($body === false) {
        return [
            'synced' => '',
            'plain' => ''
        ];
    }

    $data =
        json_decode(
            $body,
            true
        );

    if (
        !is_array($data) ||
        !$data
    ) {
        return [
            'synced' => '',
            'plain' => ''
        ];
    }

    foreach (
        $data as $row
    ) {

        if (!is_array($row)) {
            continue;
        }

        if (
            !empty(
                $row['syncedLyrics']
            ) ||
            !empty(
                $row['plainLyrics']
            )
        ) {

            return [
                'synced' =>
                    (string)(
                        $row['syncedLyrics']
                        ?? ''
                    ),

                'plain' =>
                    (string)(
                        $row['plainLyrics']
                        ?? ''
                    )
            ];
        }
    }

    return [
        'synced' => '',
        'plain' => ''
    ];
}


/* =========================================================
   PLAYER
========================================================= */

function playerToken(
    array $song,
    array $queue
): string {

    $players =
        readJson('players');

    $token =
        bin2hex(
            random_bytes(16)
        );

    $players[$token] = [
        'song' => $song,
        'queue' => $queue,
        'created_at' => now(),
        'expires_at' =>
            now() + PLAYER_TTL
    ];

    writeJson(
        'players',
        $players
    );

    return $token;
}


function cleanPlayers(): void
{
    $players =
        readJson('players');

    $changed = false;

    $time = now();

    foreach (
        $players as $key => $value
    ) {

        if (
            ($value['expires_at'] ?? 0)
            <
            $time
        ) {

            unset(
                $players[$key]
            );

            $changed = true;
        }
    }

    if ($changed) {

        writeJson(
            'players',
            $players
        );
    }
}


function webAppUrl(
    string $token
): string {

    return
        rtrim(
            WEBAPP_URL,
            '/'
        ) .
        '?mini=1&token=' .
        rawurlencode($token);
}


function downloadUrl(
    string $token
): string {

    return
        rtrim(
            WEBAPP_URL,
            '/'
        ) .
        '?action=download&token=' .
        rawurlencode($token);
}


/* =========================================================
   TELEGRAM WEB APP SECURITY
========================================================= */

function validateWebAppInitData(
    string $initData
): array|false {

    if (
        $initData === '' ||
        BOT_TOKEN ===
        'PASTE_NEW_BOT_TOKEN_HERE'
    ) {
        return false;
    }

    parse_str(
        $initData,
        $data
    );

    $hash =
        (string)(
            $data['hash']
            ?? ''
        );

    unset(
        $data['hash']
    );

    if ($hash === '') {
        return false;
    }

    ksort($data);

    $check = '';

    foreach (
        $data as $key => $value
    ) {

        $check .=
            $key .
            '=' .
            $value .
            "\n";
    }

    $check =
        rtrim(
            $check,
            "\n"
        );

    $secret =
        hash_hmac(
            'sha256',
            'WebAppData',
            BOT_TOKEN,
            true
        );

    $calculated =
        hash_hmac(
            'sha256',
            $check,
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

    $user =
        json_decode(
            (string)(
                $data['user']
                ?? '{}'
            ),
            true
        );

    $uid =
        (int)(
            $user['id']
            ?? 0
        );

    if ($uid <= 0) {
        return false;
    }

    return [
        'id' => $uid,
        'user' => $user
    ];
}


/* =========================================================
   CAPTCHA
========================================================= */

function captchaFor(
    int $uid
): array {

    $a =
        random_int(2, 9);

    $b =
        random_int(2, 9);

    $captcha =
        readJson('captcha');

    $captcha[
        (string)$uid
    ] = [
        'answer' =>
            (string)(
                $a + $b
            ),

        'expires_at' =>
            now() + 300
    ];

    writeJson(
        'captcha',
        $captcha
    );

    return [
        $a,
        $b
    ];
}


function verifyCaptcha(
    int $uid,
    string $answer
): bool {

    $captcha =
        readJson('captcha');

    $item =
        $captcha[
            (string)$uid
        ]
        ?? null;

    if (
        !$item ||
        (int)(
            $item['expires_at']
            ?? 0
        ) < now()
    ) {
        return false;
    }

    $valid =
        hash_equals(
            (string)(
                $item['answer']
            ),
            trim($answer)
        );

    if ($valid) {

        unset(
            $captcha[
                (string)$uid
            ]
        );

        writeJson(
            'captcha',
            $captcha
        );

        updateUser(
            $uid,
            [
                'verified' => true,
                'verified_at' => now()
            ]
        );
    }

    return $valid;
}


/* =========================================================
   VERIFICATION API
========================================================= */

function verificationApi(): void
{
    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    $input =
        json_decode(
            file_get_contents(
                'php://input'
            ) ?: '{}',
            true
        )
        ?: [];

    $verified =
        validateWebAppInitData(
            (string)(
                $input['initData']
                ?? ''
            )
        );

    if ($verified === false) {

        http_response_code(403);

        echo json_encode(
            [
                'ok' => false,
                'error' =>
                    'Telegram verification failed'
            ]
        );

        return;
    }

    $uid =
        (int)$verified['id'];

    $nonce =
        (string)(
            $input['nonce']
            ?? ''
        );

    $answer =
        trim(
            (string)(
                $input['answer']
                ?? ''
            )
        );

    $all =
        readJson(
            'captcha_nonce'
        );

    $item =
        $all[$nonce]
        ?? null;

    if (
        !$item ||
        (int)(
            $item['expires_at']
            ?? 0
        ) < now() ||
        !hash_equals(
            (string)(
                $item['answer']
            ),
            $answer
        )
    ) {

        echo json_encode(
            [
                'ok' => false,
                'error' =>
                    'Wrong or expired captcha'
            ]
        );

        return;
    }

    unset(
        $all[$nonce]
    );

    writeJson(
        'captcha_nonce',
        $all
    );

    updateUser(
        $uid,
        [
            'verified' => true,
            'verified_at' => now()
        ]
    );

    echo json_encode(
        [
            'ok' => true,
            'user_id' => $uid
        ]
    );
}


/* =========================================================
   VERIFICATION PAGE
========================================================= */

function verifyPage(): void
{
    header(
        'Content-Type:text/html; charset=UTF-8'
    );

    $a =
        random_int(2, 9);

    $b =
        random_int(2, 9);

    $nonce =
        bin2hex(
            random_bytes(8)
        );

    $temporary =
        readJson(
            'captcha_nonce'
        );

    $temporary[$nonce] = [
        'answer' =>
            (string)(
                $a + $b
            ),

        'expires_at' =>
            now() + 300
    ];

    writeJson(
        'captcha_nonce',
        $temporary
    );

    $base =
        rtrim(
            WEBAPP_URL,
            '/'
        );

    echo '
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport"
content="width=device-width,initial-scale=1">

<title>MAYAMUSIC Verify</title>

<style>

*{
box-sizing:border-box;
}

body{
margin:0;
background:#07090e;
color:#fff;
font-family:system-ui,sans-serif;
min-height:100vh;
display:grid;
place-items:center;
}

.box{
width:min(90%,420px);
padding:28px;
border-radius:28px;
background:#121722;
box-shadow:0 25px 80px #000;
}

.logo{
font-size:30px;
font-weight:900;
}

.sub{
opacity:.65;
margin:8px 0 22px;
}

.cap{
font-size:32px;
text-align:center;
padding:20px;
border-radius:18px;
background:#0b0f17;
}

.in{
width:100%;
padding:15px;
margin-top:15px;
border-radius:15px;
border:1px solid #30384a;
background:#080b11;
color:#fff;
font-size:18px;
}

.go{
width:100%;
margin-top:12px;
padding:15px;
border:0;
border-radius:15px;
font-weight:800;
}

.msg{
text-align:center;
margin-top:12px;
min-height:20px;
opacity:.75;
}

</style>
</head>

<body>

<div class="box">

<div class="logo">
🎵 MAYAMUSIC
</div>

<div class="sub">
Not a robot verification
</div>

<div class="cap">
' .
$a .
' + ' .
$b .
' = ?
</div>

<input
id="ans"
class="in"
inputmode="numeric"
placeholder="Enter answer"
>

<button
id="go"
class="go"
>
✓ VERIFY & CONTINUE
</button>

<div
id="msg"
class="msg"
></div>

</div>

<script src="https://telegram.org/js/telegram-web-app.js"></script>

<script>

const tg =
window.Telegram?.WebApp;

tg?.ready();
tg?.expand();

const nonce=' .
json_encode(
    $nonce
) .
';

const base=' .
json_encode(
    $base
) .
';

document
.getElementById("go")
.onclick=async()=>{

const msg =
document.getElementById("msg");

msg.textContent =
"Verifying…";

try{

const response =
await fetch(
base+"?action=verify",
{
method:"POST",
headers:{
"Content-Type":
"application/json"
},
body:JSON.stringify({
initData:
tg?.initData||"",
answer:
document
.getElementById("ans")
.value,
nonce:nonce
})
}
);

const data =
await response.json();

if(data.ok){

msg.textContent =
"✓ Verified. Opening MAYAMUSIC…";

setTimeout(
()=>{
location.href =
base+
"?mini=1&mode=home";
},
300
);

}else{

msg.textContent =
"❌ "+
(
data.error||
"Verification failed"
);

}

}catch(error){

msg.textContent =
"❌ Network error";

}

};

</script>

</body>
</html>
';

}


/* =========================================================
   MAIN KEYBOARD
========================================================= */

function mainKeyboard(
    int $uid
): string {

    $rows = [

        [
            [
                'text' =>
                    '🔎 Search Music',
                'callback_data' =>
                    'search'
            ],

            [
                'text' =>
                    '▶️ Mini Player',
                'callback_data' =>
                    'player'
            ]
        ],

        [
            [
                'text' =>
                    '⭐ Premium',
                'callback_data' =>
                    'premium'
            ],

            [
                'text' =>
                    '🎟 Redeem Key',
                'callback_data' =>
                    'redeem'
            ]
        ],

        [
            [
                'text' =>
                    '👤 Account',
                'callback_data' =>
                    'account'
            ],

            [
                'text' =>
                    '💬 Support',
                'url' =>
                    'https://t.me/' .
                    ltrim(
                        SUPPORT_USERNAME,
                        '@'
                    )
            ]
        ]

    ];

    if (isAdmin($uid)) {

        $rows[] = [
            [
                'text' =>
                    '🛠 Admin Panel',
                'callback_data' =>
                    'admin'
            ]
        ];
    }

    return kb($rows);
}


/* =========================================================
   PREMIUM
========================================================= */

function premiumText(): string
{
    return
        '<b>⭐ MAYAMUSIC PREMIUM</b>' .
        "\n\n" .

        '<b>₹' .
        MONTHLY_PRICE .
        ' / 30 days</b>' .

        "\n\n" .

        "• 🎵 Full music search\n" .
        "• 🖼 Album artwork\n" .
        "• 🎤 Lyrics\n" .
        "• ⏭ Auto-next queue\n" .
        "• ⏮ Previous / Next\n" .
        "• ▶️ Telegram Mini Player\n" .
        "• 🎟 Redeem-key access\n" .
        "• 👤 Premium account status\n" .
        "• 📱 Smooth Mini App player\n\n" .

        '<b>Payment:</b> <code>' .
        esc(UPI_ID) .
        '</code>' .

        "\n\n" .

        'After payment, submit your UTR/transaction ID for admin approval.';
}


function sendPremium(
    int $uid,
    ?int $chatId = null
): void {

    $chatId =
        $chatId ?? $uid;

    if (isAdmin($uid)) {

        sendMsg(
            $chatId,

            '<b>👑 ADMIN ACCESS</b>' .
            "\n\n" .
            'Your MAYAMUSIC admin account has <b>permanent access</b>.' .
            "\n\n" .
            'No payment or redeem key is required.' .
            "\n\n" .
            'You can use Search, Mini Player, Lyrics, Queue and Download.',

            [
                'reply_markup' =>
                    kb(
                        [
                            [
                                [
                                    'text' =>
                                        '🔎 Search Music',
                                    'callback_data' =>
                                        'search'
                                ],

                                [
                                    'text' =>
                                        '▶️ Mini Player',
                                    'callback_data' =>
                                        'player'
                                ]
                            ],

                            [
                                [
                                    'text' =>
                                        '👤 Account',
                                    'callback_data' =>
                                        'account'
                                ],

                                [
                                    'text' =>
                                        '🏠 Home',
                                    'callback_data' =>
                                        'home'
                                ]
                            ]
                        ]
                    )
            ]
        );

        return;
    }

    $pid =
        'PAY-' .
        date('ymdHis') .
        '-' .
        strtoupper(
            bin2hex(
                random_bytes(2)
            )
        );

    $payments =
        readJson(
            'payments'
        );

    $payments[$pid] = [
        'id' => $pid,
        'user_id' => $uid,
        'amount' =>
            MONTHLY_PRICE,
        'status' =>
            'pending',
        'utr' => '',
        'created_at' =>
            now(),
        'approved_at' =>
            0,
        'expires_at' =>
            0
    ];

    writeJson(
        'payments',
        $payments
    );

    $upi =
        'upi://pay?pa=' .
        rawurlencode(
            UPI_ID
        ) .
        '&pn=' .
        rawurlencode(
            UPI_NAME
        ) .
        '&am=' .
        number_format(
            MONTHLY_PRICE,
            2,
            '.',
            ''
        ) .
        '&cu=INR&tn=' .
        rawurlencode(
            BOT_NAME .
            ' ' .
            $pid
        );

    $buttons = [

        [
            [
                'text' =>
                    '💳 Pay ₹' .
                    MONTHLY_PRICE,
                'url' =>
                    $upi
            ]
        ],

        [
            [
                'text' =>
                    '🧾 Submit UTR',
                'callback_data' =>
                    'utr:' .
                    $pid
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
        $chatId,

        premiumText() .
        "\n\n" .

        '<b>Payment ID:</b> <code>' .
        esc($pid) .
        '</code>',

        [
            'reply_markup' =>
                kb($buttons)
        ]
    );
}


/* =========================================================
   ACCOUNT
========================================================= */

function accountText(
    int $uid
): string {

    $user =
        userRecord($uid);

    $active =
        premiumActive($uid);

    $payments =
        readJson(
            'payments'
        );

    $last = null;

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

            $last =
                $payment;

            break;
        }
    }

    $status =
        $active
        ? '🟢 ACTIVE'
        : '🔴 INACTIVE';

    $extra =
        $active
        ? "\n<b>Valid until:</b> " .
          fmtDate(
              (int)(
                  $user['premium_until']
                  ?? 0
              )
          )
        : '';

    if (isAdmin($uid)) {

        $status =
            '👑 ADMIN';

        $extra =
            "\nFull admin access enabled.";
    }

    return
        '<b>👤 ACCOUNT</b>' .
        "\n\n" .

        '<b>User ID:</b> <code>' .
        $uid .
        '</code>' .

        "\n" .

        '<b>Premium:</b> ' .
        $status .
        $extra .

        "\n" .

        '<b>Last payment:</b> ' .
        esc(
            $last['status']
            ?? 'None'
        );
}


/* =========================================================
   ADMIN PANEL
========================================================= */

function adminPanel(
    int $uid
): void {

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

    foreach (
        $users as $user
    ) {

        if (
            (int)(
                $user['premium_until']
                ?? 0
            ) > now()
        ) {
            $active++;
        }
    }

    $state =
        musicEnabled()
        ? '🟢 ONLINE'
        : '🔴 OFFLINE';

    $text =
        '<b>🛠 MAYAMUSIC ADMIN</b>' .
        "\n\n" .

        'Bot: <b>' .
        $state .
        '</b>' .

        "\n" .

        'Users: <b>' .
        count($users) .
        '</b>' .

        "\n" .

        'Active premium: <b>' .
        $active .
        '</b>' .

        "\n" .

        'Keys: <b>' .
        count($keys) .
        '</b>' .

        "\n" .

        'Payments: <b>' .
        count($payments) .
        '</b>';

    $buttons = [

        [
            [
                'text' =>
                    '🟢 Bot ON',
                'callback_data' =>
                    'bot:on'
            ],

            [
                'text' =>
                    '🔴 Bot OFF',
                'callback_data' =>
                    'bot:off'
            ]
        ],

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
                    '💳 Pending Payments',
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


/* =========================================================
   ADMIN COMMANDS
========================================================= */

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
            $parts[0]
            ?? ''
        );


    if ($cmd === '/admin') {

        adminPanel($uid);

        return true;
    }


    if ($cmd === '/genkey') {

        $days =
            (int)(
                $parts[1]
                ?? 30
            );

        if ($days < 1) {
            $days = 30;
        }

        if ($days > 3650) {
            $days = 3650;
        }

        $key =
            uniqueRedeemKey();

        $keys =
            readJson('keys');

        $keys[$key] = [
            'key' =>
                $key,

            'status' =>
                'unused',

            'created_at' =>
                now(),

            'expires_at' =>
                now() +
                $days *
                86400,

            'created_by' =>
                $uid,

            'redeemed_by' =>
                0,

            'redeemed_at' =>
                0
        ];

        writeJson(
            'keys',
            $keys
        );

        sendMsg(
            $uid,

            '<b>🎟 KEY GENERATED</b>' .
            "\n\n" .

            '<code>' .
            esc($key) .
            '</code>' .

            "\n\n" .

            'Expires: <b>' .
            fmtDate(
                $keys[$key]['expires_at']
            ) .
            '</b>' .

            "\n" .

            'Duration: <b>' .
            $days .
            ' days</b>'
        );

        return true;
    }


    if ($cmd === '/give') {

        $target =
            (int)(
                $parts[1]
                ?? 0
            );

        $days =
            (int)(
                $parts[2]
                ?? 30
            );

        if (
            $target < 1 ||
            $days < 1
        ) {

            sendMsg(
                $uid,
                'Usage: <code>/give USER_ID DAYS</code>'
            );

            return true;
        }

        $base =
            max(
                now(),
                premiumUntil(
                    $target
                )
            );

        $until =
            $base +
            $days *
            86400;

        updateUser(
            $target,
            [
                'premium_until' =>
                    $until
            ]
        );

        sendMsg(
            $uid,
            'Granted <b>' .
            $days .
            ' days</b> to <code>' .
            $target .
            '</code>.'
        );

        sendMsg(
            $target,
            '⭐ <b>Premium activated</b>' .
            "\n" .
            'Valid until: <b>' .
            fmtDate($until) .
            '</b>'
        );

        return true;
    }


    if ($cmd === '/revoke') {

        $target =
            (int)(
                $parts[1]
                ?? 0
            );

        if ($target < 1) {

            sendMsg(
                $uid,
                'Usage: <code>/revoke USER_ID</code>'
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
            'Premium revoked for <code>' .
            $target .
            '</code>.'
        );

        sendMsg(
            $target,
            'Your premium access has been revoked.'
        );

        return true;
    }


    if ($cmd === '/broadcast') {

        $message =
            trim(
                substr(
                    $text,
                    strlen(
                        $parts[0]
                        ?? ''
                    )
                )
            );

        if ($message === '') {

            sendMsg(
                $uid,
                'Usage: <code>/broadcast message</code>'
            );

            return true;
        }

        $users =
            readJson('users');

        $ok = 0;

        foreach (
            $users as $id => $user
        ) {

            $result =
                sendMsg(
                    (int)$id,
                    $message
                );

            if (
                $result['ok']
                ?? false
            ) {
                $ok++;
            }

            usleep(50000);
        }

        sendMsg(
            $uid,
            'Broadcast sent: <b>' .
            $ok .
            '</b>'
        );

        return true;
    }


    if ($cmd === '/on') {

        setMusicEnabled(
            true
        );

        sendMsg(
            $uid,
            '🟢 MAYAMUSIC is ON.'
        );

        return true;
    }


    if ($cmd === '/off') {

        setMusicEnabled(
            false
        );

        sendMsg(
            $uid,
            '🔴 MAYAMUSIC is OFF for normal users. Admin remains enabled.'
        );

        return true;
    }


    if ($cmd === '/stats') {

        adminPanel($uid);

        return true;
    }

    return false;
}


/* =========================================================
   COMMAND PARSER
========================================================= */

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


/* =========================================================
   SEARCH UI
========================================================= */

function showSearch(
    int $uid,
    string $q,
    string $chatType = 'private'
): void {

    if (
        !hasMusicAccess(
            $uid,
            $chatType
        )
    ) {

        accessMessage(
            $uid,
            $chatType
        );

        return;
    }

    if ($q === '') {

        sendMsg(
            $uid,
            'Send a song name, e.g. <code>/search Chandni</code>'
        );

        return;
    }

    $results =
        searchMusic($q);

    if (!$results) {

        sendMsg(
            $uid,
            '❌ No results found.'
        );

        return;
    }

    $results =
        array_slice(
            $results,
            0,
            10
        );

    $searches =
        readJson(
            'searches'
        );

    $sid =
        bin2hex(
            random_bytes(8)
        );

    $searches[$sid] = [
        'user_id' =>
            $uid,

        'results' =>
            $results,

        'expires_at' =>
            now() + 900
    ];

    writeJson(
        'searches',
        $searches
    );

    $rows = [];

    foreach (
        $results as $i => $song
    ) {

        $rows[] = [

            [
                'text' =>
                    '▶️ ' .
                    mb_substr(
                        $song['title'],
                        0,
                        35
                    ),

                'callback_data' =>
                    'pick:' .
                    $sid .
                    ':' .
                    $i
            ]

        ];
    }

    sendMsg(
        $uid,

        '<b>🔎 Results for:</b> ' .
        esc($q) .
        "\n\n" .
        'Choose a song:',

        [
            'reply_markup' =>
                kb($rows)
        ]
    );
}


/* =========================================================
   BOT UPDATE ENTRY
========================================================= */

function startBot(): void
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

    if (!is_array($update)) {
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

    if (
        isset(
            $update['pre_checkout_query']
        )
    ) {

        tg(
            'answerPreCheckoutQuery',
            [
                'pre_checkout_query_id' =>
                    $update[
                        'pre_checkout_query'
                    ]['id'],

                'ok' => 'false',

                'error_message' =>
                    'Stars payments are not used. Please use UPI Premium.'
            ]
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
    }
}


/* =========================================================
   MESSAGE HANDLER
========================================================= */

function handleMessage(
    array $message
): void {

    $chat =
        $message['chat']
        ?? [];

    $uid =
        (int)(
            $message['from']['id']
            ?? 0
        );

    if (!$uid) {
        return;
    }

    $type =
        $chat['type']
        ?? 'private';

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
                    $message['from']
                    ['username']
                    ?? ''
                ),

            'first_name' =>
                (string)(
                    $message['from']
                    ['first_name']
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
        $cmd,
        $arg
    ] =
        parseCommand($text);


    /* START */

    if ($cmd === '/start') {

        if ($type !== 'private') {

            sendMsg(
                $chat['id'],
                '🎵 <b>' .
                BOT_NAME .
                '</b> is active here.' .
                "\n\n" .
                'This chat has FREE channel/group access.'
            );

            return;
        }

        $verifyUrl =
            rtrim(
                WEBAPP_URL,
                '/'
            ) .
            '?action=verify';

        $buttons = [

            [
                [
                    'text' =>
                        '🚀 OPEN MAYAMUSIC',
                    'web_app' => [
                        'url' =>
                            $verifyUrl
                    ]
                ]
            ],

            [
                [
                    'text' =>
                        '⭐ Premium',
                    'callback_data' =>
                        'premium'
                ],

                [
                    'text' =>
                        '👤 Account',
                    'callback_data' =>
                        'account'
                ]
            ]

        ];

        if (
            PROFILE_IMAGE_URL !== ''
        ) {

            sendPhoto(
                $uid,
                PROFILE_IMAGE_URL,

                '<b>🎵 MAYAMUSIC</b>' .
                "\n\n" .
                'Welcome! First complete the anti-bot verification, then use the Mini App player.',

                [
                    'reply_markup' =>
                        kb($buttons)
                ]
            );

        } else {

            sendMsg(
                $uid,

                '<b>🎵 MAYAMUSIC</b>' .
                "\n\n" .
                'Welcome! First complete the anti-bot verification, then use the Mini App player.',

                [
                    'reply_markup' =>
                        kb($buttons)
                ]
            );
        }

        return;
    }


    /* SEARCH */

    if (
        $cmd === '/search' ||
        $cmd === '/play'
    ) {

        showSearch(
            $uid,
            $arg,
            $type
        );

        return;
    }


    /* PREMIUM */

    if ($cmd === '/premium') {

        if (
            $type !== 'private'
        ) {

            sendMsg(
                $chat['id'],
                'This chat is FREE. Premium is for private users.'
            );

        } else {

            sendPremium($uid);
        }

        return;
    }


    /* UTR */

    if ($cmd === '/utr') {

        handleUtrCommand(
            $uid,
            $arg
        );

        return;
    }


    /* ACCOUNT */

    if ($cmd === '/account') {

        sendMsg(
            $uid,
            accountText($uid),
            [
                'reply_markup' =>
                    kb(
                        [
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
                        ]
                    )
            ]
        );

        return;
    }


    /* REDEEM */

    if ($cmd === '/redeem') {

        redeemKey(
            $uid,
            $arg
        );

        return;
    }


    /* VOICE CHAT COMMAND HOOKS */

    if (
        $cmd === '/playcc' ||
        $cmd === '/pausecc' ||
        $cmd === '/resumecc' ||
        $cmd === '/skipcc' ||
        $cmd === '/stopcc' ||
        $cmd === '/leavecc'
    ) {

        if (
            $type === 'private'
        ) {

            sendMsg(
                $uid,
                'Use this command in a group/channel chat.'
            );

            return;
        }

        if (
            !isAdmin($uid)
        ) {

            sendMsg(
                $chat['id'],
                'Only the bot owner/admin can control VC playback.'
            );

            return;
        }

        channelCommand(
            $chat,
            $cmd,
            $arg
        );

        return;
    }


    if (
        $type !== 'private' &&
        $text !== ''
    ) {
        return;
    }

    if ($text !== '') {

        showSearch(
            $uid,
            $text,
            $type
        );
    }
}


/* =========================================================
   REDEEM
========================================================= */

function redeemKey(
    int $uid,
    string $key
): void {

    $key =
        strtoupper(
            trim($key)
        );

    if ($key === '') {

        sendMsg(
            $uid,
            '🎟 <b>Redeem Key</b>' .
            "\n\n" .
            'Use <code>/redeem MAYA-XXXXXX-XXXXXX-XXXXXX</code>'
        );

        return;
    }

    $keys =
        readJson('keys');

    if (
        !isset(
            $keys[$key]
        )
    ) {

        sendMsg(
            $uid,
            '❌ Invalid redeem key.'
        );

        return;
    }

    $item =
        $keys[$key];

    if (
        ($item['status'] ?? '')
        !==
        'unused'
    ) {

        sendMsg(
            $uid,
            '❌ This key has already been used.'
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
            '❌ This key has expired.'
        );

        return;
    }

    $grantUntil =
        (int)(
            $item['expires_at']
        );

    $current =
        premiumUntil($uid);

    $until =
        max(
            $current,
            $grantUntil
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

        '✅ <b>Key redeemed successfully!</b>' .
        "\n\n" .
        '⭐ Premium active until:' .
        "\n<b>" .
        fmtDate($until) .
        '</b>",

        [
            'reply_markup' =>
                kb(
                    [
                        [
                            [
                                'text' =>
                                    '👤 Account',
                                'callback_data' =>
                                    'account'
                            ],

                            [
                                'text' =>
                                    '▶️ Mini Player',
                                'callback_data' =>
                                    'player'
                            ]
                        ]
                    ]
                )
        ]
    );
}


/* =========================================================
   CHANNEL / VC CONTROL
========================================================= */

function channelCommand(
    array $chat,
    string $cmd,
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
            'chat_id' =>
                $chatId,

            'title' =>
                (string)(
                    $chat['title']
                    ?? ''
                ),

            'created_at' =>
                now(),

            'current' =>
                null,

            'queue' =>
                [],

            'status' =>
                'idle'
        ];
    }


    if (
        $cmd === '/playcc'
    ) {

        if ($arg === '') {

            sendMsg(
                $chatId,
                'Usage: <code>/playcc song name</code>'
            );

            return;
        }

        $results =
            searchMusic($arg);

        if (!$results) {

            sendMsg(
                $chatId,
                '❌ No song found.'
            );

            return;
        }

        /*
         * API does not provide rating.
         * Therefore first API result is used.
         */

        $song =
            $results[0];

        $channels[$key]['current'] =
            $song;

        $channels[$key]['status'] =
            'requested';

        writeJson(
            'channels',
            $channels
        );

        sendMsg(
            $chatId,

            '▶️ <b>VC PLAY REQUEST</b>' .
            "\n\n" .

            '<b>' .
            esc(
                $song['title']
            ) .
            '</b>' .

            "\n" .

            esc(
                $song['artists']
            ) .

            "\n\n" .

            'PHP Telegram Bot API cannot itself join/stream Telegram Voice Chats.' .
            "\n" .
            'Connect a separate MTProto/voice engine for actual VC audio.'
        );

        return;
    }


    if (
        $cmd === '/pausecc' ||
        $cmd === '/resumecc' ||
        $cmd === '/skipcc' ||
        $cmd === '/stopcc' ||
        $cmd === '/leavecc'
    ) {

        $channels[$key]['status'] =
            ltrim(
                $cmd,
                '/'
            );

        writeJson(
            'channels',
            $channels
        );

        sendMsg(
            $chatId,

            '🎛 <b>' .
            strtoupper(
                ltrim(
                    $cmd,
                    '/'
                )
            ) .
            '</b>' .
            "\n\n" .
            'Command recorded.'
        );
    }
}


/* =========================================================
   CALLBACK HANDLER
========================================================= */

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

    $message =
        $callback['message']
        ?? [];

    userRecord($uid);


    if ($data === 'home') {

        answerCb($id);

        sendMsg(
            $uid,
            '<b>🎵 MAYAMUSIC</b>',
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


    if ($data === 'redeem') {

        answerCb($id);

        sendMsg(
            $uid,
            '🎟 Send your key using <code>/redeem MAYA-XXXXXX-XXXXXX-XXXXXX</code>'
        );

        return;
    }


    if ($data === 'search') {

        answerCb($id);

        sendMsg(
            $uid,
            '🔎 Send <code>/search song name</code>'
        );

        return;
    }


    if ($data === 'player') {

        answerCb($id);

        $chatType =
            (string)(
                $message['chat']['type']
                ?? 'private'
            );

        if (
            !hasMusicAccess(
                $uid,
                $chatType
            )
        ) {

            sendMsg(
                $uid,
                '🔒 Premium required.'
            );

            return;
        }

        sendMsg(
            $uid,

            '▶️ <b>Mini Player</b>' .
            "\n\n" .
            'Search a song first, then open its Mini Player.',

            [
                'reply_markup' =>
                    kb(
                        [
                            [
                                [
                                    'text' =>
                                        '🔎 Search',
                                    'callback_data' =>
                                        'search'
                                ]
                            ]
                        ]
                    )
            ]
        );

        return;
    }


    if ($data === 'admin') {

        answerCb($id);

        if (
            isAdmin($uid)
        ) {

            adminPanel($uid);

        } else {

            sendMsg(
                $uid,
                'Access denied.'
            );
        }

        return;
    }


    if (
        str_starts_with(
            $data,
            'bot:'
        )
    ) {

        if (
            !isAdmin($uid)
        ) {
            return;
        }

        setMusicEnabled(
            substr(
                $data,
                4
            ) === 'on'
        );

        answerCb($id);

        adminPanel($uid);

        return;
    }


    if ($data === 'akey') {

        answerCb($id);

        if (
            !isAdmin($uid)
        ) {
            return;
        }

        generateAdminKey(
            $uid,
            30
        );

        return;
    }


    if ($data === 'akeys') {

        answerCb($id);

        if (
            !isAdmin($uid)
        ) {
            return;
        }

        adminKeys($uid);

        return;
    }


    if ($data === 'apays') {

        answerCb($id);

        if (
            !isAdmin($uid)
        ) {
            return;
        }

        adminPayments($uid);

        return;
    }


    if ($data === 'ausers') {

        answerCb($id);

        if (
            !isAdmin($uid)
        ) {
            return;
        }

        adminUsers($uid);

        return;
    }


    if ($data === 'astats') {

        answerCb($id);

        if (
            !isAdmin($uid)
        ) {
            return;
        }

        adminStats($uid);

        return;
    }


    /* SONG PICK */

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

        $sid =
            $parts[1]
            ?? '';

        $index =
            (int)(
                $parts[2]
                ?? -1
            );

        $searches =
            readJson(
                'searches'
            );

        $entry =
            $searches[$sid]
            ?? null;

        if (
            !$entry ||
            (int)(
                $entry['user_id']
                ?? 0
            ) !== $uid ||
            (int)(
                $entry['expires_at']
                ?? 0
            ) < now()
        ) {

            sendMsg(
                $uid,
                '❌ Search session expired. Search again.'
            );

            return;
        }

        $queue =
            $entry['results']
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
                '❌ Invalid song selection.'
            );

            return;
        }

        $chatType =
            (string)(
                $message['chat']['type']
                ?? 'private'
            );

        if (
            !hasMusicAccess(
                $uid,
                $chatType
            )
        ) {

            sendMsg(
                $uid,
                '🔒 Premium required.'
            );

            return;
        }

        $token =
            playerToken(
                $song,
                $queue
            );

        $art =
            artwork(
                $song['title'],
                $song['artists']
            );

        $caption =
            '<b>▶️ ' .
            esc(
                $song['title']
            ) .
            '</b>' .

            "\n" .

            esc(
                $song['artists']
            ) .

            "\n" .

            (
                $song['duration']
                ? esc(
                    $song['duration']
                )
                : ''
            );

        $buttons = [

            [
                [
                    'text' =>
                        '🎧 Open Mini Player',

                    'web_app' => [
                        'url' =>
                            webAppUrl(
                                $token
                            )
                    ]
                ],

                [
                    'text' =>
                        '⬇️ Download',

                    'url' =>
                        downloadUrl(
                            $token
                        )
                ]
            ]

        ];

        if ($art !== '') {

            tg(
                'sendPhoto',
                [
                    'chat_id' =>
                        $uid,

                    'photo' =>
                        $art,

                    'caption' =>
                        $caption,

                    'parse_mode' =>
                        'HTML',

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


    /* UTR */

    if (
        str_starts_with(
            $data,
            'utr:'
        )
    ) {

        answerCb($id);

        $pid =
            substr(
                $data,
                4
            );

        $payments =
            readJson(
                'payments'
            );

        if (
            !isset(
                $payments[$pid]
            )
        ) {

            sendMsg(
                $uid,
                'Payment not found.'
            );

            return;
        }

        sendMsg(
            $uid,

            '🧾 <b>Submit UTR</b>' .
            "\n\n" .

            'Payment ID: <code>' .
            esc($pid) .
            '</code>' .

            "\n" .

            'Send:' .
            "\n" .

            '<code>/utr ' .
            esc($pid) .
            ' YOUR_UTR</code>'
        );

        return;
    }


    /* APPROVE / DECLINE */

    if (
        str_starts_with(
            $data,
            'approve:'
        ) ||
        str_starts_with(
            $data,
            'decline:'
        )
    ) {

        if (
            !isAdmin($uid)
        ) {

            answerCb(
                $id,
                'Access denied',
                true
            );

            return;
        }

        answerCb($id);

        $approve =
            str_starts_with(
                $data,
                'approve:'
            );

        $pid =
            substr(
                $data,
                $approve
                ? 8
                : 7
            );

        processPaymentDecision(
            $uid,
            $pid,
            $approve
        );

        return;
    }
}


/* =========================================================
   ADMIN KEY
========================================================= */

function generateAdminKey(
    int $uid,
    int $days
): void {

    $key =
        uniqueRedeemKey();

    $keys =
        readJson('keys');

    $keys[$key] = [
        'key' =>
            $key,

        'status' =>
            'unused',

        'created_at' =>
            now(),

        'expires_at' =>
            now() +
            $days *
            86400,

        'created_by' =>
            $uid,

        'redeemed_by' =>
            0,

        'redeemed_at' =>
            0
    ];

    writeJson(
        'keys',
        $keys
    );

    sendMsg(
        $uid,

        '<b>🎟 NEW REDEEM KEY</b>' .
        "\n\n" .

        '<code>' .
        esc($key) .
        '</code>' .

        "\n\n" .

        'Duration: <b>' .
        $days .
        ' days</b>' .

        "\n" .

        'Expires: <b>' .
        fmtDate(
            $keys[$key]['expires_at']
        ) .
        '</b>'
    );
}


/* =========================================================
   ADMIN KEYS
========================================================= */

function adminKeys(
    int $uid
): void {

    $keys =
        readJson('keys');

    $text =
        '<b>🎟 REDEEM KEYS</b>' .
        "\n\n";

    if (!$keys) {

        $text .=
            'No keys yet.';

    } else {

        foreach (
            array_slice(
                array_reverse(
                    $keys,
                    true
                ),
                0,
                15,
                true
            ) as $key => $value
        ) {

            $text .=
                '<code>' .
                esc($key) .
                '</code> — ' .
                esc(
                    $value['status']
                    ?? ''
                ) .
                ' — ' .
                fmtDate(
                    (int)(
                        $value['expires_at']
                        ?? 0
                    )
                ) .
                "\n";
        }
    }

    sendMsg(
        $uid,
        $text,
        [
            'reply_markup' =>
                kb(
                    [
                        [
                            [
                                'text' =>
                                    '🎟 Generate 30D Key',
                                'callback_data' =>
                                    'akey'
                            ],

                            [
                                'text' =>
                                    '⬅️ Admin',
                                'callback_data' =>
                                    'admin'
                            ]
                        ]
                    ]
                )
        ]
    );
}


/* =========================================================
   ADMIN PAYMENTS
========================================================= */

function adminPayments(
    int $uid
): void {

    $payments =
        readJson(
            'payments'
        );

    $text =
        '<b>💳 PAYMENTS</b>' .
        "\n\n";

    $buttons = [];

    $count = 0;

    foreach (
        array_reverse(
            $payments,
            true
        ) as $id => $value
    ) {

        if (
            ($value['status'] ?? '')
            !==
            'pending'
        ) {
            continue;
        }

        $count++;

        $text .=
            '<code>' .
            esc($id) .
            '</code> — User <code>' .
            (int)(
                $value['user_id']
                ?? 0
            ) .
            '</code> — ₹' .
            (int)(
                $value['amount']
                ?? 0
            ) .
            ' — UTR: ' .
            esc(
                (string)(
                    $value['utr']
                    ?? 'Not submitted'
                )
            ) .
            "\n";

        $buttons[] = [

            [
                'text' =>
                    '✅ ' . $id,

                'callback_data' =>
                    'approve:' .
                    $id
            ],

            [
                'text' =>
                    '❌',

                'callback_data' =>
                    'decline:' .
                    $id
            ]

        ];

        if ($count >= 10) {
            break;
        }
    }

    if ($count === 0) {

        $text .=
            'No pending payments.';
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


/* =========================================================
   ADMIN USERS
========================================================= */

function adminUsers(
    int $uid
): void {

    $users =
        readJson('users');

    $text =
        '<b>👥 USERS</b>' .
        "\n\n";

    foreach (
        array_slice(
            array_reverse(
                $users,
                true
            ),
            0,
            20,
            true
        ) as $id => $user
    ) {

        $text .=
            '<code>' .
            (int)$id .
            '</code> — ' .

            (
                (int)(
                    $user['premium_until']
                    ?? 0
                ) > now()
                ? '🟢'
                : '🔴'
            ) .

            ' — ' .

            fmtDate(
                (int)(
                    $user['premium_until']
                    ?? 0
                )
            ) .

            "\n";
    }

    sendMsg(
        $uid,
        $text,
        [
            'reply_markup' =>
                kb(
                    [
                        [
                            [
                                'text' =>
                                    '⬅️ Admin',
                                'callback_data' =>
                                    'admin'
                            ]
                        ]
                    ]
                )
        ]
    );
}


/* =========================================================
   ADMIN STATS
========================================================= */

function adminStats(
    int $uid
): void {

    $users =
        readJson('users');

    $payments =
        readJson('payments');

    $keys =
        readJson('keys');

    $approved = 0;

    $revenue = 0;

    foreach (
        $payments as $payment
    ) {

        if (
            ($payment['status'] ?? '')
            ===
            'approved'
        ) {

            $approved++;

            $revenue +=
                (int)(
                    $payment['amount']
                    ?? 0
                );
        }
    }

    sendMsg(
        $uid,

        '<b>📊 STATISTICS</b>' .
        "\n\n" .

        'Users: <b>' .
        count($users) .
        '</b>' .

        "\n" .

        'Keys: <b>' .
        count($keys) .
        '</b>' .

        "\n" .

        'Approved payments: <b>' .
        $approved .
        '</b>' .

        "\n" .

        'Recorded revenue: <b>₹' .
        $revenue .
        '</b>',

        [
            'reply_markup' =>
                kb(
                    [
                        [
                            [
                                'text' =>
                                    '⬅️ Admin',
                                'callback_data' =>
                                    'admin'
                            ]
                        ]
                    ]
                )
        ]
    );
}


/* =========================================================
   PAYMENT DECISION
========================================================= */

function processPaymentDecision(
    int $admin,
    string $pid,
    bool $approve
): void {

    $payments =
        readJson(
            'payments'
        );

    if (
        !isset(
            $payments[$pid]
        )
    ) {

        sendMsg(
            $admin,
            'Payment not found.'
        );

        return;
    }

    $payment =
        $payments[$pid];

    if (
        ($payment['status'] ?? '')
        !==
        'pending'
    ) {

        sendMsg(
            $admin,
            'Already processed.'
        );

        return;
    }

    $uid =
        (int)(
            $payment['user_id']
            ?? 0
        );


    if ($approve) {

        $base =
            max(
                now(),
                premiumUntil($uid)
            );

        $until =
            $base +
            ACCESS_DAYS *
            86400;

        $payment['status'] =
            'approved';

        $payment['approved_at'] =
            now();

        $payment['expires_at'] =
            $until;

        $payments[$pid] =
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

            '✅ <b>Payment approved!</b>' .
            "\n\n" .

            '⭐ Premium active until:' .
            "\n<b>" .
            fmtDate($until) .
            '</b>'
        );

        sendMsg(
            $admin,

            '✅ Approved <code>' .
            esc($pid) .
            '</code> for <code>' .
            $uid .
            '</code>.'
        );

    } else {

        $payment['status'] =
            'declined';

        $payments[$pid] =
            $payment;

        writeJson(
            'payments',
            $payments
        );

        sendMsg(
            $uid,
            '❌ Your payment request was declined. Please contact support.'
        );

        sendMsg(
            $admin,
            '❌ Declined <code>' .
            esc($pid) .
            '</code>.'
        );
    }
}


/* =========================================================
   UTR
========================================================= */

function submitUtr(
    int $uid,
    string $pid,
    string $utr
): void {

    $payments =
        readJson(
            'payments'
        );

    if (
        !isset(
            $payments[$pid]
        )
    ) {

        sendMsg(
            $uid,
            'Payment ID not found.'
        );

        return;
    }

    if (
        (int)(
            $payments[$pid]['user_id']
            ?? 0
        ) !== $uid
    ) {

        sendMsg(
            $uid,
            'Access denied.'
        );

        return;
    }

    $utr =
        trim($utr);

    if (
        $utr === '' ||
        strlen($utr) > 100
    ) {

        sendMsg(
            $uid,
            'Invalid UTR.'
        );

        return;
    }

    $payments[$pid]['utr'] =
        $utr;

    $payments[$pid]['status'] =
        'pending';

    $payments[$pid]['utr_submitted_at'] =
        now();

    writeJson(
        'payments',
        $payments
    );

    sendMsg(
        $uid,

        '🧾 <b>UTR submitted.</b>' .
        "\n\n" .
        'Admin will review your payment.'
    );

    sendMsg(
        ADMIN_ID,

        '💳 <b>New UTR submitted</b>' .
        "\n\n" .

        'Payment: <code>' .
        esc($pid) .
        '</code>' .

        "\n" .

        'User: <code>' .
        $uid .
        '</code>' .

        "\n" .

        'UTR: <code>' .
        esc($utr) .
        '</code>',

        [
            'reply_markup' =>
                kb(
                    [
                        [
                            [
                                'text' =>
                                    '✅ Approve',
                                'callback_data' =>
                                    'approve:' .
                                    $pid
                            ],

                            [
                                'text' =>
                                    '❌ Decline',
                                'callback_data' =>
                                    'decline:' .
                                    $pid
                            ]
                        ]
                    ]
                )
        ]
    );
}


function handleUtrCommand(
    int $uid,
    string $arg
): void {

    $parts =
        preg_split(
            '/\s+/',
            trim($arg),
            2
        );

    $pid =
        $parts[0]
        ?? '';

    $utr =
        $parts[1]
        ?? '';

    if (
        $pid === '' ||
        $utr === ''
    ) {

        sendMsg(
            $uid,
            'Usage: <code>/utr PAYMENT_ID UTR</code>'
        );

        return;
    }

    submitUtr(
        $uid,
        $pid,
        $utr
    );
}


/* =========================================================
   MINI APP
========================================================= */

function miniApp(): void
{
    $mode =
        (string)(
            $_GET['mode']
            ?? 'player'
        );

    header(
        'Content-Type: text/html; charset=UTF-8'
    );


    /* VERIFICATION SUCCESS */

    if ($mode === 'home') {

        echo '
<!doctype html>
<html>

<head>

<meta charset="utf-8">

<meta
name="viewport"
content="width=device-width,initial-scale=1"
>

<title>MAYAMUSIC</title>

<style>

body{
margin:0;
background:#07090e;
color:#fff;
font-family:system-ui;
display:grid;
place-items:center;
min-height:100vh;
}

.box{
width:min(90%,420px);
padding:30px;
border-radius:28px;
background:#121722;
text-align:center;
box-shadow:0 25px 80px #000;
}

.ok{
font-size:56px;
}

.btn{
margin-top:18px;
padding:14px 24px;
border:0;
border-radius:14px;
font-weight:800;
}

</style>

</head>

<body>

<div class="box">

<div class="ok">✓</div>

<h2>
Verification complete
</h2>

<p style="opacity:.65">
MAYAMUSIC is unlocked for this Telegram session.
</p>

<button
class="btn"
onclick="window.Telegram?.WebApp?.close()"
>
OPEN BOT
</button>

</div>

<script
src="https://telegram.org/js/telegram-web-app.js"
></script>

<script>

const tg =
window.Telegram?.WebApp;

tg?.ready();

tg?.expand();

setTimeout(
()=>{
tg?.close();
},
900
);

</script>

</body>

</html>
';

        return;
    }


    if ($mode === 'verify') {

        verifyPage();

        return;
    }


    $token =
        (string)(
            $_GET['token']
            ?? ''
        );

    $players =
        readJson('players');

    $player =
        $players[$token]
        ?? null;

    if (!$player) {

        http_response_code(404);

        echo '
<h2
style="
font-family:system-ui;
text-align:center;
padding:50px;
"
>
Player expired. Search again.
</h2>
';

        return;
    }

    $song =
        $player['song'];

    $queue =
        $player['queue'];

    $title =
        json_encode(
            $song['title'],
            JSON_UNESCAPED_UNICODE
        );

    $artist =
        json_encode(
            $song['artists'],
            JSON_UNESCAPED_UNICODE
        );

    $audio =
        json_encode(
            $song['download_url'],
            JSON_UNESCAPED_SLASHES
        );

    $art =
        json_encode(
            artwork(
                $song['title'],
                $song['artists']
            ),
            JSON_UNESCAPED_SLASHES
        );

    $queueJson =
        json_encode(
            $queue,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

    echo '
<!doctype html>

<html>

<head>

<meta charset="utf-8">

<meta
name="viewport"
content="width=device-width,initial-scale=1,viewport-fit=cover"
>

<title>MAYAMUSIC</title>

<style>

body{
margin:0;
background:#090b10;
color:#fff;
font-family:Inter,system-ui,sans-serif;
}

.wrap{
min-height:100vh;
padding:22px;
box-sizing:border-box;
background:
radial-gradient(
circle at top,
#22283a,
#090b10 55%
);
}

.art{
width:min(82vw,340px);
aspect-ratio:1;
border-radius:28px;
object-fit:cover;
display:block;
margin:20px auto;
box-shadow:0 20px 60px #0008;
background:#1b1e28;
}

.title{
text-align:center;
font-size:23px;
font-weight:800;
}

.artist{
text-align:center;
opacity:.7;
margin-top:6px;
}

.bar{
height:5px;
background:#2a2e39;
border-radius:99px;
margin:24px 0;
}

.fill{
height:100%;
width:0;
background:#fff;
border-radius:99px;
}

.time{
display:flex;
justify-content:space-between;
font-size:12px;
opacity:.55;
}

.controls{
display:flex;
justify-content:center;
gap:16px;
margin:22px 0;
}

.btn{
border:0;
border-radius:50%;
width:58px;
height:58px;
background:#1d212b;
color:#fff;
font-size:21px;
}

.play{
width:72px;
height:72px;
background:#fff;
color:#000;
}

.lyrics{
margin-top:24px;
max-height:32vh;
overflow:auto;
text-align:center;
white-space:pre-wrap;
line-height:1.8;
opacity:.9;
}

.row{
display:flex;
gap:10px;
}

.small{
flex:1;
border:0;
border-radius:14px;
padding:13px;
background:#171b24;
color:#fff;
}

.status{
text-align:center;
opacity:.55;
font-size:12px;
margin-top:12px;
}

.enh{
margin-top:14px;
padding:14px;
border-radius:16px;
background:#171b24;
}

.enh input{
width:100%;
}

</style>

</head>

<body>

<main class="wrap">

<img
id="art"
class="art"
>

<div
id="title"
class="title"
></div>

<div
id="artist"
class="artist"
></div>

<div class="bar">

<div
id="fill"
class="fill"
></div>

</div>

<div class="time">

<span id="cur">
0:00
</span>

<span id="dur">
0:00
</span>

</div>

<div class="controls">

<button
class="btn"
id="prev"
>
⏮
</button>

<button
class="btn play"
id="play"
>
▶
</button>

<button
class="btn"
id="next"
>
⏭
</button>

</div>

<div class="row">

<button
class="small"
id="lyricsBtn"
>
🎤 Lyrics
</button>

<button
class="small"
id="queueBtn"
>
☰ Queue
</button>

<button
class="small"
id="downloadBtn"
>
⬇️ Download
</button>

</div>

<div class="enh">

<b>
🎛 Audio Enhancement
</b>

<br>

<small style="opacity:.65">
Device/browser dependent; this is not Dolby certification.
</small>

<input
id="gain"
type="range"
min="0.7"
max="1.4"
step="0.01"
value="1"
>

</div>

<div
id="lyrics"
class="lyrics"
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

</main>


<script
src="https://telegram.org/js/telegram-web-app.js"
></script>

<script>

const tg =
window.Telegram?.WebApp;

tg?.ready();

tg?.expand();


let song =
' . $audio . ';

let title =
' . $title . ';

let artist =
' . $artist . ';

let art =
' . $art . ';

let q =
' . $queueJson . ';

let i = 0;

let ctx = null;

let gain = null;


const audio =
document.getElementById("audio");

const play =
document.getElementById("play");

const fill =
document.getElementById("fill");

const cur =
document.getElementById("cur");

const dur =
document.getElementById("dur");

const image =
document.getElementById("art");

const titleEl =
document.getElementById("title");

const artistEl =
document.getElementById("artist");

const lyricsEl =
document.getElementById("lyrics");

const statusEl =
document.getElementById("status");


function timeText(seconds){

seconds =
Math.floor(
seconds || 0
);

return (
Math.floor(
seconds / 60
)
+
":"
+
String(
seconds % 60
).padStart(
2,
"0"
)
);

}


function graph(){

if(ctx){
return;
}

try{

ctx =
new AudioContext();

const source =
ctx.createMediaElementSource(
audio
);

gain =
ctx.createGain();

source
.connect(gain)
.connect(
ctx.destination
);

}catch(error){

ctx = null;

}

}


function start(){

graph();

try{

ctx?.resume();

}catch(error){}

audio
.play()
.then(
()=>{
play.textContent =
"⏸";
}
)
.catch(
()=>{
statusEl.textContent =
"Tap Play again to start playback";
}
);

}


function loadSong(
songData,
autoplay=false
){

song =
songData.download_url;

title =
songData.title;

artist =
songData.artists;

titleEl.textContent =
title;

artistEl.textContent =
artist;

image.src =
songData.artwork ||
art ||
"";

audio.src =
song;

audio.load();

lyricsEl.textContent =
"Loading lyrics…";

fetch(
location.pathname +
"?action=lyrics" +
"&title=" +
encodeURIComponent(
title
) +
"&artist=" +
encodeURIComponent(
artist
)
)
.then(
r=>r.json()
)
.then(
data=>{

lyricsEl.textContent =
data.synced ||
data.plain ||
"Lyrics not found";

}
)
.catch(
()=>{
lyricsEl.textContent =
"Lyrics unavailable";
}
);

if(autoplay){
start();
}

}


play.onclick=()=>{

if(audio.paused){

start();

}else{

audio.pause();

play.textContent =
"▶";

}

};


document
.getElementById("next")
.onclick=()=>{

if(
i + 1 <
q.length
){

i++;

loadSong(
q[i],
true
);

}

};


document
.getElementById("prev")
.onclick=()=>{

if(
audio.currentTime > 5
){

audio.currentTime =
0;

return;
}

if(i > 0){

i--;

loadSong(
q[i],
true
);

}

};


audio.ontimeupdate=()=>{

cur.textContent =
timeText(
audio.currentTime
);

fill.style.width =
(
audio.duration
?
audio.currentTime /
audio.duration *
100
:
0
) +
"%";

};


audio.onloadedmetadata=()=>{

dur.textContent =
timeText(
audio.duration
);

};


audio.onended=()=>{

if(
i + 1 <
q.length
){

i++;

loadSong(
q[i],
true
);

}else{

play.textContent =
"▶";

statusEl.textContent =
"Queue finished";

}

};


document
.getElementById(
"lyricsBtn"
)
.onclick=()=>{

lyricsEl.scrollIntoView({
behavior:"smooth"
});

};


document
.getElementById(
"queueBtn"
)
.onclick=()=>{

alert(
q.map(
(songItem,index)=>
(
index + 1
) +
". " +
songItem.title
)
.join(
"\n"
)
);

};


document
.getElementById(
"downloadBtn"
)
.onclick=()=>{

const token =
new URLSearchParams(
location.search
).get(
"token"
) || "";

window.open(
location.pathname +
"?action=download&token=" +
encodeURIComponent(
token
),
"_blank"
);

};


document
.getElementById(
"gain"
)
.oninput=(event)=>{

if(gain){

gain.gain.value =
parseFloat(
event.target.value
);

}

};


image.src =
art ||
"";


if(q.length){

loadSong(
q[0]
);

}

</script>

</body>

</html>
';

}


/* =========================================================
   DOWNLOAD
========================================================= */

function downloadSong(): void
{
    $token =
        (string)(
            $_GET['token']
            ?? ''
        );

    $players =
        readJson(
            'players'
        );

    $player =
        $players[$token]
        ?? null;

    if (
        !$player ||
        (int)(
            $player['expires_at']
            ?? 0
        ) < now()
    ) {

        http_response_code(404);

        header(
            'Content-Type: text/plain; charset=UTF-8'
        );

        echo
            'Download link expired. Please search and create a new player link.';

        return;
    }

    $song =
        $player['song']
        ?? [];

    $source =
        (string)(
            $song['download_url']
            ?? ''
        );

    if (
        $source === '' ||
        !preg_match(
            '~^https?://~i',
            $source
        )
    ) {

        http_response_code(400);

        header(
            'Content-Type: text/plain; charset=UTF-8'
        );

        echo
            'Invalid download source.';

        return;
    }

    $safeTitle =
        preg_replace(
            '/[^A-Za-z0-9 _-]+/',
            '',
            (string)(
                $song['title']
                ?? 'MAYAMUSIC'
            )
        );

    if (!$safeTitle) {
        $safeTitle =
            'MAYAMUSIC';
    }

    $filename =
        trim(
            $safeTitle
        ) .
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
        curl_init(
            $source
        );

    curl_setopt_array(
        $ch,
        [
            CURLOPT_FOLLOWLOCATION =>
                true,

            CURLOPT_RETURNTRANSFER =>
                false,

            CURLOPT_CONNECTTIMEOUT =>
                10,

            CURLOPT_TIMEOUT =>
                0,

            CURLOPT_USERAGENT =>
                'MAYAMUSIC/1.0',

            CURLOPT_BUFFERSIZE =>
                65536,

            CURLOPT_WRITEFUNCTION =>
                function (
                    $ch,
                    $data
                ) {

                    echo $data;

                    return strlen(
                        $data
                    );
                }
        ]
    );

    curl_exec($ch);

    curl_close($ch);
}


/* =========================================================
   MINI API
========================================================= */

function apiMini(): void
{
    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    $action =
        (string)(
            $_GET['action']
            ?? ''
        );

    if (
        $action === 'lyrics'
    ) {

        echo json_encode(
            lyrics(
                (string)(
                    $_GET['title']
                    ?? ''
                ),

                (string)(
                    $_GET['artist']
                    ?? ''
                )
            ),
            JSON_UNESCAPED_UNICODE
        );

        return;
    }

    echo json_encode(
        [
            'ok' => false,
            'error' =>
                'unknown action'
        ]
    );
}


/* =========================================================
   HTTP ROUTER
========================================================= */

function handleHttp(): bool
{
    if (
        ($_GET['action'] ?? '')
        === 'verify' &&
        ($_SERVER['REQUEST_METHOD']
            ?? 'GET')
        === 'POST'
    ) {

        verificationApi();

        return true;
    }


    if (
        ($_GET['action'] ?? '')
        === 'verify'
    ) {

        verifyPage();

        return true;
    }


    if (
        isset(
            $_GET['mini']
        )
    ) {

        miniApp();

        return true;
    }


    if (
        ($_GET['action'] ?? '')
        === 'download'
    ) {

        downloadSong();

        return true;
    }


    if (
        isset(
            $_GET['action']
        )
    ) {

        apiMini();

        return true;
    }


    return false;
}


/* =========================================================
   WEBHOOK
========================================================= */

function setWebhook(): void
{
    if (
        BOT_TOKEN !==
        'PASTE_NEW_BOT_TOKEN_HERE' &&
        WEBAPP_URL !==
        'https://YOUR-DOMAIN.example/index.php'
    ) {

        tg(
            'setWebhook',
            [
                'url' =>
                    WEBAPP_URL
            ]
        );
    }
}


/* =========================================================
   ENTRY
========================================================= */

if (
    handleHttp()
) {
    exit;
}


if (
    php_sapi_name() !==
    'cli'
) {

    startBot();
}

?>
