<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ADMIN ZIP EXTRACTOR BOT - SINGLE FILE
|--------------------------------------------------------------------------
| PHP 8+
| Extensions: curl, zip, json
|
| IMPORTANT:
| Normal Telegram Bot API has a file-download limitation, so this code
| cannot magically bypass Telegram's own file-size limits.
|--------------------------------------------------------------------------
*/

set_time_limit(0);
ini_set('memory_limit', '512M');
ignore_user_abort(true);

/* =========================
   CONFIG
========================= */

const BOT_TOKEN = '7832316573:AAHuDnlgw1pUSFnWFrxe5flPpoKlBJ7nqYI';
const ADMIN_ID  = '8897821078';

const BASE_DIR  = __DIR__ . '/zip_storage';
const UPLOAD_DIR = BASE_DIR . '/uploads';
const EXTRACT_DIR = BASE_DIR . '/extracted';
const JOB_FILE = BASE_DIR . '/job.json';

const MAX_EXTRACTED_BYTES = 1099511627776; // 1 TB
const MAX_FILES = 2000000;

const PROGRESS_INTERVAL = 2; // seconds

/* =========================
   DIRECTORY SETUP
========================= */

foreach ([BASE_DIR, UPLOAD_DIR, EXTRACT_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

/* =========================
   HELPERS
========================= */

function tg(string $method, array $data = []): array
{
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'ok' => false,
            'description' => $error
        ];
    }

    curl_close($ch);

    $decoded = json_decode($response, true);

    return is_array($decoded) ? $decoded : [
        'ok' => false,
        'description' => 'Invalid Telegram response'
    ];
}

function sendMessage(int|string $chatId, string $text, ?array $keyboard = null): void
{
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] = json_encode($keyboard);
    }

    tg('sendMessage', $data);
}

function editMessage(int|string $chatId, int $messageId, string $text): void
{
    tg('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ]);
}

function formatBytes(float $bytes): string
{
    if ($bytes < 1024) {
        return number_format($bytes, 0) . ' B';
    }

    $units = ['KB', 'MB', 'GB', 'TB'];

    $i = -1;

    do {
        $bytes /= 1024;
        $i++;
    } while ($bytes >= 1024 && $i < count($units) - 1);

    return number_format($bytes, 2) . ' ' . $units[$i];
}

function formatTime(float $seconds): string
{
    $seconds = max(0, (int)$seconds);

    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;

    if ($h > 0) {
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    return sprintf('%02d:%02d', $m, $s);
}

function adminOnly(array $message): bool
{
    $id = $message['from']['id'] ?? '';

    return (string)$id === ADMIN_ID;
}

function loadJob(): ?array
{
    if (!is_file(JOB_FILE)) {
        return null;
    }

    $data = @file_get_contents(JOB_FILE);

    if (!$data) {
        return null;
    }

    $job = json_decode($data, true);

    return is_array($job) ? $job : null;
}

function saveJob(array $job): void
{
    @file_put_contents(
        JOB_FILE,
        json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function clearJob(): void
{
    if (is_file(JOB_FILE)) {
        @unlink(JOB_FILE);
    }
}

function sanitizeZipPath(string $path): ?string
{
    $path = str_replace('\\', '/', $path);
    $path = trim($path);

    if ($path === '') {
        return null;
    }

    /*
     * Reject absolute paths.
     */
    if (
        str_starts_with($path, '/') ||
        preg_match('/^[A-Za-z]:\//', $path)
    ) {
        return null;
    }

    $parts = explode('/', $path);
    $clean = [];

    foreach ($parts as $part) {

        if ($part === '' || $part === '.') {
            continue;
        }

        if ($part === '..') {
            return null;
        }

        /*
         * Remove NUL bytes.
         */
        if (strpos($part, "\0") !== false) {
            return null;
        }

        $clean[] = $part;
    }

    if (!$clean) {
        return null;
    }

    return implode('/', $clean);
}

function isInside(string $path, string $root): bool
{
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $path = str_replace('\\', '/', $path);

    return str_starts_with($path, $root);
}

function updateProgress(
    int $chatId,
    int $messageId,
    int $current,
    int $total,
    float $processedBytes,
    float $totalBytes,
    float $startTime,
    string $status = 'Extracting'
): void {
    static $lastUpdate = 0;

    $now = microtime(true);

    if (($now - $lastUpdate) < PROGRESS_INTERVAL && $current < $total) {
        return;
    }

    $lastUpdate = $now;

    $filePercent = $total > 0
        ? ($current / $total) * 100
        : 0;

    $bytePercent = $totalBytes > 0
        ? ($processedBytes / $totalBytes) * 100
        : 0;

    $percent = max($filePercent, $bytePercent);
    $percent = min(100, $percent);

    $elapsed = $now - $startTime;

    $speed = $elapsed > 0
        ? $processedBytes / $elapsed
        : 0;

    $remaining = $speed > 0 && $totalBytes > $processedBytes
        ? ($totalBytes - $processedBytes) / $speed
        : 0;

    $bars = 20;
    $filled = (int)floor(($percent / 100) * $bars);

    $progressBar =
        str_repeat('█', $filled) .
        str_repeat('░', $bars - $filled);

    $text =
        "⚙️ <b>{$status}</b>\n\n" .
        "[$progressBar] <b>" . number_format($percent, 1) . "%</b>\n\n" .
        "📦 Files: <b>{$current}</b> / <b>{$total}</b>\n" .
        "📤 Processed: <b>" . formatBytes($processedBytes) . "</b>\n" .
        "📦 Total: <b>" . formatBytes($totalBytes) . "</b>\n" .
        "🚀 Speed: <b>" . formatBytes($speed) . "/s</b>\n" .
        "⏱ Elapsed: <b>" . formatTime($elapsed) . "</b>\n" .
        "⏳ ETA: <b>" . formatTime($remaining) . "</b>";

    editMessage($chatId, $messageId, $text);
}

function extractionStats(string $dir): array
{
    $stats = [
        'files' => 0,
        'folders' => 0,
        'bytes' => 0,
        'php' => 0,
        'python' => 0,
        'zip' => 0,
        'images' => 0,
        'videos' => 0,
        'docs' => 0,
        'other' => 0
    ];

    if (!is_dir($dir)) {
        return $stats;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $dir,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {

        if ($item->isDir()) {
            $stats['folders']++;
            continue;
        }

        if (!$item->isFile()) {
            continue;
        }

        $stats['files']++;

        $size = @filesize($item->getPathname());

        if ($size !== false) {
            $stats['bytes'] += $size;
        }

        $ext = strtolower(pathinfo(
            $item->getFilename(),
            PATHINFO_EXTENSION
        ));

        switch ($ext) {
            case 'php':
            case 'php3':
            case 'php4':
            case 'php5':
            case 'phtml':
                $stats['php']++;
                break;

            case 'py':
            case 'pyw':
                $stats['python']++;
                break;

            case 'zip':
            case 'rar':
            case '7z':
            case 'tar':
            case 'gz':
                $stats['zip']++;
                break;

            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'gif':
            case 'webp':
            case 'bmp':
            case 'svg':
                $stats['images']++;
                break;

            case 'mp4':
            case 'mkv':
            case 'avi':
            case 'mov':
            case 'webm':
                $stats['videos']++;
                break;

            case 'txt':
            case 'pdf':
            case 'doc':
            case 'docx':
            case 'xls':
            case 'xlsx':
            case 'csv':
                $stats['docs']++;
                break;

            default:
                $stats['other']++;
        }
    }

    return $stats;
}

/* =========================
   ZIP EXTRACTION
========================= */

function extractZip(
    string $zipPath,
    string $destination,
    int $chatId,
    int $progressMessageId
): array {

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException(
            'PHP ZipArchive extension is not installed.'
        );
    }

    $zip = new ZipArchive();

    $open = $zip->open($zipPath);

    if ($open !== true) {
        throw new RuntimeException(
            'ZIP could not be opened. Error code: ' . $open
        );
    }

    $totalEntries = $zip->numFiles;

    if ($totalEntries > MAX_FILES) {
        $zip->close();

        throw new RuntimeException(
            'ZIP contains too many entries.'
        );
    }

    /*
     * First calculate total uncompressed size.
     */
    $totalUncompressed = 0;

    for ($i = 0; $i < $totalEntries; $i++) {

        $stat = $zip->statIndex($i);

        if (!$stat) {
            continue;
        }

        $size = (int)($stat['size'] ?? 0);

        $totalUncompressed += $size;

        if ($totalUncompressed > MAX_EXTRACTED_BYTES) {
            $zip->close();

            throw new RuntimeException(
                'Extraction would exceed the 1 TB safety limit.'
            );
        }
    }

    /*
     * Clean destination.
     */
    if (!is_dir($destination)) {
        @mkdir($destination, 0775, true);
    }

    $rootReal = realpath($destination);

    if ($rootReal === false) {
        $zip->close();

        throw new RuntimeException(
            'Could not create extraction directory.'
        );
    }

    $startTime = microtime(true);
    $processedBytes = 0;
    $processedFiles = 0;
    $unsafe = 0;

    for ($i = 0; $i < $totalEntries; $i++) {

        $stat = $zip->statIndex($i);

        if (!$stat) {
            $unsafe++;
            continue;
        }

        $rawName = (string)($stat['name'] ?? '');

        $safeName = sanitizeZipPath($rawName);

        if ($safeName === null) {
            $unsafe++;
            continue;
        }

        $isDirectory =
            str_ends_with($rawName, '/') ||
            (($stat['size'] ?? 0) === 0 &&
             !str_contains(basename($rawName), '.'));

        $target = $destination . '/' . $safeName;

        if ($isDirectory) {

            if (!is_dir($target)) {
                @mkdir($target, 0775, true);
            }

        } else {

            $parent = dirname($target);

            if (!is_dir($parent)) {
                @mkdir($parent, 0775, true);
            }

            /*
             * Validate parent path.
             */
            $parentReal = realpath($parent);

            if (
                $parentReal === false ||
                !isInside($parentReal, $rootReal)
            ) {
                $unsafe++;
                continue;
            }

            $stream = $zip->getStream($rawName);

            if ($stream === false) {
                $unsafe++;
                continue;
            }

            $out = @fopen($target, 'wb');

            if ($out === false) {
                fclose($stream);
                $unsafe++;
                continue;
            }

            while (!feof($stream)) {

                $buffer = fread($stream, 1024 * 1024);

                if ($buffer === false) {
                    break;
                }

                if ($buffer === '') {
                    continue;
                }

                fwrite($out, $buffer);

                $len = strlen($buffer);

                $processedBytes += $len;

                if ($processedBytes > MAX_EXTRACTED_BYTES) {
                    fclose($out);
                    fclose($stream);
                    $zip->close();

                    throw new RuntimeException(
                        'Extraction exceeded the 1 TB safety limit.'
                    );
                }
            }

            fclose($out);
            fclose($stream);

            $processedFiles++;

            updateProgress(
                $chatId,
                $progressMessageId,
                $processedFiles,
                max(1, $totalEntries),
                $processedBytes,
                $totalUncompressed,
                $startTime
            );
        }
    }

    $zip->close();

    return [
        'entries' => $totalEntries,
        'files' => $processedFiles,
        'bytes' => $processedBytes,
        'unsafe' => $unsafe,
        'time' => microtime(true) - $startTime
    ];
}

/* =========================
   TELEGRAM UPDATE
========================= */

$input = file_get_contents('php://input');

if ($input !== false && trim($input) !== '') {

    $update = json_decode($input, true);

    if (!is_array($update)) {
        http_response_code(400);
        exit;
    }

    /*
     * CALLBACK
     */
    if (isset($update['callback_query'])) {

        $callback = $update['callback_query'];

        $fromId = (string)($callback['from']['id'] ?? '');

        tg('answerCallbackQuery', [
            'callback_query_id' => $callback['id']
        ]);

        if ($fromId !== ADMIN_ID) {
            exit;
        }

        $chatId = $callback['message']['chat']['id'] ?? null;
        $messageId = $callback['message']['message_id'] ?? null;
        $data = $callback['data'] ?? '';

        if ($data === 'status') {

            $job = loadJob();

            if (!$job) {
                editMessage(
                    $chatId,
                    $messageId,
                    "🟢 <b>Extractor Ready</b>\n\nNo extraction is currently running."
                );
            } else {

                editMessage(
                    $chatId,
                    $messageId,
                    "⚙️ <b>Extraction Running</b>\n\n" .
                    "📦 File: <code>" .
                    htmlspecialchars($job['filename']) .
                    "</code>\n" .
                    "🚦 Status: <b>" .
                    htmlspecialchars($job['status']) .
                    "</b>\n" .
                    "🕐 Started: <b>" .
                    htmlspecialchars($job['started']) .
                    "</b>"
                );
            }

            exit;
        }

        if ($data === 'system') {

            $free = @disk_free_space(__DIR__);
            $total = @disk_total_space(__DIR__);

            $text =
                "🖥 <b>System Storage</b>\n\n" .
                "💾 Free: <b>" . formatBytes((float)$free) . "</b>\n" .
                "💽 Total: <b>" . formatBytes((float)$total) . "</b>\n\n" .
                "📦 Max extraction safety limit: <b>1 TB</b>";

            editMessage($chatId, $messageId, $text);

            exit;
        }

        exit;
    }

    /*
     * MESSAGE
     */
    $message = $update['message'] ?? null;

    if (!$message) {
        exit;
    }

    $fromId = (string)($message['from']['id'] ?? '');

    /*
     * ADMIN ONLY
     */
    if ($fromId !== ADMIN_ID) {

        if (isset($message['chat']['id'])) {
            sendMessage(
                $message['chat']['id'],
                "⛔ <b>Access Denied</b>\n\nThis bot is private."
            );
        }

        exit;
    }

    $chatId = $message['chat']['id'];

    /*
     * COMMANDS
     */
    $text = trim((string)($message['text'] ?? ''));

    if ($text === '/start' || $text === '/menu') {

        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '📊 Status',
                        'callback_data' => 'status'
                    ],
                    [
                        'text' => '🖥 System',
                        'callback_data' => 'system'
                    ]
                ]
            ]
        ];

        sendMessage(
            $chatId,
            "🗜 <b>Advanced ZIP Extractor</b>\n\n" .
            "📤 Send a ZIP file to start extraction.\n\n" .
            "⚙️ Live progress\n" .
            "⏱ Live elapsed time\n" .
            "⏳ ETA countdown\n" .
            "📊 File statistics\n" .
            "🛡 Safe path extraction\n" .
            "🔒 One extraction at a time\n" .
            "💾 1 TB application safety limit",
            $keyboard
        );

        exit;
    }

    if ($text === '/status') {

        $job = loadJob();

        if (!$job) {

            sendMessage(
                $chatId,
                "🟢 <b>Extractor Ready</b>\n\nNo extraction is running."
            );

        } else {

            sendMessage(
                $chatId,
                "⚙️ <b>Extraction Running</b>\n\n" .
                "📦 <b>" . htmlspecialchars($job['filename']) . "</b>\n" .
                "🚦 Status: <b>" . htmlspecialchars($job['status']) . "</b>\n" .
                "🕐 Started: <b>" . htmlspecialchars($job['started']) . "</b>"
            );
        }

        exit;
    }

    if ($text === '/cancel') {

        $job = loadJob();

        if (!$job) {
            sendMessage(
                $chatId,
                "ℹ️ No extraction is currently running."
            );
            exit;
        }

        /*
         * Current synchronous extraction cannot safely kill itself
         * from another Telegram request. Mark cancellation request.
         */
        $job['cancel_requested'] = true;

        saveJob($job);

        sendMessage(
            $chatId,
            "🛑 <b>Cancel requested.</b>\n\n" .
            "The extractor will stop at the next safe checkpoint."
        );

        exit;
    }

    /*
     * DOCUMENT / ZIP
     */
    if (isset($message['document'])) {

        $document = $message['document'];

        $fileId = $document['file_id'] ?? '';
        $fileName = $document['file_name'] ?? 'upload.zip';
        $fileSize = (int)($document['file_size'] ?? 0);

        if ($fileId === '') {
            sendMessage($chatId, "❌ File ID missing.");
            exit;
        }

        /*
         * Only ZIP.
         */
        $extension = strtolower(
            pathinfo($fileName, PATHINFO_EXTENSION)
        );

        if ($extension !== 'zip') {

            sendMessage(
                $chatId,
                "❌ <b>Only ZIP files are allowed.</b>"
            );

            exit;
        }

        /*
         * One job only.
         */
        $existingJob = loadJob();

        if ($existingJob) {

            sendMessage(
                $chatId,
                "⛔ <b>Extraction already running.</b>\n\n" .
                "📦 Current file: <code>" .
                htmlspecialchars($existingJob['filename']) .
                "</code>\n\n" .
                "Please wait until the current job finishes."
            );

            exit;
        }

        /*
         * Get Telegram file info.
         */
        $info = tg('getFile', [
            'file_id' => $fileId
        ]);

        if (!($info['ok'] ?? false)) {

            sendMessage(
                $chatId,
                "❌ Telegram could not prepare this file.\n\n" .
                "The file may exceed Telegram Bot API's downloadable file limit."
            );

            exit;
        }

        $telegramPath = $info['result']['file_path'] ?? '';

        if ($telegramPath === '') {
            sendMessage(
                $chatId,
                "❌ Telegram file path unavailable."
            );
            exit;
        }

        /*
         * Create unique job.
         */
        $jobId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));

        $zipPath =
            UPLOAD_DIR . '/' .
            $jobId . '.zip';

        $destination =
            EXTRACT_DIR . '/' .
            $jobId;

        @mkdir($destination, 0775, true);

        /*
         * Mark busy BEFORE download.
         */
        saveJob([
            'id' => $jobId,
            'filename' => $fileName,
            'status' => 'Downloading',
            'started' => date('Y-m-d H:i:s'),
            'cancel_requested' => false
        ]);

        $progressMessage = tg('sendMessage', [
            'chat_id' => $chatId,
            'text' =>
                "📥 <b>Preparing download...</b>\n\n" .
                "📦 File: <code>" .
                htmlspecialchars($fileName) .
                "</code>\n" .
                "💾 Size: <b>" .
                formatBytes($fileSize) .
                "</b>",
            'parse_mode' => 'HTML'
        ]);

        $progressMessageId =
            (int)($progressMessage['result']['message_id'] ?? 0);

        /*
         * Download from Telegram.
         *
         * NOTE:
         * Telegram's Bot API file-size restrictions still apply.
         */
        $downloadUrl =
            'https://api.telegram.org/file/bot' .
            BOT_TOKEN . '/' .
            $telegramPath;

        $fp = @fopen($zipPath, 'wb');

        if ($fp === false) {

            clearJob();

            sendMessage(
                $chatId,
                "❌ Cannot create local ZIP file."
            );

            exit;
        }

        $ch = curl_init($downloadUrl);

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $downloadOk = curl_exec($ch);
        $curlError = curl_error($ch);

        curl_close($ch);
        fclose($fp);

        if ($downloadOk === false || !is_file($zipPath)) {

            @unlink($zipPath);
            clearJob();

            if ($progressMessageId) {
                editMessage(
                    $chatId,
                    $progressMessageId,
                    "❌ <b>Download failed</b>\n\n" .
                    htmlspecialchars($curlError ?: 'Unknown error')
                );
            }

            exit;
        }

        /*
         * Extraction status.
         */
        saveJob([
            'id' => $jobId,
            'filename' => $fileName,
            'status' => 'Extracting',
            'started' => date('Y-m-d H:i:s'),
            'destination' => $destination,
            'cancel_requested' => false
        ]);

        if ($progressMessageId) {

            editMessage(
                $chatId,
                $progressMessageId,
                "⚙️ <b>Starting extraction...</b>\n\n" .
                "📦 <code>" .
                htmlspecialchars($fileName) .
                "</code>\n\n" .
                "🔒 Extraction lock enabled.\n" .
                "No second extraction can start."
            );
        }

        try {

            $result = extractZip(
                $zipPath,
                $destination,
                $chatId,
                $progressMessageId
            );

            /*
             * Final statistics.
             */
            $stats = extractionStats($destination);

            /*
             * Delete original ZIP to save disk space.
             */
            @unlink($zipPath);

            clearJob();

            $finalText =
                "✅ <b>EXTRACTION COMPLETED</b>\n\n" .
                "📦 ZIP: <code>" .
                htmlspecialchars($fileName) .
                "</code>\n\n" .

                "📁 Files: <b>" .
                number_format($stats['files']) .
                "</b>\n" .

                "📂 Folders: <b>" .
                number_format($stats['folders']) .
                "</b>\n" .

                "💾 Extracted: <b>" .
                formatBytes($stats['bytes']) .
                "</b>\n\n" .

                "🐘 PHP: <b>{$stats['php']}</b>\n" .
                "🐍 Python: <b>{$stats['python']}</b>\n" .
                "🗜 Archives: <b>{$stats['zip']}</b>\n" .
                "🖼 Images: <b>{$stats['images']}</b>\n" .
                "🎬 Videos: <b>{$stats['videos']}</b>\n" .
                "📄 Documents: <b>{$stats['docs']}</b>\n" .
                "📦 Other: <b>{$stats['other']}</b>\n\n" .

                "🛡 Unsafe entries skipped: <b>" .
                $result['unsafe'] .
                "</b>\n" .

                "⏱ Processing time: <b>" .
                formatTime($result['time']) .
                "</b>\n\n" .

                "🟢 <b>Extractor is ready for the next job.</b>";

            if ($progressMessageId) {
                editMessage(
                    $chatId,
                    $progressMessageId,
                    $finalText
                );
            } else {
                sendMessage($chatId, $finalText);
            }

        } catch (Throwable $e) {

            @unlink($zipPath);
            clearJob();

            $errorText =
                "❌ <b>EXTRACTION FAILED</b>\n\n" .
                "📦 File: <code>" .
                htmlspecialchars($fileName) .
                "</code>\n\n" .
                "⚠️ Error:\n<code>" .
                htmlspecialchars($e->getMessage()) .
                "</code>\n\n" .
                "🔓 Extraction lock released.";

            if ($progressMessageId) {
                editMessage(
                    $chatId,
                    $progressMessageId,
                    $errorText
                );
            } else {
                sendMessage($chatId, $errorText);
            }
        }

        exit;
    }

    /*
     * Unknown message.
     */
    sendMessage(
        $chatId,
        "🗜 <b>ZIP Extractor</b>\n\n" .
        "Sirf <b>.ZIP</b> file send karo.\n\n" .
        "Commands:\n" .
        "/start\n" .
        "/status\n" .
        "/cancel"
    );

    exit;
}

/* =========================
   WEB DASHBOARD
========================= */

header('Content-Type: text/html; charset=UTF-8');

$job = loadJob();

$free = @disk_free_space(__DIR__);
$total = @disk_total_space(__DIR__);

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>ZIP Extractor</title>

<style>
*{
    box-sizing:border-box;
}

body{
    margin:0;
    min-height:100vh;
    font-family:Arial,sans-serif;
    background:#07111f;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
}

.card{
    width:min(700px,92%);
    background:#0d1b2d;
    border:1px solid #193653;
    border-radius:22px;
    padding:30px;
    box-shadow:0 20px 70px rgba(0,0,0,.45);
}

h1{
    margin-top:0;
    font-size:28px;
}

.status{
    padding:18px;
    border-radius:15px;
    background:#071522;
    border:1px solid #193653;
    margin-top:20px;
}

.green{
    color:#57e389;
}

.yellow{
    color:#ffd166;
}

.small{
    color:#8ea4ba;
    line-height:1.7;
}

.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
    margin-top:18px;
}

.box{
    background:#091827;
    border:1px solid #193653;
    padding:15px;
    border-radius:13px;
}

.value{
    font-size:20px;
    font-weight:bold;
    margin-top:5px;
}
</style>
</head>

<body>

<div class="card">

<h1>🗜 Advanced ZIP Extractor</h1>

<div class="status">

<?php if ($job): ?>

<div class="yellow">
⚙️ EXTRACTION RUNNING
</div>

<p>
File:
<strong>
<?=htmlspecialchars($job['filename'])?>
</strong>
</p>

<p>
Status:
<strong>
<?=htmlspecialchars($job['status'])?>
</strong>
</p>

<p class="small">
Started:
<?=htmlspecialchars($job['started'])?>
</p>

<?php else: ?>

<div class="green">
🟢 EXTRACTOR READY
</div>

<p class="small">
No extraction is currently running.
</p>

<?php endif; ?>

</div>

<div class="grid">

<div class="box">
<div class="small">Free Storage</div>
<div class="value">
<?=formatBytes((float)$free)?>
</div>
</div>

<div class="box">
<div class="small">Total Storage</div>
<div class="value">
<?=formatBytes((float)$total)?>
</div>
</div>

<div class="box">
<div class="small">Maximum Safety Extraction</div>
<div class="value">1 TB</div>
</div>

<div class="box">
<div class="small">Concurrent Jobs</div>
<div class="value">1</div>
</div>

</div>

<p class="small">
Admin-only ZIP extraction system. Uploaded ZIP files are never executed;
they are extracted as files only.
</p>

</div>

</body>
</html>
<?php
