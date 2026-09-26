<?php
declare(strict_types=1);

/*
=========================================================
                    MAYAMUSIC
              Telegram Music Bot
=========================================================

FEATURES
- Music API Search
- Correct API fields:
    title
    artists
    album
    duration
    download_url

- Telegram Stars Premium Access
- Telegram Mini App Music Player
- Direct audio playback
- Play / Pause
- Seek bar
- MP3 conversion with FFmpeg
- Save
- Queue
- Admin statistics
- JSON storage
- Player session tokens
- API error debugging

IMPORTANT:
1. Replace BOT_TOKEN with a NEW token from @BotFather.
2. Replace WEBAPP_URL with your real HTTPS domain.
3. FFmpeg is required only for MP3 conversion.
=========================================================
*/


/* =======================================================
   CONFIGURATION
======================================================= */

const BOT_TOKEN = '8817347840:AAFpsNeTkzHqjnlqkV_18AjMEgIX-FXHmQo';

const ADMIN_ID = '8897821078';

const BOT_NAME = 'MAYAMUSIC';

const MUSIC_API =
    'https://music-search-api-frnb.vercel.app/search?song=';

const REQUIRED_STARS = 5;

/*
Example:
https://example.com/index.php
*/
const WEBAPP_URL =
    'https://hosting-production-aacd.up.railway.app/index.php';


/* =======================================================
   DIRECTORIES
======================================================= */

const DATA_DIR = __DIR__ . '/data';

const TEMP_DIR = __DIR__ . '/tmp';

const USERS_FILE =
    DATA_DIR . '/users.json';

const RESULTS_FILE =
    DATA_DIR . '/results.json';

const PLAYER_FILE =
    DATA_DIR . '/players.json';

const QUEUE_FILE =
    DATA_DIR . '/queues.json';


date_default_timezone_set('UTC');


/* =======================================================
   BOOTSTRAP
======================================================= */

bootstrap();


/* =======================================================
   MINI APP PLAYER
======================================================= */

if (
    isset($_GET['player']) &&
    $_GET['player'] === '1'
) {
    renderPlayer();
    exit;
}


/* =======================================================
   TELEGRAM WEBHOOK
======================================================= */

$rawUpdate = file_get_contents('php://input');

$update = json_decode(
    $rawUpdate ?: '',
    true
);

if (!is_array($update)) {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        header(
            'Content-Type: text/plain; charset=utf-8'
        );

        echo BOT_NAME . " is running.";
    }

    exit;
}


handleUpdate($update);

exit;


/* =======================================================
   BOOTSTRAP
======================================================= */

function bootstrap(): void
{
    foreach (
        [
            DATA_DIR,
            TEMP_DIR
        ] as $dir
    ) {

        if (!is_dir($dir)) {
            @mkdir(
                $dir,
                0775,
                true
            );
        }
    }


    $files = [

        USERS_FILE => [],

        RESULTS_FILE => [],

        PLAYER_FILE => [],

        QUEUE_FILE => []

    ];


    foreach (
        $files as $file => $default
    ) {

        if (!file_exists($file)) {

            @file_put_contents(

                $file,

                json_encode(
                    $default,
                    JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_SLASHES |
                    JSON_UNESCAPED_UNICODE
                )

            );
        }
    }
}


/* =======================================================
   TELEGRAM API
======================================================= */

function telegram(
    string $method,
    array $params = []
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

            CURLOPT_POSTFIELDS => $params,

            CURLOPT_CONNECTTIMEOUT => 15,

            CURLOPT_TIMEOUT => 60,

            CURLOPT_SSL_VERIFYPEER => true,

            CURLOPT_HTTPHEADER => [

                'Accept: application/json'

            ]

        ]

    );


    $response = curl_exec($ch);

    $error = curl_error($ch);

    curl_close($ch);


    if (
        $response === false ||
        $error
    ) {

        return [

            'ok' => false,

            'description' =>
                $error !== ''
                    ? $error
                    : 'Telegram request failed'

        ];
    }


    $json =
        json_decode(
            $response,
            true
        );


    if (!is_array($json)) {

        return [

            'ok' => false,

            'description' =>
                'Invalid Telegram response'

        ];
    }


    return $json;
}


/* =======================================================
   SEND MESSAGE
======================================================= */

function sendMessage(
    int|string $chatId,
    string $text,
    ?array $keyboard = null,
    string $parseMode = 'HTML'
): array {

    $params = [

        'chat_id' => $chatId,

        'text' => $text,

        'parse_mode' => $parseMode,

        'disable_web_page_preview' => 'true'

    ];


    if ($keyboard !== null) {

        $params['reply_markup'] =
            json_encode(
                $keyboard,
                JSON_UNESCAPED_UNICODE
            );
    }


    return telegram(
        'sendMessage',
        $params
    );
}


/* =======================================================
   CALLBACK ANSWER
======================================================= */

function answerCallback(
    string $callbackId,
    string $text = '',
    bool $alert = false
): void {

    telegram(
        'answerCallbackQuery',
        [

            'callback_query_id' =>
                $callbackId,

            'text' =>
                $text,

            'show_alert' =>
                $alert
                    ? 'true'
                    : 'false'

        ]
    );
}


/* =======================================================
   JSON READ
======================================================= */

function readJson(
    string $file,
    array $default = []
): array {

    if (!file_exists($file)) {
        return $default;
    }


    $raw =
        @file_get_contents($file);


    if (
        $raw === false ||
        trim($raw) === ''
    ) {

        return $default;
    }


    $data =
        json_decode(
            $raw,
            true
        );


    return is_array($data)
        ? $data
        : $default;
}


/* =======================================================
   JSON WRITE
======================================================= */

function writeJson(
    string $file,
    array $data
): bool {

    $tmp =
        $file . '.tmp';


    $ok =
        @file_put_contents(

            $tmp,

            json_encode(

                $data,

                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE

            ),

            LOCK_EX

        );


    if ($ok === false) {
        return false;
    }


    return @rename(
        $tmp,
        $file
    );
}


/* =======================================================
   ESCAPE HTML
======================================================= */

function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES |
        ENT_SUBSTITUTE,
        'UTF-8'
    );
}


/* =======================================================
   SAVE USER
======================================================= */

function saveUser(array $from): void
{
    if (!isset($from['id'])) {
        return;
    }


    $users =
        readJson(USERS_FILE);


    $id =
        (string)$from['id'];


    if (!isset($users[$id])) {

        $users[$id] = [

            'id' => $id,

            'first_name' =>
                $from['first_name'] ?? '',

            'last_name' =>
                $from['last_name'] ?? '',

            'username' =>
                $from['username'] ?? '',

            'access' => false,

            'access_until' => null,

            'joined_at' => time(),

            'last_seen' => time()

        ];

    } else {

        $users[$id]['first_name'] =
            $from['first_name']
            ??
            ($users[$id]['first_name'] ?? '');


        $users[$id]['last_name'] =
            $from['last_name']
            ??
            ($users[$id]['last_name'] ?? '');


        $users[$id]['username'] =
            $from['username']
            ??
            ($users[$id]['username'] ?? '');


        $users[$id]['last_seen'] =
            time();
    }


    writeJson(
        USERS_FILE,
        $users
    );
}


/* =======================================================
   ACCESS CHECK
======================================================= */

function hasAccess(
    int|string $userId
): bool {

    if (
        (string)$userId ===
        (string)ADMIN_ID
    ) {

        return true;
    }


    $users =
        readJson(USERS_FILE);


    $id =
        (string)$userId;


    if (!isset($users[$id])) {
        return false;
    }


    if (
        !empty($users[$id]['access']) &&
        empty($users[$id]['access_until'])
    ) {

        return true;
    }


    $until =
        (int)(
            $users[$id]['access_until']
            ?? 0
        );


    return $until > time();
}


/* =======================================================
   GRANT ACCESS
======================================================= */

function grantAccess(
    int|string $userId,
    ?int $days = null
): void {

    $users =
        readJson(USERS_FILE);


    $id =
        (string)$userId;


    if (!isset($users[$id])) {

        $users[$id] = [

            'id' => $id,

            'access' => false,

            'access_until' => null

        ];
    }


    $users[$id]['access'] =
        true;


    if ($days === null) {

        $users[$id]['access_until'] =
            null;

    } else {

        $users[$id]['access_until'] =
            time() +
            ($days * 86400);
    }


    writeJson(
        USERS_FILE,
        $users
    );
}


/* =======================================================
   PREMIUM MESSAGE
======================================================= */

function accessMessage(
    int|string $chatId
): void {

    $keyboard = [

        'inline_keyboard' => [

            [

                [

                    'text' =>
                        '⭐ Unlock for ' .
                        REQUIRED_STARS .
                        ' Stars',

                    'callback_data' =>
                        'buy_access'

                ]

            ],

            [

                [

                    'text' =>
                        '🔄 Check Access',

                    'callback_data' =>
                        'check_access'

                ]

            ]

        ]

    ];


    sendMessage(

        $chatId,

        "🎵 <b>" .
        BOT_NAME .
        "</b>\n\n" .

        "Premium access is required.\n\n" .

        "⭐ Price: <b>" .
        REQUIRED_STARS .
        " Telegram Stars</b>",

        $keyboard

    );
}


/* =======================================================
   STARS INVOICE
======================================================= */

function sendAccessInvoice(
    int|string $chatId
): void {

    telegram(

        'sendInvoice',

        [

            'chat_id' =>
                $chatId,

            'title' =>
                BOT_NAME .
                ' Premium',

            'description' =>
                'Unlock music search, player and downloads.',

            'payload' =>
                'maya_access_' .
                $chatId .
                '_' .
                time(),

            'currency' =>
                'XTR',

            'prices' =>
                json_encode(

                    [

                        [

                            'label' =>
                                'Premium Access',

                            'amount' =>
                                REQUIRED_STARS

                        ]

                    ]

                ),

            'provider_token' =>
                ''

        ]

    );
}


/* =======================================================
   MUSIC SEARCH API
======================================================= */

function searchMusic(
    string $query
): array {

    $query =
        trim($query);


    if ($query === '') {
        return [];
    }


    $url =
        MUSIC_API .
        rawurlencode($query);


    $ch =
        curl_init();


    curl_setopt_array(

        $ch,

        [

            CURLOPT_URL =>
                $url,

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_FOLLOWLOCATION =>
                true,

            CURLOPT_MAXREDIRS =>
                5,

            CURLOPT_CONNECTTIMEOUT =>
                15,

            CURLOPT_TIMEOUT =>
                45,

            CURLOPT_SSL_VERIFYPEER =>
                true,

            CURLOPT_SSL_VERIFYHOST =>
                2,

            CURLOPT_HTTPHEADER => [

                'Accept: application/json',

                'User-Agent: Mozilla/5.0 MAYAMUSIC/1.0'

            ]

        ]

    );


    $response =
        curl_exec($ch);


    $curlError =
        curl_error($ch);


    $curlNo =
        curl_errno($ch);


    $httpCode =
        (int)
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    curl_close($ch);


    /*
    -------------------------------------------------------
    API CONNECTION ERROR
    -------------------------------------------------------
    */

    if (
        $response === false ||
        $response === ''
    ) {

        return [

            '__error' => true,

            'message' =>
                'API connection failed',

            'curl' =>
                $curlError,

            'errno' =>
                $curlNo,

            'http' =>
                $httpCode

        ];
    }


    /*
    -------------------------------------------------------
    JSON ERROR
    -------------------------------------------------------
    */

    $json =
        json_decode(
            $response,
            true
        );


    if (!is_array($json)) {

        return [

            '__error' => true,

            'message' =>
                'API returned invalid JSON',

            'http' =>
                $httpCode,

            'raw' =>
                substr(
                    $response,
                    0,
                    500
                )

        ];
    }


    /*
    -------------------------------------------------------
    API SUCCESS CHECK
    -------------------------------------------------------
    */

    if (
        isset($json['success']) &&
        !$json['success']
    ) {

        return [

            '__error' => true,

            'message' =>
                'API returned success=false',

            'http' =>
                $httpCode,

            'raw' =>
                substr(
                    $response,
                    0,
                    500
                )

        ];
    }


    /*
    -------------------------------------------------------
    RESULTS CHECK
    -------------------------------------------------------
    */

    if (
        !isset($json['results']) ||
        !is_array($json['results'])
    ) {

        return [

            '__error' => true,

            'message' =>
                'API results array missing',

            'http' =>
                $httpCode

        ];
    }


    $results = [];


    foreach (
        $json['results']
        as $item
    ) {

        if (!is_array($item)) {
            continue;
        }


        $title =
            trim(
                (string)
                ($item['title'] ?? '')
            );


        $artists =
            trim(
                (string)
                ($item['artists'] ?? '')
            );


        $album =
            trim(
                (string)
                ($item['album'] ?? '')
            );


        $duration =
            trim(
                (string)
                ($item['duration'] ?? '')
            );


        $downloadUrl =
            trim(
                (string)
                ($item['download_url'] ?? '')
            );


        if ($title === '') {
            continue;
        }


        if ($downloadUrl === '') {
            continue;
        }


        $results[] = [

            'title' =>
                $title,

            'artists' =>
                $artists !== ''
                    ? $artists
                    : 'Unknown Artist',

            'album' =>
                $album !== ''
                    ? $album
                    : 'Unknown Album',

            'duration' =>
                $duration !== ''
                    ? $duration
                    : '--:--',

            'download_url' =>
                $downloadUrl,

            'thumbnail' =>
                ''

        ];
    }


    return array_slice(
        $results,
        0,
        10
    );
}


/* =======================================================
   SAVE RESULTS
======================================================= */

function saveResults(
    int|string $chatId,
    array $results
): void {

    $all =
        readJson(RESULTS_FILE);


    $all[(string)$chatId] = [

        'saved_at' =>
            time(),

        'results' =>
            $results

    ];


    writeJson(
        RESULTS_FILE,
        $all
    );
}


/* =======================================================
   GET SONG
======================================================= */

function getSong(
    int|string $chatId,
    int $index
): ?array {

    $all =
        readJson(RESULTS_FILE);


    $id =
        (string)$chatId;


    if (
        !isset(
            $all[$id]['results'][$index]
        )
    ) {

        return null;
    }


    return is_array(
        $all[$id]['results'][$index]
    )
        ? $all[$id]['results'][$index]
        : null;
}


/* =======================================================
   PLAYER TOKEN
======================================================= */

function createPlayerToken(
    array $song
): string {

    $token =
        bin2hex(
            random_bytes(16)
        );


    $players =
        readJson(PLAYER_FILE);


    $players[$token] = [

        'song' =>
            $song,

        'created_at' =>
            time(),

        'expires_at' =>
            time() + 3600

    ];


    /*
    Cleanup expired tokens
    */

    foreach (
        $players as $key => $value
    ) {

        if (
            (int)
            ($value['expires_at'] ?? 0)
            <
            time()
        ) {

            unset(
                $players[$key]
            );
        }
    }


    writeJson(
        PLAYER_FILE,
        $players
    );


    return $token;
}


/* =======================================================
   GET PLAYER SONG
======================================================= */

function getPlayerSong(
    string $token
): ?array {

    $players =
        readJson(PLAYER_FILE);


    if (
        !isset(
            $players[$token]
        )
    ) {

        return null;
    }


    $item =
        $players[$token];


    if (
        !is_array($item)
    ) {

        return null;
    }


    if (
        (int)
        ($item['expires_at'] ?? 0)
        <
        time()
    ) {

        unset(
            $players[$token]
        );


        writeJson(
            PLAYER_FILE,
            $players
        );


        return null;
    }


    return
        isset($item['song']) &&
        is_array($item['song'])
            ? $item['song']
            : null;
}


/* =======================================================
   RESULT KEYBOARD
======================================================= */

function resultKeyboard(
    int|string $chatId,
    array $results
): array {

    $rows = [];


    foreach (
        $results as $i => $song
    ) {

        $token =
            createPlayerToken(
                $song
            );


        $playerUrl =
            rtrim(
                WEBAPP_URL,
                '?&'
            ) .
            '?player=1&t=' .
            urlencode($token);


        $rows[] = [

            [

                'text' =>
                    '▶️ Play ' .
                    ($i + 1),

                'web_app' => [

                    'url' =>
                        $playerUrl

                ]

            ],

            [

                'text' =>
                    '📥 MP3',

                'callback_data' =>
                    'mp3:' . $i

            ]

        ];


        $rows[] = [

            [

                'text' =>
                    '💾 Save',

                'callback_data' =>
                    'save:' . $i

            ],

            [

                'text' =>
                    '➕ Queue',

                'callback_data' =>
                    'queue:' . $i

            ]

        ];
    }


    return [

        'inline_keyboard' =>
            $rows

    ];
}


/* =======================================================
   SHOW SEARCH RESULTS
======================================================= */

function showSearchResults(
    int|string $chatId,
    string $query
): void {

    sendMessage(

        $chatId,

        "🔎 <b>Searching...</b>\n" .
        "<code>" .
        e($query) .
        "</code>"

    );


    $results =
        searchMusic(
            $query
        );


    /*
    -------------------------------------------------------
    API ERROR
    -------------------------------------------------------
    */

    if (
        isset($results['__error']) &&
        $results['__error'] === true
    ) {

        $errorText =

            "❌ <b>Music API Error</b>\n\n" .

            "🔎 Query: <code>" .
            e($query) .
            "</code>\n" .

            "🌐 HTTP: <code>" .
            e(
                (string)
                ($results['http'] ?? 'N/A')
            ) .
            "</code>\n" .

            "⚠️ Error: <code>" .
            e(
                (string)
                ($results['message'] ?? 'Unknown')
            ) .
            "</code>";


        if (
            !empty($results['curl'])
        ) {

            $errorText .=

                "\n\n🔧 cURL:\n<code>" .

                e(
                    (string)
                    $results['curl']
                ) .

                "</code>";
        }


        sendMessage(
            $chatId,
            $errorText
        );


        return;
    }


    /*
    -------------------------------------------------------
    NO RESULTS
    -------------------------------------------------------
    */

    if (!$results) {

        sendMessage(

            $chatId,

            "❌ <b>No results found</b>\n\n" .

            "Query: <code>" .
            e($query) .
            "</code>\n\n" .

            "Try another song or artist."

        );


        return;
    }


    saveResults(
        $chatId,
        $results
    );


    $text =
        "🎵 <b>" .
        BOT_NAME .
        " Results</b>\n\n";


    foreach (
        $results as $i => $song
    ) {

        $text .=

            "<b>" .
            ($i + 1) .
            ". " .
            e($song['title']) .
            "</b>\n" .

            "👤 " .
            e($song['artists']) .
            "\n" .

            "💿 " .
            e($song['album']) .
            "\n" .

            "⏱ " .
            e($song['duration']) .
            "\n\n";
    }


    $text .=
        "━━━━━━━━━━━━━━\n" .
        "▶️ Tap Play to listen inside Telegram.";


    sendMessage(

        $chatId,

        $text,

        resultKeyboard(
            $chatId,
            $results
        )

    );
}


/* =======================================================
   FFMPEG FINDER
======================================================= */

function findFFmpeg(): ?string
{
    $paths = [

        '/usr/bin/ffmpeg',

        '/usr/local/bin/ffmpeg',

        '/opt/bin/ffmpeg',

        '/bin/ffmpeg'

    ];


    foreach (
        $paths as $path
    ) {

        if (
            is_executable($path)
        ) {

            return $path;
        }
    }


    $which =
        @shell_exec(
            'command -v ffmpeg 2>/dev/null'
        );


    if (
        is_string($which)
    ) {

        $which =
            trim($which);


        if (
            $which !== '' &&
            is_executable($which)
        ) {

            return $which;
        }
    }


    return null;
}


/* =======================================================
   DOWNLOAD SOURCE
======================================================= */

function downloadRemoteFile(
    string $url,
    string $destination
): bool {

    $fp =
        @fopen(
            $destination,
            'wb'
        );


    if (!$fp) {
        return false;
    }


    $ch =
        curl_init($url);


    curl_setopt_array(

        $ch,

        [

            CURLOPT_FILE =>
                $fp,

            CURLOPT_FOLLOWLOCATION =>
                true,

            CURLOPT_MAXREDIRS =>
                5,

            CURLOPT_CONNECTTIMEOUT =>
                15,

            CURLOPT_TIMEOUT =>
                180,

            CURLOPT_SSL_VERIFYPEER =>
                true,

            CURLOPT_USERAGENT =>
                'MAYAMUSIC/1.0'

        ]

    );


    $ok =
        curl_exec($ch);


    $http =
        (int)
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    curl_close($ch);


    fclose($fp);


    return

        $ok !== false &&

        $http >= 200 &&

        $http < 400 &&

        file_exists($destination) &&

        filesize($destination) > 1024;
}


/* =======================================================
   FFMPEG CONVERSION
======================================================= */

function convertToMP3(
    string $input,
    string $output,
    string $ffmpeg
): bool {

    $command =

        escapeshellarg($ffmpeg) .

        ' -y ' .

        '-hide_banner ' .

        '-loglevel error ' .

        '-i ' .
        escapeshellarg($input) .

        ' -vn ' .

        '-codec:a libmp3lame ' .

        '-b:a 128k ' .

        '-map_metadata -1 ' .

        escapeshellarg($output) .

        ' 2>&1';


    $lines = [];


    @exec(
        $command,
        $lines,
        $exitCode
    );


    return

        $exitCode === 0 &&

        file_exists($output) &&

        filesize($output) > 1024;
}


/* =======================================================
   SEND MP3
======================================================= */

function sendMP3(
    int|string $chatId,
    array $song
): void {

    if (
        empty(
            $song['download_url']
        )
    ) {

        sendMessage(
            $chatId,
            "❌ Audio source unavailable."
        );


        return;
    }


    $ffmpeg =
        findFFmpeg();


    if ($ffmpeg === null) {

        sendMessage(

            $chatId,

            "❌ <b>FFmpeg not installed.</b>\n\n" .

            "Install FFmpeg on the server " .
            "to enable MP3 downloads."

        );


        return;
    }


    sendMessage(

        $chatId,

        "⏳ <b>Preparing MP3...</b>\n\n" .
        e($song['title'])

    );


    $base =
        'maya_' .
        bin2hex(
            random_bytes(8)
        );


    $input =
        TEMP_DIR .
        '/' .
        $base .
        '.source';


    $output =
        TEMP_DIR .
        '/' .
        $base .
        '.mp3';


    try {

        if (
            !downloadRemoteFile(
                $song['download_url'],
                $input
            )
        ) {

            sendMessage(

                $chatId,

                "❌ Could not download " .
                "the source audio."

            );


            return;
        }


        if (
            !convertToMP3(
                $input,
                $output,
                $ffmpeg
            )
        ) {

            sendMessage(

                $chatId,

                "❌ MP3 conversion failed.\n\n" .
                "Check FFmpeg and source URL."

            );


            return;
        }


        $filename =
            safeFilename(
                $song['title'] .
                ' - ' .
                $song['artists']
            );


        $result =
            telegram(

                'sendAudio',

                [

                    'chat_id' =>
                        $chatId,

                    'audio' =>
                        new CURLFile(

                            $output,

                            'audio/mpeg',

                            $filename

                        ),

                    'title' =>
                        $song['title'],

                    'performer' =>
                        $song['artists'],

                    'caption' =>
                        '🎵 ' .
                        $song['title'] .
                        "\n" .
                        BOT_NAME

                ]

            );


        if (
            empty(
                $result['ok']
            )
        ) {

            sendMessage(

                $chatId,

                "❌ Telegram upload failed.\n\n" .

                e(
                    (string)
                    (
                        $result['description']
                        ??
                        'Unknown error'
                    )
                )

            );
        }

    } finally {

        @unlink($input);

        @unlink($output);
    }
}


/* =======================================================
   SAVE SONG
======================================================= */

function saveSong(
    int|string $chatId,
    array $song
): void {

    $users =
        readJson(USERS_FILE);


    $id =
        (string)$chatId;


    if (
        !isset($users[$id])
    ) {

        $users[$id] = [];
    }


    if (
        !isset(
            $users[$id]['saved']
        ) ||
        !is_array(
            $users[$id]['saved']
        )
    ) {

        $users[$id]['saved'] = [];
    }


    $users[$id]['saved'][] =
        $song;


    $users[$id]['saved'] =
        array_slice(
            $users[$id]['saved'],
            -50
        );


    writeJson(
        USERS_FILE,
        $users
    );
}


/* =======================================================
   QUEUE SONG
======================================================= */

function queueSong(
    int|string $chatId,
    array $song
): void {

    $queues =
        readJson(QUEUE_FILE);


    $id =
        (string)$chatId;


    if (
        !isset($queues[$id]) ||
        !is_array($queues[$id])
    ) {

        $queues[$id] = [];
    }


    $queues[$id][] =
        $song;


    $queues[$id] =
        array_slice(
            $queues[$id],
            -50
        );


    writeJson(
        QUEUE_FILE,
        $queues
    );
}


/* =======================================================
   UPDATE HANDLER
======================================================= */

function handleUpdate(
    array $update
): void {

    if (
        isset(
            $update['message']['from']
        )
    ) {

        saveUser(
            $update['message']['from']
        );
    }


    if (
        isset(
            $update['callback_query']['from']
        )
    ) {

        saveUser(
            $update['callback_query']['from']
        );
    }


    /*
    Telegram Stars pre-checkout
    */

    if (
        isset(
            $update['pre_checkout_query']
        )
    ) {

        $query =
            $update['pre_checkout_query'];


        telegram(

            'answerPreCheckoutQuery',

            [

                'pre_checkout_query_id' =>
                    $query['id'],

                'ok' =>
                    'true'

            ]

        );


        return;
    }


    /*
    Successful payment
    */

    if (
        isset(
            $update['message']
                ['successful_payment']
        )
    ) {

        $chatId =
            $update['message']
                ['chat']
                ['id'];


        grantAccess(
            $chatId
        );


        sendMessage(

            $chatId,

            "✅ <b>Payment successful!</b>\n\n" .

            BOT_NAME .
            " Premium is unlocked.\n\n" .

            "🎵 Send a song name or artist."

        );


        return;
    }


    /*
    Callback
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
    Message
    */

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
}


/* =======================================================
   MESSAGE HANDLER
======================================================= */

function handleMessage(
    array $message
): void {

    $chatId =
        $message['chat']['id'];


    $text =
        trim(
            (string)
            (
                $message['text']
                ?? ''
            )
        );


    if ($text === '') {
        return;
    }


    /*
    START
    */

    if (
        $text === '/start'
    ) {

        if (
            hasAccess($chatId)
        ) {

            sendMessage(

                $chatId,

                "🎵 <b>" .
                BOT_NAME .
                "</b>\n\n" .

                "Send a song name, artist " .
                "or album to search.\n\n" .

                "Example:\n" .

                "<code>Chandni</code>\n" .

                "<code>Arijit Singh</code>"

            );

        } else {

            accessMessage(
                $chatId
            );
        }


        return;
    }


    /*
    HELP
    */

    if (
        $text === '/help'
    ) {

        sendMessage(

            $chatId,

            "🎵 <b>" .
            BOT_NAME .
            " Help</b>\n\n" .

            "🔎 Send a song name to search\n" .

            "▶️ Play opens the Telegram player\n" .

            "📥 MP3 converts audio using FFmpeg\n" .

            "💾 Save stores your song\n" .

            "➕ Queue adds the song to queue"

        );


        return;
    }


    /*
    ADMIN
    */

    if (
        $text === '/admin'
    ) {

        if (
            (string)$chatId !==
            (string)ADMIN_ID
        ) {

            sendMessage(
                $chatId,
                "⛔ Admin only."
            );


            return;
        }


        $users =
            readJson(
                USERS_FILE
            );


        $total =
            count($users);


        $active =
            0;


        foreach (
            $users as $user
        ) {

            if (
                !empty(
                    $user['access']
                )
            ) {

                $until =
                    $user['access_until']
                    ?? null;


                if (
                    $until === null ||
                    (int)$until > time()
                ) {

                    $active++;
                }
            }
        }


        sendMessage(

            $chatId,

            "🛠 <b>" .
            BOT_NAME .
            " Admin</b>\n\n" .

            "👥 Users: <b>" .
            $total .
            "</b>\n" .

            "⭐ Active: <b>" .
            $active .
            "</b>"

        );


        return;
    }


    /*
    ACCESS
    */

    if (
        !hasAccess($chatId)
    ) {

        accessMessage(
            $chatId
        );


        return;
    }


    /*
    MUSIC SEARCH
    */

    showSearchResults(
        $chatId,
        $text
    );
}


/* =======================================================
   CALLBACK HANDLER
======================================================= */

function handleCallback(
    array $callback
): void {

    $callbackId =
        $callback['id'];


    $chatId =
        $callback['message']
            ['chat']
            ['id']
        ?? null;


    if ($chatId === null) {

        answerCallback(
            $callbackId,
            'Chat unavailable.',
            true
        );


        return;
    }


    $data =
        (string)
        (
            $callback['data']
            ?? ''
        );


    /*
    BUY ACCESS
    */

    if (
        $data === 'buy_access'
    ) {

        answerCallback(
            $callbackId,
            'Opening Stars payment...'
        );


        sendAccessInvoice(
            $chatId
        );


        return;
    }


    /*
    CHECK ACCESS
    */

    if (
        $data === 'check_access'
    ) {

        if (
            hasAccess($chatId)
        ) {

            answerCallback(
                $callbackId,
                'Access is active.'
            );


            sendMessage(

                $chatId,

                "✅ <b>Access active.</b>\n\n" .
                "Send a song name to search."

            );

        } else {

            answerCallback(

                $callbackId,

                'Premium access is not active.',

                true

            );
        }


        return;
    }


    /*
    PREMIUM CHECK
    */

    if (
        !hasAccess($chatId)
    ) {

        answerCallback(

            $callbackId,

            'Premium access required.',

            true

        );


        return;
    }


    /*
    MUSIC ACTIONS
    */

    if (
        preg_match(
            '/^(mp3|save|queue):(\d+)$/',
            $data,
            $match
        )
    ) {

        $action =
            $match[1];


        $index =
            (int)$match[2];


        $song =
            getSong(
                $chatId,
                $index
            );


        if (!$song) {

            answerCallback(

                $callbackId,

                'Result expired. Search again.',

                true

            );


            return;
        }


        /*
        MP3
        */

        if (
            $action === 'mp3'
        ) {

            answerCallback(
                $callbackId,
                'Preparing MP3...'
            );


            sendMP3(
                $chatId,
                $song
            );


            return;
        }


        /*
        SAVE
        */

        if (
            $action === 'save'
        ) {

            saveSong(
                $chatId,
                $song
            );


            answerCallback(

                $callbackId,

                'Saved to your MAYAMUSIC library.'

            );


            return;
        }


        /*
        QUEUE
        */

        if (
            $action === 'queue'
        ) {

            queueSong(
                $chatId,
                $song
            );


            answerCallback(

                $callbackId,

                'Added to queue.'

            );


            return;
        }
    }


    answerCallback(
        $callbackId,
        'Unknown action.'
    );
}


/* =======================================================
   MINI APP PLAYER
======================================================= */

function renderPlayer(): void
{
    $token =
        trim(
            (string)
            (
                $_GET['t']
                ?? ''
            )
        );


    $song =
        $token !== ''
            ? getPlayerSong($token)
            : null;


    header(
        'Content-Type: text/html; charset=utf-8'
    );


    if (
        !$song ||
        empty(
            $song['download_url']
        )
    ) {

        echo playerErrorPage();

        return;
    }


    $title =
        e(
            (string)
            (
                $song['title']
                ?? 'Unknown Title'
            )
        );


    $artists =
        e(
            (string)
            (
                $song['artists']
                ?? 'Unknown Artist'
            )
        );


    $album =
        e(
            (string)
            (
                $song['album']
                ?? 'Unknown Album'
            )
        );


    $duration =
        e(
            (string)
            (
                $song['duration']
                ?? '--:--'
            )
        );


    $audio =
        e(
            (string)
            $song['download_url']
        );


echo <<<HTML
<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
name="viewport"
content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">

<meta
name="theme-color"
content="#08090d">

<title>MAYAMUSIC</title>

<script
src="https://telegram.org/js/telegram-web-app.js">
</script>


<style>

*{
    box-sizing:border-box;
    -webkit-tap-highlight-color:transparent;
}


html,
body{

    margin:0;

    width:100%;

    min-height:100%;

    background:#08090d;

    color:#fff;

    font-family:

        -apple-system,

        BlinkMacSystemFont,

        "SF Pro Display",

        "SF Pro Text",

        Inter,

        Arial,

        sans-serif;

}


body{

    min-height:100vh;

    overflow:hidden;

}


.app{

    min-height:100vh;

    display:flex;

    flex-direction:column;

    padding:

        calc(env(safe-area-inset-top) + 18px)

        18px

        calc(env(safe-area-inset-bottom) + 18px);

}


.top{

    display:flex;

    align-items:center;

    justify-content:space-between;

}


.brand{

    font-size:18px;

    font-weight:850;

    letter-spacing:-.5px;

}


.status{

    display:flex;

    align-items:center;

    gap:7px;

    color:#969ba9;

    font-size:10px;

    letter-spacing:1px;

}


.dot{

    width:7px;

    height:7px;

    border-radius:50%;

    background:#55e59e;

    box-shadow:

        0 0 12px

        rgba(85,229,158,.65);

}


.cover{

    width:min(78vw,330px);

    aspect-ratio:1/1;

    margin:34px auto 28px;

    border-radius:30px;

    background:

        radial-gradient(

            circle at 25% 20%,

            rgba(255,255,255,.15),

            transparent 25%

        ),

        radial-gradient(

            circle at 75% 80%,

            rgba(255,255,255,.08),

            transparent 28%

        ),

        linear-gradient(

            145deg,

            #292d39,

            #0d0f15

        );

    display:flex;

    align-items:center;

    justify-content:center;

    box-shadow:

        0 30px 70px rgba(0,0,0,.5),

        inset 0 1px 0

        rgba(255,255,255,.08);

}


.coverIcon{

    font-size:76px;

}


.meta{

    margin-bottom:20px;

}


.title{

    font-size:25px;

    line-height:1.15;

    font-weight:850;

    letter-spacing:-.8px;

    white-space:nowrap;

    overflow:hidden;

    text-overflow:ellipsis;

}


.artist{

    margin-top:8px;

    color:#c4c7d1;

    font-size:15px;

    white-space:nowrap;

    overflow:hidden;

    text-overflow:ellipsis;

}


.album{

    margin-top:5px;

    color:#858b9b;

    font-size:12px;

    white-space:nowrap;

    overflow:hidden;

    text-overflow:ellipsis;

}


.progress{

    width:100%;

    height:4px;

    appearance:none;

    -webkit-appearance:none;

    border:0;

    border-radius:99px;

    background:#343741;

    outline:none;

}


.progress::-webkit-slider-thumb{

    appearance:none;

    -webkit-appearance:none;

    width:13px;

    height:13px;

    border-radius:50%;

    background:#fff;

}


.times{

    display:flex;

    justify-content:space-between;

    color:#818796;

    font-size:10px;

    margin-top:8px;

}


.controls{

    margin-top:23px;

    display:flex;

    align-items:center;

    justify-content:center;

    gap:21px;

}


.ctrl{

    border:0;

    background:transparent;

    color:#fff;

    width:47px;

    height:47px;

    border-radius:50%;

    display:flex;

    align-items:center;

    justify-content:center;

    font-size:20px;

    transition:

        transform .12s ease,

        background .12s ease;

}


.ctrl:active{

    transform:scale(.86);

    background:

        rgba(255,255,255,.08);

}


.play{

    width:65px;

    height:65px;

    background:#fff;

    color:#08090d;

    font-size:25px;

    box-shadow:

        0 14px 35px

        rgba(0,0,0,.4);

}


.bottom{

    margin-top:auto;

    padding-top:18px;

    text-align:center;

    color:#737988;

    font-size:10px;

}


</style>

</head>


<body>


<div class="app">


    <div class="top">

        <div class="brand">
            MAYAMUSIC
        </div>

        <div class="status">

            <span class="dot"></span>

            <span>PLAYER</span>

        </div>

    </div>


    <div class="cover">

        <div class="coverIcon">
            🎵
        </div>

    </div>


    <div class="meta">

        <div
        class="title"
        id="title">

            {$title}

        </div>


        <div class="artist">

            {$artists}

        </div>


        <div class="album">

            {$album}

        </div>

    </div>


    <input
    class="progress"
    id="seek"
    type="range"
    min="0"
    max="100"
    value="0"
    step="0.1">


    <div class="times">

        <span id="current">
            0:00
        </span>

        <span id="total">
            {$duration}
        </span>

    </div>


    <div class="controls">

        <button
        class="ctrl"
        id="prev">

            ⏮

        </button>


        <button
        class="ctrl play"
        id="play">

            ▶

        </button>


        <button
        class="ctrl"
        id="next">

            ⏭

        </button>

    </div>


    <div class="bottom">

        MAYAMUSIC • Telegram Player

    </div>


</div>


<audio
id="audio"
preload="auto"
playsinline
src="{$audio}">
</audio>


<script>

(function(){

    const tg =
        window.Telegram &&
        window.Telegram.WebApp
        ? window.Telegram.WebApp
        : null;


    if(tg){

        try{

            tg.ready();

            tg.expand();

            if(tg.setHeaderColor){

                tg.setHeaderColor(
                    '#08090d'
                );

            }

            if(tg.setBackgroundColor){

                tg.setBackgroundColor(
                    '#08090d'
                );

            }

        }catch(e){}

    }


    const audio =
        document.getElementById(
            'audio'
        );


    const play =
        document.getElementById(
            'play'
        );


    const seek =
        document.getElementById(
            'seek'
        );


    const current =
        document.getElementById(
            'current'
        );


    const total =
        document.getElementById(
            'total'
        );


    function formatTime(seconds){

        if(
            !Number.isFinite(seconds)
        ){

            return '0:00';

        }


        const s =
            Math.max(
                0,
                Math.floor(seconds)
            );


        const m =
            Math.floor(
                s / 60
            );


        const r =
            String(
                s % 60
            ).padStart(
                2,
                '0'
            );


        return m + ':' + r;

    }


    function updateIcon(){

        play.textContent =
            audio.paused
                ? '▶'
                : 'Ⅱ';

    }


    async function startPlayback(){

        try{

            await audio.play();

            updateIcon();

        }catch(error){

            /*
            Telegram WebView may block
            autoplay without user gesture.
            User can press Play.
            */

            updateIcon();

        }

    }


    play.addEventListener(
        'click',
        async function(){

            if(
                audio.paused
            ){

                await startPlayback();

            }else{

                audio.pause();

                updateIcon();

            }

        }
    );


    audio.addEventListener(
        'loadedmetadata',
        function(){

            if(
                Number.isFinite(
                    audio.duration
                )
            ){

                seek.max =
                    String(
                        audio.duration
                    );


                total.textContent =
                    formatTime(
                        audio.duration
                    );

            }


            /*
            Best-effort autoplay
            */

            startPlayback();

        }
    );


    audio.addEventListener(
        'timeupdate',
        function(){

            if(
                Number.isFinite(
                    audio.duration
                )
            ){

                seek.max =
                    String(
                        audio.duration
                    );


                seek.value =
                    String(
                        audio.currentTime
                    );

            }


            current.textContent =
                formatTime(
                    audio.currentTime
                );

        }
    );


    seek.addEventListener(
        'input',
        function(){

            audio.currentTime =
                Number(
                    seek.value || 0
                );

        }
    );


    audio.addEventListener(
        'play',
        updateIcon
    );


    audio.addEventListener(
        'pause',
        updateIcon
    );


    document
        .getElementById('prev')
        .addEventListener(
            'click',
            function(){

                if(
                    tg &&
                    tg.HapticFeedback
                ){

                    try{

                        tg.HapticFeedback
                          .impactOccurred(
                              'light'
                          );

                    }catch(e){}

                }

            }
        );


    document
        .getElementById('next')
        .addEventListener(
            'click',
            function(){

                if(
                    tg &&
                    tg.HapticFeedback
                ){

                    try{

                        tg.HapticFeedback
                          .impactOccurred(
                              'light'
                          );

                    }catch(e){}

                }

            }
        );


})();

</script>


</body>

</html>
HTML;
}


/* =======================================================
   PLAYER ERROR PAGE
======================================================= */

function playerErrorPage(): string
{
    return <<<HTML
<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
name="viewport"
content="width=device-width,initial-scale=1">

<meta
name="theme-color"
content="#08090d">

<title>MAYAMUSIC</title>


<style>

body{

    margin:0;

    min-height:100vh;

    display:flex;

    align-items:center;

    justify-content:center;

    background:#08090d;

    color:#fff;

    font-family:

        -apple-system,

        BlinkMacSystemFont,

        Arial,

        sans-serif;

}


.box{

    width:calc(100% - 40px);

    max-width:380px;

    padding:28px;

    border-radius:24px;

    background:#11131a;

    text-align:center;

}


h2{

    margin:0 0 10px;

}


p{

    color:#9298a8;

    line-height:1.5;

}

</style>

</head>


<body>

<div class="box">

    <h2>
        🎵 Player unavailable
    </h2>

    <p>

        This player session has
        expired or is unavailable.

        Search the song again in
        MAYAMUSIC.

    </p>

</div>

</body>

</html>
HTML;
}


/* =======================================================
   SAFE MP3 FILENAME
======================================================= */

function safeFilename(
    string $name
): string {

    $name =
        preg_replace(
            '/[^\p{L}\p{N}\-_. ]+/u',
            '',
            $name
        );


    $name =
        trim(
            (string)$name
        );


    if ($name === '') {

        $name =
            'MAYAMUSIC';

    }


    return
        mb_substr(
            $name,
            0,
            120
        ) .
        '.mp3';
}

?>
