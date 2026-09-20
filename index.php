<?php
/*
==========================================================
 TeraBox Downloader Telegram Bot
 Single-file index.php
==========================================================

 REQUIRED:
 1. PHP 8.1+
 2. cURL enabled
 3. ZipArchive enabled
 4. Public HTTPS hosting

 YOU ONLY CHANGE:
 BOT TOKEN
 ADMIN ID

==========================================================
*/


/* ========================================================
   CONFIG
======================================================== */

$BOT_TOKEN = "7832316573:AAHuDnlgw1pUSFnWFrxe5flPpoKlBJ7nYqI";

/*
   Multiple admin IDs supported:

   $ADMIN_IDS = [
       "123456789",
       "987654321"
   ];
*/
$ADMIN_IDS = [
    "8897821078"
];


/* ========================================================
   FREE TERABOX API
======================================================== */

$TERABOX_API =
    "https://terabox-worker.robinkumarshakya103.workers.dev/api";


/* ========================================================
   STORAGE
======================================================== */

$BASE_DIR = __DIR__ . "/storage";

$DOWNLOAD_DIR = $BASE_DIR . "/downloads";
$EXTRACT_DIR  = $BASE_DIR . "/extracted";
$JOB_DIR      = $BASE_DIR . "/jobs";


foreach (
    [
        $BASE_DIR,
        $DOWNLOAD_DIR,
        $EXTRACT_DIR,
        $JOB_DIR
    ] as $dir
) {

    if (!is_dir($dir)) {

        @mkdir(
            $dir,
            0755,
            true
        );
    }
}


/* ========================================================
   TELEGRAM REQUEST
======================================================== */

function telegram(
    string $method,
    array $data = []
): array {

    global $BOT_TOKEN;

    $url =
        "https://api.telegram.org/bot" .
        $BOT_TOKEN .
        "/" .
        $method;

    $ch =
        curl_init($url);

    curl_setopt_array(
        $ch,
        [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60
        ]
    );

    $response =
        curl_exec($ch);

    $error =
        curl_error($ch);

    curl_close($ch);

    if ($response === false) {

        throw new Exception(
            $error ?: "Telegram connection failed"
        );
    }

    $json =
        json_decode(
            $response,
            true
        );

    if (
        !is_array($json) ||
        empty($json["ok"])
    ) {

        throw new Exception(
            $json["description"]
            ?? "Telegram API error"
        );
    }

    return $json;
}


/* ========================================================
   SEND MESSAGE
======================================================== */

function sendMessage(
    $chatId,
    string $text,
    ?array $keyboard = null
): void {

    $data = [

        "chat_id" => $chatId,

        "text" => $text,

        "parse_mode" => "HTML",

        "disable_web_page_preview" => true

    ];


    if ($keyboard !== null) {

        $data["reply_markup"] =
            json_encode(
                [
                    "inline_keyboard" =>
                        $keyboard
                ]
            );
    }


    telegram(
        "sendMessage",
        $data
    );
}


/* ========================================================
   EDIT MESSAGE
======================================================== */

function editMessage(
    $chatId,
    $messageId,
    string $text,
    ?array $keyboard = null
): void {

    $data = [

        "chat_id" =>
            $chatId,

        "message_id" =>
            $messageId,

        "text" =>
            $text,

        "parse_mode" =>
            "HTML",

        "disable_web_page_preview" =>
            true
    ];


    if ($keyboard !== null) {

        $data["reply_markup"] =
            json_encode(
                [
                    "inline_keyboard" =>
                        $keyboard
                ]
            );
    }


    telegram(
        "editMessageText",
        $data
    );
}


/* ========================================================
   ADMIN CHECK
======================================================== */

function isAdmin($userId): bool {

    global $ADMIN_IDS;

    return in_array(
        (string)$userId,
        array_map(
            "strval",
            $ADMIN_IDS
        ),
        true
    );
}


/* ========================================================
   TERABOX URL CHECK
======================================================== */

function isTeraBoxURL(
    string $url
): bool {

    if (
        !filter_var(
            $url,
            FILTER_VALIDATE_URL
        )
    ) {

        return false;
    }


    $host =
        strtolower(
            (string)parse_url(
                $url,
                PHP_URL_HOST
            )
        );


    $allowed = [

        "terabox.com",

        "www.terabox.com",

        "teraboxapp.com",

        "www.teraboxapp.com",

        "1024terabox.com",

        "www.1024terabox.com",

        "terabox.link",

        "www.terabox.link"

    ];


    return in_array(
        $host,
        $allowed,
        true
    );
}


/* ========================================================
   TERABOX API
======================================================== */

function teraboxLookup(
    string $url
): array {

    global $TERABOX_API;


    $apiURL =
        $TERABOX_API .
        "?url=" .
        rawurlencode($url);


    $ch =
        curl_init(
            $apiURL
        );


    curl_setopt_array(
        $ch,
        [

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_FOLLOWLOCATION =>
                true,

            CURLOPT_CONNECTTIMEOUT =>
                20,

            CURLOPT_TIMEOUT =>
                120,

            CURLOPT_HTTPHEADER => [

                "Accept: application/json",

                "User-Agent: Mozilla/5.0"

            ]

        ]
    );


    $response =
        curl_exec($ch);


    $http =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    $error =
        curl_error($ch);


    curl_close($ch);


    if (
        $response === false
    ) {

        throw new Exception(
            $error ?: "TeraBox API request failed"
        );
    }


    $json =
        json_decode(
            $response,
            true
        );


    if (
        $http < 200 ||
        $http >= 300 ||
        !is_array($json)
    ) {

        throw new Exception(
            "TeraBox API HTTP error: " .
            $http
        );
    }


    if (
        empty($json["success"])
    ) {

        throw new Exception(
            $json["error"]
            ?? "Unable to resolve TeraBox link"
        );
    }


    if (
        empty($json["files"])
    ) {

        throw new Exception(
            "No files found in this TeraBox link"
        );
    }


    return $json;
}


/* ========================================================
   SIZE FORMAT
======================================================== */

function formatBytes(
    $bytes
): string {

    if (
        !is_numeric($bytes)
    ) {

        return (string)$bytes;
    }


    $bytes =
        (float)$bytes;


    if ($bytes < 1024) {

        return
            round($bytes, 2) .
            " B";
    }


    if ($bytes < 1048576) {

        return
            round(
                $bytes / 1024,
                2
            ) .
            " KB";
    }


    if ($bytes < 1073741824) {

        return
            round(
                $bytes / 1048576,
                2
            ) .
            " MB";
    }


    return
        round(
            $bytes / 1073741824,
            2
        ) .
        " GB";
}


/* ========================================================
   JOB ID
======================================================== */

function createJob(
    array $data
): string {

    global $JOB_DIR;

    $id =
        bin2hex(
            random_bytes(16)
        );


    $data["_id"] =
        $id;

    $data["_created"] =
        time();


    file_put_contents(

        $JOB_DIR .
        "/" .
        $id .
        ".json",

        json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES
        ),

        LOCK_EX
    );


    return $id;
}


/* ========================================================
   LOAD JOB
======================================================== */

function loadJob(
    string $id
): ?array {

    global $JOB_DIR;


    if (
        !preg_match(
            '/^[a-f0-9]{32}$/',
            $id
        )
    ) {

        return null;
    }


    $file =
        $JOB_DIR .
        "/" .
        $id .
        ".json";


    if (
        !is_file($file)
    ) {

        return null;
    }


    $data =
        json_decode(
            file_get_contents($file),
            true
        );


    return
        is_array($data)
        ? $data
        : null;
}


/* ========================================================
   SAVE JOB
======================================================== */

function saveJob(
    string $id,
    array $data
): void {

    global $JOB_DIR;


    file_put_contents(

        $JOB_DIR .
        "/" .
        $id .
        ".json",

        json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES
        ),

        LOCK_EX
    );
}


/* ========================================================
   SAFE FILENAME
======================================================== */

function safeName(
    string $name
): string {

    $name =
        basename(
            str_replace(
                "\\",
                "/",
                $name
            )
        );


    $name =
        preg_replace(
            '/[^\pL\pN._() \-]+/u',
            "_",
            $name
        );


    return
        trim(
            $name ?: "file",
            ". "
        );
}


/* ========================================================
   DOWNLOAD FILE FROM URL
======================================================== */

function downloadRemoteFile(
    string $url,
    string $destination
): void {

    $fp =
        fopen(
            $destination,
            "wb"
        );


    if (!$fp) {

        throw new Exception(
            "Cannot create destination file"
        );
    }


    $ch =
        curl_init(
            $url
        );


    curl_setopt_array(
        $ch,
        [

            CURLOPT_FILE =>
                $fp,

            CURLOPT_FOLLOWLOCATION =>
                true,

            CURLOPT_MAXREDIRS =>
                10,

            CURLOPT_CONNECTTIMEOUT =>
                30,

            CURLOPT_TIMEOUT =>
                86400,

            CURLOPT_USERAGENT =>
                "Mozilla/5.0",

            CURLOPT_HTTPHEADER => [

                "Accept: */*"

            ]

        ]
    );


    $result =
        curl_exec($ch);


    $error =
        curl_error($ch);


    $http =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    curl_close($ch);

    fclose($fp);


    if (
        $result === false ||
        $http < 200 ||
        $http >= 400
    ) {

        @unlink(
            $destination
        );


        throw new Exception(
            "Download failed. HTTP " .
            $http .
            " " .
            $error
        );
    }
}


/* ========================================================
   ZIP EXTRACTION
======================================================== */

function extractZip(
    string $zipFile,
    string $destination
): string {

    if (
        !class_exists(
            "ZipArchive"
        )
    ) {

        throw new Exception(
            "ZipArchive PHP extension is required."
        );
    }


    if (
        !is_dir($destination)
    ) {

        mkdir(
            $destination,
            0755,
            true
        );
    }


    $zip =
        new ZipArchive();


    $result =
        $zip->open(
            $zipFile
        );


    if (
        $result !== true
    ) {

        throw new Exception(
            "Unable to open ZIP archive."
        );
    }


    /*
     * ZIP Slip protection
     */

    for (
        $i = 0;
        $i < $zip->numFiles;
        $i++
    ) {

        $name =
            $zip->getNameIndex(
                $i
            );


        if (
            $name === false
        ) {

            continue;
        }


        $name =
            str_replace(
                "\\",
                "/",
                $name
            );


        if (
            str_starts_with(
                $name,
                "/"
            ) ||
            preg_match(
                '#(^|/)\.\.(/|$)#',
                $name
            )
        ) {

            $zip->close();

            throw new Exception(
                "Unsafe ZIP path detected."
            );
        }
    }


    if (
        !$zip->extractTo(
            $destination
        )
    ) {

        $zip->close();

        throw new Exception(
            "Extraction failed."
        );
    }


    $zip->close();


    return createIndexHTML(
        $destination
    );
}


/* ========================================================
   INDEX.HTML
======================================================== */

function createIndexHTML(
    string $directory
): string {

    $files = [];


    $iterator =
        new RecursiveIteratorIterator(

            new RecursiveDirectoryIterator(

                $directory,

                FilesystemIterator::SKIP_DOTS
            )
        );


    foreach (
        $iterator as $file
    ) {

        if (
            !$file->isFile()
        ) {

            continue;
        }


        $full =
            $file->getPathname();


        $relative =
            ltrim(
                str_replace(
                    $directory,
                    "",
                    $full
                ),
                DIRECTORY_SEPARATOR
            );


        $files[] = [

            "name" =>
                $relative,

            "size" =>
                $file->getSize()

        ];
    }


    usort(
        $files,
        function (
            $a,
            $b
        ) {

            return strcmp(
                $a["name"],
                $b["name"]
            );
        }
    );


    $html = <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">

<meta
 name="viewport"
 content="width=device-width,initial-scale=1"
>

<title>TeraBox Extracted Files</title>

<style>

body{
    margin:0;
    padding:30px;
    background:#080d18;
    color:#fff;
    font-family:Arial,sans-serif;
}

.container{
    max-width:1100px;
    margin:auto;
}

h1{
    margin-bottom:25px;
}

.file{
    padding:15px;
    margin:8px 0;
    border:1px solid #263247;
    border-radius:12px;
    background:#111827;
}

a{
    color:#61a8ff;
    text-decoration:none;
}

.size{
    color:#8d99aa;
    font-size:13px;
    margin-top:5px;
}

</style>

</head>

<body>

<div class="container">

<h1>📦 Extracted Files</h1>

HTML;


    foreach (
        $files as $file
    ) {

        $name =
            htmlspecialchars(
                $file["name"],
                ENT_QUOTES,
                "UTF-8"
            );


        $url =
            htmlspecialchars(
                str_replace(
                    DIRECTORY_SEPARATOR,
                    "/",
                    $file["name"]
                ),
                ENT_QUOTES,
                "UTF-8"
            );


        $size =
            formatBytes(
                $file["size"]
            );


        $html .= <<<HTML

<div class="file">

<a href="$url" download>
⬇️ $name
</a>

<div class="size">
$size
</div>

</div>

HTML;
    }


    $html .= <<<HTML

</div>

</body>
</html>

HTML;


    $index =
        $directory .
        "/index.html";


    file_put_contents(
        $index,
        $html,
        LOCK_EX
    );


    return $index;
}


/* ========================================================
   TELEGRAM UPDATE
======================================================== */

function processUpdate(
    array $update
): void {

    /*
     * NORMAL MESSAGE
     */

    if (
        isset(
            $update["message"]
        )
    ) {

        $message =
            $update["message"];


        $chatId =
            $message["chat"]["id"]
            ?? null;


        $userId =
            $message["from"]["id"]
            ?? null;


        if (
            $chatId === null ||
            $userId === null
        ) {

            return;
        }


        /*
         * ADMIN ONLY
         */

        if (
            !isAdmin($userId)
        ) {

            sendMessage(

                $chatId,

                "⛔ <b>Access Denied</b>\n\n" .
                "This bot is private and admin-only."

            );

            return;
        }


        $text =
            trim(
                (string)(
                    $message["text"]
                    ?? ""
                )
            );


        /*
         * START
         */

        if (
            $text === "/start"
        ) {

            sendMessage(

                $chatId,

                "🚀 <b>TeraBox Downloader</b>\n\n" .
                "Send a TeraBox share link."

            );

            return;
        }


        /*
         * HELP
         */

        if (
            $text === "/help"
        ) {

            sendMessage(

                $chatId,

                "📖 <b>How to use</b>\n\n" .
                "1️⃣ Send TeraBox link\n" .
                "2️⃣ Bot verifies it\n" .
                "3️⃣ Filename + size appear\n" .
                "4️⃣ Choose Download or Extract"

            );

            return;
        }


        /*
         * TERABOX LINK
         */

        if (
            isTeraBoxURL($text)
        ) {

            try {

                sendMessage(
                    $chatId,
                    "🔎 <b>Verifying TeraBox link...</b>"
                );


                $data =
                    teraboxLookup(
                        $text
                    );


                /*
                 * API can return multiple files.
                 */

                $files =
                    $data["files"];


                /*
                 * Create one job containing
                 * all resolved files.
                 */

                $jobId =
                    createJob(

                        [

                            "chat_id" =>
                                (string)$chatId,

                            "source_url" =>
                                $text,

                            "files" =>
                                $files,

                            "status" =>
                                "verified"

                        ]

                    );


                /*
                 * Display first file.
                 */

                $first =
                    $files[0];


                $filename =
                    safeName(
                        (string)(
                            $first["file_name"]
                            ?? "Unknown"
                        )
                    );


                $size =
                    (string)(
                        $first["size"]
                        ?? "Unknown"
                    );


                $count =
                    count(
                        $files
                    );


                $message =

                    "✅ <b>TeraBox Verified</b>\n\n" .

                    "📄 <b>File:</b> " .
                    htmlspecialchars(
                        $filename
                    ) .
                    "\n" .

                    "📦 <b>Size:</b> " .
                    htmlspecialchars(
                        $size
                    ) .
                    "\n" .

                    "📁 <b>Items:</b> " .
                    $count .
                    "\n\n" .

                    "Choose an action:";


                sendMessage(

                    $chatId,

                    $message,

                    [

                        [

                            [

                                "text" =>
                                    "⬇️ Download",

                                "callback_data" =>
                                    "download:" .
                                    $jobId

                            ],

                            [

                                "text" =>
                                    "📦 Extract",

                                "callback_data" =>
                                    "extract:" .
                                    $jobId

                            ]

                        ]

                    ]

                );

            }

            catch (
                Throwable $e
            ) {

                sendMessage(

                    $chatId,

                    "❌ <b>TeraBox verification failed</b>\n\n" .
                    htmlspecialchars(
                        $e->getMessage()
                    )

                );
            }


            return;
        }


        sendMessage(

            $chatId,

            "⚠️ Send a valid TeraBox share link."

        );

        return;
    }


    /*
     * BUTTON PRESS
     */

    if (
        isset(
            $update["callback_query"]
        )
    ) {

        $query =
            $update["callback_query"];


        $userId =
            $query["from"]["id"]
            ?? null;


        $chatId =
            $query["message"]["chat"]["id"]
            ?? null;


        $messageId =
            $query["message"]["message_id"]
            ?? null;


        $data =
            (string)(
                $query["data"]
                ?? ""
            );


        if (
            $userId === null ||
            $chatId === null ||
            $messageId === null
        ) {

            return;
        }


        if (
            !isAdmin($userId)
        ) {

            telegram(

                "answerCallbackQuery",

                [

                    "callback_query_id" =>
                        $query["id"],

                    "text" =>
                        "Access denied.",

                    "show_alert" =>
                        true

                ]

            );

            return;
        }


        telegram(

            "answerCallbackQuery",

            [

                "callback_query_id" =>
                    $query["id"],

                "text" =>
                    "Processing..."

            ]

        );


        $parts =
            explode(
                ":",
                $data,
                2
            );


        $action =
            $parts[0]
            ?? "";


        $jobId =
            $parts[1]
            ?? "";


        $job =
            loadJob(
                $jobId
            );


        if (
            !$job
        ) {

            editMessage(

                $chatId,

                $messageId,

                "❌ <b>Job expired or not found.</b>"

            );

            return;
        }


        /*
         * DOWNLOAD
         */

        if (
            $action === "download"
        ) {

            $files =
                $job["files"]
                ?? [];


            if (
                empty($files)
            ) {

                sendMessage(
                    $chatId,
                    "❌ No downloadable file found."
                );

                return;
            }


            /*
             * Telegram itself cannot act as a
             * 500GB storage/download server.
             *
             * We provide the resolved download
             * links from the free API.
             */

            $buttons = [];


            foreach (
                $files as $index => $file
            ) {

                $name =
                    safeName(
                        (string)(
                            $file["file_name"]
                            ?? "File " .
                            ($index + 1)
                        )
                    );


                $url =
                    (string)(
                        $file["download_url"]
                        ?? ""
                    );


                if (
                    $url === ""
                ) {

                    continue;
                }


                /*
                 * Telegram button text
                 * must stay reasonably short.
                 */

                $label =
                    "⬇️ " .
                    mb_substr(
                        $name,
                        0,
                        35
                    );


                $buttons[] = [

                    [

                        "text" =>
                            $label,

                        "url" =>
                            $url

                    ]

                ];
            }


            if (
                empty($buttons)
            ) {

                sendMessage(
                    $chatId,
                    "❌ Download URL unavailable."
                );

                return;
            }


            editMessage(

                $chatId,

                $messageId,

                "⬇️ <b>Download Ready</b>\n\n" .
                "Tap a file below:",

                $buttons

            );


            return;
        }


        /*
         * EXTRACT
         */

        if (
            $action === "extract"
        ) {

            $files =
                $job["files"]
                ?? [];


            if (
                empty($files)
            ) {

                sendMessage(
                    $chatId,
                    "❌ No file available for extraction."
                );

                return;
            }


            /*
             * Extraction requires the hosting server
             * to download the archive first.
             *
             * It is NOT safe to run a huge 500GB
             * extraction inside Telegram webhook.
             */

            editMessage(

                $chatId,

                $messageId,

                "📦 <b>Extraction Request Created</b>\n\n" .
                "The archive must first be transferred to the server.\n\n" .
                "⚠️ Extraction speed depends on server bandwidth, disk I/O, " .
                "archive compression and available storage."

            );


            /*
             * Store extraction request.
             *
             * A cron/worker can process:
             *
             * status = extract_queued
             */

            $job["status"] =
                "extract_queued";


            $job["extract_requested"] =
                time();


            saveJob(
                $jobId,
                $job
            );


            return;
        }
    }
}


/* ========================================================
   WEBHOOK ENTRY
======================================================== */

if (
    $_SERVER["REQUEST_METHOD"]
    === "POST"
) {

    $raw =
        file_get_contents(
            "php://input"
        );


    $update =
        json_decode(
            $raw ?: "",
            true
        );


    if (
        !is_array($update)
    ) {

        http_response_code(400);

        echo "Invalid update.";

        exit;
    }


    try {

        processUpdate(
            $update
        );


        header(
            "Content-Type: application/json"
        );


        echo json_encode(
            [
                "ok" => true
            ]
        );

    }

    catch (
        Throwable $e
    ) {

        http_response_code(500);

        echo json_encode(

            [

                "ok" =>
                    false,

                "error" =>
                    $e->getMessage()

            ]

        );
    }


    exit;
}


/* ========================================================
   HEALTH CHECK
======================================================== */

header(
    "Content-Type: text/plain; charset=utf-8"
);

echo
"TeraBox Downloader Bot is online.\n";
