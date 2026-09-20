<?php
declare(strict_types=1);

/*
==========================================================
 VICKY BOT HOST MANAGER
 PHP Telegram Bot Hosting Panel
==========================================================

 Supports:
   - .php
   - .py
   - Admin only
   - Upload
   - Source analysis
   - Token detection
   - Admin ID detection
   - Docker isolated deployment
   - Start / Stop / Restart
   - Delete
   - Logs
   - Daily 100 deployment limit

 REQUIREMENTS:
   PHP 8+
   cURL
   Docker installed
   Docker CLI accessible by PHP user
   Telegram webhook with HTTPS

 IMPORTANT:
 Never run uploaded code directly on the host.
==========================================================
*/


/* ========================================================
   CONFIG
======================================================== */

$BOT_TOKEN = '7832316573:AAHuDnlgw1pUSFnWFrxe5flPpoKlBJ7nYqI';

$ADMIN_ID = '8897821078';

$MAX_DAILY_DEPLOYS = 100;

$BASE_DIR = __DIR__;

$DATA_DIR = $BASE_DIR . '/data';
$UPLOAD_DIR = $DATA_DIR . '/uploads';
$BOT_DIR = $DATA_DIR . '/bots';
$LOG_DIR = $DATA_DIR . '/logs';

foreach ([
    $DATA_DIR,
    $UPLOAD_DIR,
    $BOT_DIR,
    $LOG_DIR
] as $dir) {

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

$DB_FILE = $DATA_DIR . '/bots.json';
$USAGE_FILE = $DATA_DIR . '/usage.json';


/* ========================================================
   DATABASE HELPERS
======================================================== */

function loadJson(string $file, array $default = []): array
{
    if (!file_exists($file)) {
        return $default;
    }

    $data = json_decode(
        file_get_contents($file),
        true
    );

    return is_array($data)
        ? $data
        : $default;
}


function saveJson(string $file, array $data): void
{
    file_put_contents(
        $file,
        json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES
        ),
        LOCK_EX
    );
}


/* ========================================================
   TELEGRAM API
======================================================== */

function telegram(string $method, array $data = []): array
{
    global $BOT_TOKEN;

    $url =
        "https://api.telegram.org/bot"
        . $BOT_TOKEN
        . "/"
        . $method;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_TIMEOUT => 60,
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


function sendMessage(
    string $chatId,
    string $text,
    ?array $keyboard = null
): void {

    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] =
            json_encode($keyboard);
    }

    telegram(
        'sendMessage',
        $data
    );
}


/* ========================================================
   SECURITY
======================================================== */

function isAdmin($userId): bool
{
    global $ADMIN_ID;

    return (string)$userId ===
        (string)$ADMIN_ID;
}


/* ========================================================
   DAILY LIMIT
======================================================== */

function getUsage(): array
{
    global $USAGE_FILE;

    $today = date('Y-m-d');

    $data = loadJson(
        $USAGE_FILE,
        [
            'date' => $today,
            'count' => 0
        ]
    );

    if (($data['date'] ?? '') !== $today) {

        $data = [
            'date' => $today,
            'count' => 0
        ];

        saveJson(
            $USAGE_FILE,
            $data
        );
    }

    return $data;
}


function canDeploy(): bool
{
    global $MAX_DAILY_DEPLOYS;

    $usage = getUsage();

    return $usage['count'] <
        $MAX_DAILY_DEPLOYS;
}


function consumeDeploy(): void
{
    global $USAGE_FILE;

    $usage = getUsage();

    $usage['count']++;

    saveJson(
        $USAGE_FILE,
        $usage
    );
}


/* ========================================================
   SAFE BOT ID
======================================================== */

function generateBotId(): string
{
    return 'bot_' .
        date('Ymd_His') .
        '_' .
        bin2hex(random_bytes(3));
}


/* ========================================================
   FILE ANALYSIS
======================================================== */

function analyzeSource(
    string $file
): array {

    $source = file_get_contents($file);

    $extension =
        strtolower(
            pathinfo(
                $file,
                PATHINFO_EXTENSION
            )
        );

    $result = [
        'language' => strtoupper($extension),
        'token' => null,
        'admin_id' => null,
        'dependencies' => []
    ];


    /* Telegram token */

    $patterns = [

        '/\b\d{8,12}:[A-Za-z0-9_-]{30,}\b/',

        '/(?:BOT_TOKEN|TOKEN)\s*=\s*[\'"]([^\'"]+)[\'"]/i',

        '/(?:bot_token|token)\s*=>\s*[\'"]([^\'"]+)[\'"]/i'
    ];


    foreach ($patterns as $pattern) {

        if (preg_match(
            $pattern,
            $source,
            $match
        )) {

            $result['token'] =
                $match[1] ??
                $match[0];

            break;
        }
    }


    /* Admin ID */

    $adminPatterns = [

        '/(?:ADMIN_ID|OWNER_ID)\s*=\s*[\'"]?(\d{5,15})/i',

        '/(?:ADMIN_ID|OWNER_ID)\s*=>\s*[\'"]?(\d{5,15})/i'
    ];


    foreach ($adminPatterns as $pattern) {

        if (preg_match(
            $pattern,
            $source,
            $match
        )) {

            $result['admin_id'] =
                $match[1];

            break;
        }
    }


    /* Python dependencies */

    if ($extension === 'py') {

        preg_match_all(
            '/^\s*(?:import|from)\s+([A-Za-z0-9_.-]+)/m',
            $source,
            $matches
        );

        $result['dependencies'] =
            array_values(
                array_unique(
                    $matches[1] ?? []
                )
            );
    }


    /* PHP dependencies */

    if ($extension === 'php') {

        if (
            stripos(
                $source,
                'curl_init'
            ) !== false
        ) {

            $result['dependencies'][] =
                'PHP cURL';
        }

        if (
            stripos(
                $source,
                'mysqli'
            ) !== false
        ) {

            $result['dependencies'][] =
                'MySQLi';
        }

        if (
            stripos(
                $source,
                'PDO'
            ) !== false
        ) {

            $result['dependencies'][] =
                'PDO';
        }
    }


    return $result;
}


/* ========================================================
   MASK SECRET
======================================================== */

function maskSecret(
    ?string $secret
): string {

    if (!$secret) {
        return 'Not detected';
    }

    if (strlen($secret) < 8) {
        return '••••••••';
    }

    return
        '••••••'
        . substr(
            $secret,
            -6
        );
}


/* ========================================================
   DOCKER
======================================================== */

function docker(string $command): array
{
    $output = [];
    $code = 0;

    exec(
        'docker ' .
        $command .
        ' 2>&1',
        $output,
        $code
    );

    return [
        'code' => $code,
        'output' => implode(
            "\n",
            $output
        )
    ];
}


/* ========================================================
   CREATE PYTHON DOCKERFILE
======================================================== */

function createPythonDockerfile(
    string $dir
): void {

    $dockerfile = <<<DOCKER
FROM python:3.12-slim

WORKDIR /app

COPY . /app

RUN if [ -f requirements.txt ]; then pip install --no-cache-dir -r requirements.txt; fi

CMD ["python", "bot.py"]
DOCKER;

    file_put_contents(
        $dir . '/Dockerfile',
        $dockerfile
    );
}


/* ========================================================
   CREATE PHP DOCKERFILE
======================================================== */

function createPhpDockerfile(
    string $dir
): void {

    $dockerfile = <<<DOCKER
FROM php:8.3-cli

WORKDIR /app

COPY . /app

CMD ["php", "bot.php"]
DOCKER;

    file_put_contents(
        $dir . '/Dockerfile',
        $dockerfile
    );
}


/* ========================================================
   DEPLOY BOT
======================================================== */

function deployBot(
    string $botId,
    string $sourceDir,
    string $language
): array {

    if ($language === 'PY') {

        createPythonDockerfile(
            $sourceDir
        );

        $image =
            'vicky-bot-python:' .
            $botId;

    } else {

        createPhpDockerfile(
            $sourceDir
        );

        $image =
            'vicky-bot-php:' .
            $botId;
    }


    /* Build image */

    $build = docker(
        'build -t ' .
        escapeshellarg($image) .
        ' ' .
        escapeshellarg($sourceDir)
    );


    if ($build['code'] !== 0) {

        return [
            'success' => false,
            'error' =>
                "Docker build failed:\n"
                . $build['output']
        ];
    }


    /* Start isolated container */

    $containerName =
        'vicky_' .
        $botId;


    $run = docker(
        'run -d ' .
        '--name ' .
        escapeshellarg(
            $containerName
        ) .
        ' --restart unless-stopped ' .
        '--memory=512m ' .
        '--cpus=1 ' .
        '--pids-limit=128 ' .
        escapeshellarg($image)
    );


    if ($run['code'] !== 0) {

        return [
            'success' => false,
            'error' =>
                "Container start failed:\n"
                . $run['output']
        ];
    }


    $containerId =
        trim($run['output']);


    return [
        'success' => true,
        'image' => $image,
        'container' => $containerId,
        'container_name' =>
            $containerName
    ];
}


/* ========================================================
   STOP
======================================================== */

function stopBot(
    string $container
): bool {

    $result = docker(
        'stop ' .
        escapeshellarg($container)
    );

    return $result['code'] === 0;
}


/* ========================================================
   START
======================================================== */

function startBot(
    string $container
): bool {

    $result = docker(
        'start ' .
        escapeshellarg($container)
    );

    return $result['code'] === 0;
}


/* ========================================================
   RESTART
======================================================== */

function restartBot(
    string $container
): bool {

    $result = docker(
        'restart ' .
        escapeshellarg($container)
    );

    return $result['code'] === 0;
}


/* ========================================================
   LOGS
======================================================== */

function getLogs(
    string $container
): string {

    $result = docker(
        'logs --tail 80 ' .
        escapeshellarg($container)
    );

    return $result['output'];
}


/* ========================================================
   DELETE
======================================================== */

function deleteBot(
    string $container
): void {

    docker(
        'rm -f ' .
        escapeshellarg($container)
    );
}


/* ========================================================
   KEYBOARD
======================================================== */

function mainKeyboard(): array
{
    return [
        'inline_keyboard' => [

            [

                [
                    'text' => '📤 Upload Bot',
                    'callback_data' =>
                        'upload'
                ]

            ],

            [

                [
                    'text' => '🤖 My Bots',
                    'callback_data' =>
                        'bots'
                ],

                [
                    'text' => '📊 Usage',
                    'callback_data' =>
                        'usage'
                ]

            ]

        ]
    ];
}


/* ========================================================
   WEBHOOK UPDATE
======================================================== */

$updateRaw =
    file_get_contents(
        'php://input'
    );

if (!$updateRaw) {

    /*
     Browser access = health page
    */

    header(
        'Content-Type: text/plain'
    );

    echo
        "Vicky Bot Hosting Manager is running\n";

    exit;
}


$update =
    json_decode(
        $updateRaw,
        true
    );


if (!is_array($update)) {
    exit;
}


/* ========================================================
   CALLBACK QUERY
======================================================== */

if (isset(
    $update['callback_query']
)) {

    $callback =
        $update['callback_query'];

    $fromId =
        $callback['from']['id'] ??
        null;

    $chatId =
        $callback['message']['chat']['id'] ??
        null;

    $action =
        $callback['data'] ??
        '';


    if (!isAdmin($fromId)) {

        telegram(
            'answerCallbackQuery',
            [
                'callback_query_id' =>
                    $callback['id'],
                'text' =>
                    'Access denied',
                'show_alert' => true
            ]
        );

        exit;
    }


    telegram(
        'answerCallbackQuery',
        [
            'callback_query_id' =>
                $callback['id']
        ]
    );


    /* Upload */

    if ($action === 'upload') {

        sendMessage(
            (string)$chatId,
            "📤 <b>Upload Bot</b>\n\n"
            . "Send a <code>.php</code> or "
            . "<code>.py</code> file.\n\n"
            . "The system will analyze it before deployment."
        );

        exit;
    }


    /* Usage */

    if ($action === 'usage') {

        $usage = getUsage();

        sendMessage(
            (string)$chatId,
            "📊 <b>Daily Hosting Usage</b>\n\n"
            . "Deployments: <b>"
            . $usage['count']
            . "/"
            . $MAX_DAILY_DEPLOYS
            . "</b>"
        );

        exit;
    }


    /* Bots */

    if ($action === 'bots') {

        $bots =
            loadJson(
                $DB_FILE
            );

        if (!$bots) {

            sendMessage(
                (string)$chatId,
                "🤖 No bots deployed."
            );

            exit;
        }


        foreach ($bots as $id => $bot) {

            $status =
                docker(
                    'inspect -f "{{.State.Status}}" '
                    . escapeshellarg(
                        $bot['container']
                    )
                );

            $state =
                trim(
                    $status['output']
                );

            $keyboard = [
                'inline_keyboard' => [

                    [

                        [
                            'text' => '▶️ Start',
                            'callback_data' =>
                                'start:' . $id
                        ],

                        [
                            'text' => '⏹ Stop',
                            'callback_data' =>
                                'stop:' . $id
                        ]

                    ],

                    [

                        [
                            'text' => '🔄 Restart',
                            'callback_data' =>
                                'restart:' . $id
                        ],

                        [
                            'text' => '📜 Logs',
                            'callback_data' =>
                                'logs:' . $id
                        ]

                    ],

                    [

                        [
                            'text' => '🗑 Delete',
                            'callback_data' =>
                                'delete:' . $id
                        ]

                    ]

                ]
            ];


            sendMessage(
                (string)$chatId,

                "🤖 <b>"
                . htmlspecialchars(
                    $bot['name']
                )
                . "</b>\n\n"

                . "ID: <code>"
                . $id
                . "</code>\n"

                . "Language: <b>"
                . $bot['language']
                . "</b>\n"

                . "Status: <b>"
                . htmlspecialchars($state)
                . "</b>\n"

                . "Token: <code>"
                . maskSecret(
                    $bot['token'] ?? null
                )
                . "</code>",

                $keyboard
            );
        }

        exit;
    }


    /* Bot actions */

    if (preg_match(
        '/^(start|stop|restart|logs|delete):(.+)$/',
        $action,
        $match
    )) {

        $command =
            $match[1];

        $botId =
            $match[2];

        $bots =
            loadJson(
                $DB_FILE
            );


        if (!isset(
            $bots[$botId]
        )) {

            sendMessage(
                (string)$chatId,
                "❌ Bot not found."
            );

            exit;
        }


        $container =
            $bots[$botId]['container'];


        if ($command === 'start') {

            startBot(
                $container
            );

            sendMessage(
                (string)$chatId,
                "▶️ Bot started."
            );
        }


        if ($command === 'stop') {

            stopBot(
                $container
            );

            sendMessage(
                (string)$chatId,
                "⏹ Bot stopped."
            );
        }


        if ($command === 'restart') {

            restartBot(
                $container
            );

            sendMessage(
                (string)$chatId,
                "🔄 Bot restarted."
            );
        }


        if ($command === 'logs') {

            $logs =
                getLogs(
                    $container
                );

            if ($logs === '') {
                $logs = 'No logs available.';
            }

            if (strlen($logs) > 3500) {

                $logs =
                    substr(
                        $logs,
                        -3500
                    );
            }

            sendMessage(
                (string)$chatId,
                "📜 <b>Bot Logs</b>\n\n"
                . "<pre>"
                . htmlspecialchars(
                    $logs
                )
                . "</pre>"
            );
        }


        if ($command === 'delete') {

            deleteBot(
                $container
            );

            unset(
                $bots[$botId]
            );

            saveJson(
                $DB_FILE,
                $bots
            );

            sendMessage(
                (string)$chatId,
                "🗑 Bot deleted."
            );
        }

        exit;
    }
}


/* ========================================================
   MESSAGE
======================================================== */

if (isset(
    $update['message']
)) {

    $message =
        $update['message'];

    $chatId =
        $message['chat']['id'] ??
        null;

    $userId =
        $message['from']['id'] ??
        null;

    if (!isAdmin($userId)) {

        if ($chatId !== null) {

            sendMessage(
                (string)$chatId,
                "⛔ <b>Admin access only.</b>"
            );
        }

        exit;
    }


    /* START */

    if (
        isset(
            $message['text']
        )
        &&
        trim(
            $message['text']
        ) === '/start'
    ) {

        $usage =
            getUsage();

        sendMessage(
            (string)$chatId,

            "🚀 <b>VICKY BOT HOST MANAGER</b>\n\n"
            . "📤 Upload PHP/Python Telegram bots\n"
            . "🔍 Automatic source analysis\n"
            . "🔐 Token/Admin detection\n"
            . "🐳 Docker isolated hosting\n"
            . "▶️ Start / Stop / Restart\n"
            . "📜 Live logs\n"
            . "🗑 Delete bots\n\n"
            . "📊 Today: <b>"
            . $usage['count']
            . "/"
            . $MAX_DAILY_DEPLOYS
            . "</b>",

            mainKeyboard()
        );

        exit;
    }


    /* DOCUMENT */

    if (
        isset(
            $message['document']
        )
    ) {

        $document =
            $message['document'];

        $filename =
            $document['file_name'] ??
            '';


        if (
            !preg_match(
                '/\.(php|py)$/i',
                $filename
            )
        ) {

            sendMessage(
                (string)$chatId,
                "❌ Only <b>.php</b> and <b>.py</b> files are supported."
            );

            exit;
        }


        if (!canDeploy()) {

            sendMessage(
                (string)$chatId,
                "⛔ Daily deployment limit reached.\n\n"
                . "Limit: <b>"
                . $MAX_DAILY_DEPLOYS
                . "</b> bots/day."
            );

            exit;
        }


        sendMessage(
            (string)$chatId,
            "🔍 <b>Reading uploaded bot...</b>\n\n"
            . "Checking source, token, admin ID and dependencies..."
        );


        $fileId =
            $document['file_id'];


        $fileResponse =
            telegram(
                'getFile',
                [
                    'file_id' =>
                        $fileId
                ]
            );


        if (
            !isset(
                $fileResponse['result']['file_path']
            )
        ) {

            sendMessage(
                (string)$chatId,
                "❌ Could not download uploaded file."
            );

            exit;
        }


        $remotePath =
            $fileResponse['result']['file_path'];


        $downloadUrl =
            "https://api.telegram.org/file/bot"
            . $BOT_TOKEN
            . "/"
            . $remotePath;


        $botId =
            generateBotId();


        $botDir =
            $BOT_DIR . '/' . $botId;


        mkdir(
            $botDir,
            0755,
            true
        );


        $localFile =
            $botDir . '/' . basename(
                $filename
            );


        $content =
            file_get_contents(
                $downloadUrl
            );


        if ($content === false) {

            sendMessage(
                (string)$chatId,
                "❌ File download failed."
            );

            exit;
        }


        file_put_contents(
            $localFile,
            $content
        );


        /* Analyze */

        try {

            $analysis =
                analyzeSource(
                    $localFile
                );

        } catch (
            Throwable $e
        ) {

            sendMessage(
                (string)$chatId,
                "❌ Analysis failed:\n"
                . htmlspecialchars(
                    $e->getMessage()
                )
            );

            exit;
        }


        $language =
            $analysis['language'];


        $token =
            $analysis['token'];


        $detectedAdmin =
            $analysis['admin_id'];


        $dependencies =
            $analysis['dependencies'];


        $dependencyText =
            $dependencies
            ? implode(
                ', ',
                $dependencies
            )
            : 'None detected';


        /* Store bot */

        $bots =
            loadJson(
                $DB_FILE
            );


        $bots[$botId] = [

            'name' =>
                $filename,

            'language' =>
                $language,

            'token' =>
                $token,

            'admin_id' =>
                $detectedAdmin,

            'dependencies' =>
                $dependencies,

            'container' =>
                '',

            'created_at' =>
                date('c'),

            'status' =>
                'analyzed'

        ];


        saveJson(
            $DB_FILE,
            $bots
        );


        $keyboard = [
            'inline_keyboard' => [

                [

                    [
                        'text' =>
                            '🚀 Deploy Bot',
                        'callback_data' =>
                            'deploy:' . $botId
                    ]

                ]

            ]
        ];


        sendMessage(
            (string)$chatId,

            "✅ <b>Bot Analysis Complete</b>\n\n"

            . "📄 File: <code>"
            . htmlspecialchars(
                $filename
            )
            . "</code>\n"

            . "💻 Language: <b>"
            . $language
            . "</b>\n"

            . "🔐 Token: <code>"
            . maskSecret(
                $token
            )
            . "</code>\n"

            . "👤 Admin ID: <code>"
            . (
                $detectedAdmin
                ?: 'Not detected'
            )
            . "</code>\n"

            . "📦 Dependencies: <code>"
            . htmlspecialchars(
                $dependencyText
            )
            . "</code>\n\n"

            . "Bot is ready for deployment.",

            $keyboard
        );

        exit;
    }
}


/* ========================================================
   DEPLOY CALLBACK
======================================================== */

if (
    isset(
        $update['callback_query']['data']
    )
    &&
    str_starts_with(
        $update['callback_query']['data'],
        'deploy:'
    )
) {

    $callback =
        $update['callback_query'];

    $userId =
        $callback['from']['id'];

    $chatId =
        $callback['message']['chat']['id'];

    if (!isAdmin($userId)) {
        exit;
    }


    $botId =
        substr(
            $callback['data'],
            7
        );


    $bots =
        loadJson(
            $DB_FILE
        );


    if (!isset(
        $bots[$botId]
    )) {

        sendMessage(
            (string)$chatId,
            "❌ Bot not found."
        );

        exit;
    }


    if (!canDeploy()) {

        sendMessage(
            (string)$chatId,
            "⛔ Daily deployment limit reached."
        );

        exit;
    }


    sendMessage(
        (string)$chatId,
        "🚀 <b>Deploying bot...</b>\n\n"
        . "Creating isolated Docker environment.\n"
        . "Installing dependencies.\n"
        . "Starting runtime..."
    );


    $bot =
        $bots[$botId];


    $sourceDir =
        $BOT_DIR . '/' . $botId;


    $result =
        deployBot(
            $botId,
            $sourceDir,
            $bot['language']
        );


    if (!$result['success']) {

        $bots[$botId]['status'] =
            'failed';

        saveJson(
            $DB_FILE,
            $bots
        );

        sendMessage(
            (string)$chatId,
            "❌ <b>Deployment failed</b>\n\n"
            . "<pre>"
            . htmlspecialchars(
                $result['error']
            )
            . "</pre>"
        );

        exit;
    }


    $bots[$botId]['container'] =
        $result['container_name'];

    $bots[$botId]['status'] =
        'running';

    $bots[$botId]['deployed_at'] =
        date('c');


    saveJson(
        $DB_FILE,
        $bots
    );


    consumeDeploy();


    sendMessage(
        (string)$chatId,

        "🟢 <b>BOT LIVE</b>\n\n"

        . "🤖 <b>"
        . htmlspecialchars(
            $bot['name']
        )
        . "</b>\n"

        . "🆔 <code>"
        . $botId
        . "</code>\n"

        . "💻 "
        . $bot['language']
        . "\n"

        . "🐳 Docker: <b>Running</b>\n\n"

        . "The bot is now hosted."
    );

    exit;
}
?>
