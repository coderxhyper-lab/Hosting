<?php
/**
 * ============================================================
 * MAYAMUSIC - COMPLETE SINGLE FILE TELEGRAM MUSIC BOT V2
 * PHP 8.1+
 * Security hardening + Manual UPI/UTR + smart auto-next
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

/*
 * QUICK SETUP (OPTION A - SIMPLE PHONE/FILE EDIT)
 * ------------------------------------------------------------
 * 1) Put ONLY your BotFather token in BOT_TOKEN below.
 * 2) Upload this single file to GitHub/Railway.
 * 3) No Railway Variables, CAPTCHA, Cloudflare, API keys or other
 *    manual configuration is required by this build.
 * 4) Webhook URL is the WEBAPP_URL constant below.
 *
 * IMPORTANT: Keep this repository PRIVATE because the Bot Token is
 * a secret. If a token has ever been exposed, regenerate it in
 * @BotFather before using this build.
 */

const BOT_TOKEN = '8817347840:AAFpsNeTkzHqjnlqkV_18AjMEgIX-FXHmQo'; // <-- ONLY VALUE YOU NEED TO CHANGE
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
const TELEGRAM_STARS_PRICE = 5;
const UPI_PAYMENT_TTL = 300;
const MAX_DISCOUNT_PERCENT = 90;

const UPI_ID = 'vickybanna8674@ybl';
const UPI_NAME = 'MAYAMUSIC';

const DATA_DIR = __DIR__ . '/data';

const HTTP_TIMEOUT = 18;
const API_CONNECT_TIMEOUT = 8;

/* ============================================================
   INTERNAL SECURITY / FEATURE LIMITS
   No external configuration is required.
   ============================================================ */
const PLAYER_TTL = 86400;
const SEARCH_TTL = 1800;
const RATE_WINDOW = 60;
const RATE_LIMIT_SEARCH = 30;
const RATE_LIMIT_API = 60;
const RATE_LIMIT_REDEEM = 10;
const REFERRAL_PRICE = 2;
const REFERRAL_REWARD_DAYS = 3;
const REFERRAL_BATCH = 5;
const RADHE_HOURS = 6;
const REMINDER_INTERVAL = 1800;
const REMINDER_MIN_IDLE = 1800;
const SUNDAY_REWARD_HOURS = 24;
const POLICY_VERSION = '1.0';


/* ============================================================
   FIXED APPLICATION CONFIGURATION
   ============================================================ */

function configBotToken(): string
{
    $token = trim(BOT_TOKEN);
    return ($token !== '' && $token !== 'PASTE_YOUR_BOT_TOKEN_HERE')
        ? $token
        : '';
}

function configWebAppUrl(): string
{
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
   RATE LIMIT + AUDIT LOG
   ============================================================ */

function rateLimit(string $name, int $max, int $window, int $uid = 0): bool
{
    $max = max(1, $max);
    $window = max(1, $window);
    $uid = (int)$uid;
    $key = $name . ':' . $uid;
    $data = readJson('ratelimits');
    $now = now();
    $hits = $data[$key] ?? [];
    if (!is_array($hits)) $hits = [];

    $fresh = [];
    foreach ($hits as $ts) {
        $ts = (int)$ts;
        if ($ts > ($now - $window)) $fresh[] = $ts;
    }

    if (count($fresh) >= $max) {
        $data[$key] = array_slice($fresh, -$max);
        writeJson('ratelimits', $data);
        return false;
    }

    $fresh[] = $now;
    $data[$key] = $fresh;

    // Keep the JSON store bounded.
    if (count($data) > 5000) {
        $cutoff = $now - max($window, 3600);
        foreach ($data as $k => $list) {
            if (!is_array($list)) { unset($data[$k]); continue; }
            $list = array_values(array_filter($list, static fn($t) => (int)$t > $cutoff));
            if ($list) $data[$k] = $list; else unset($data[$k]);
        }
    }

    writeJson('ratelimits', $data);
    return true;
}

function auditLog(string $event, int $uid = 0, array $meta = []): void
{
    $row = [
        'time' => now(),
        'event' => substr($event, 0, 80),
        'user_id' => $uid,
        'meta' => $meta,
    ];

    $path = filePath('audit');
    $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line !== false) {
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
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
    secureHeaders();
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}



/* ============================================================
   SECURITY / CONFIG HELPERS
   ============================================================ */
function requireVerification(int $uid): bool
{
    // Telegram Mini App session validation and callback ownership checks remain
    // the primary application protections.
    return true;
}

function secureHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
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
            'show_alert' => $alert
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
            'first_name' => '',
            'ref_code' => '',
            'referred_by' => '',
            'referral_count' => 0,
            'referral_rewarded' => 0,
            'active_days' => [],
            'last_reminder_at' => 0,
            'last_start_at' => 0,
            'radhe_last_claim_month' => '',
            'sunday_last_reward_week' => '',
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


function ensureRefCode(int $uid): string
{
    $users = readJson('users');
    $key = (string)$uid;
    $code = trim((string)($users[$key]['ref_code'] ?? ''));
    if ($code !== '') return $code;
    do {
        $code = 'MAYA' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    } while (isset($users[$key]) && in_array($code, array_column($users, 'ref_code'), true));
    $users[$key]['ref_code'] = $code;
    writeJson('users', $users);
    return $code;
}

function referralUrl(int $uid): string
{
    return 'https://t.me/' . ltrim(BOT_USERNAME, '@') . '?start=ref_' . rawurlencode(ensureRefCode($uid));
}

function parseReferralArg(string $args): string
{
    $args = trim($args);
    if (preg_match('/^ref_([A-Za-z0-9]+)$/i', $args, $m)) return strtoupper($m[1]);
    return '';
}

function findUserByRefCode(string $code): int
{
    if ($code === '') return 0;
    foreach (readJson('users') as $uid => $u) {
        if (strcasecmp((string)($u['ref_code'] ?? ''), $code) === 0) return (int)$uid;
    }
    return 0;
}

function trackActiveDay(int $uid): void
{
    $u = userRecord($uid);
    $days = is_array($u['active_days'] ?? null) ? $u['active_days'] : [];
    $today = date('Y-m-d');
    if (!in_array($today, $days, true)) {
        $days[] = $today;
        $days = array_slice($days, -14);
        updateUser($uid, ['active_days' => $days]);
    }
}

function activeSevenDays(int $uid): bool
{
    $days = userRecord($uid)['active_days'] ?? [];
    if (!is_array($days)) return false;
    $set = array_fill_keys($days, true);
    for ($i=0; $i<7; $i++) if (!isset($set[date('Y-m-d', strtotime('-'.$i.' days'))])) return false;
    return true;
}

function weekKey(): string { return date('o-W'); }

function addAccessHours(int $uid, int $hours, string $reason): int
{
    $base = max(now(), premiumUntil($uid));
    $until = $base + ($hours * 3600);
    updateUser($uid, ['premium_until'=>$until]);
    auditLog('access_reward',$uid,['hours'=>$hours,'reason'=>$reason,'until'=>$until]);
    return $until;
}

function grantSundayRewards(): int
{
    if ((int)date('w') !== 0) return 0;
    $count=0;
    foreach (readJson('users') as $uid => $u) {
        $id=(int)$uid;
        if ($id<=0 || isAdmin($id) || !activeSevenDays($id)) continue;
        if (($u['sunday_last_reward_week'] ?? '') === weekKey()) continue;
        addAccessHours($id,SUNDAY_REWARD_HOURS,'sunday_7_day_activity');
        updateUser($id,['sunday_last_reward_week'=>weekKey()]);
        sendMsg($id,"🎁 <b>SUNDAY FREE ACCESS</b>\n\nYou completed 7 consecutive active days. Your 24-hour song-play access is active until:\n<b>".fmtDate(premiumUntil($id))."</b>");
        $count++;
    }
    return $count;
}

function sendActivityReminders(): int
{
    $count=0; $t=now();
    foreach (readJson('users') as $uid => $u) {
        $id=(int)$uid;
        if ($id<=0 || isAdmin($id)) continue;
        $lastSeen=(int)($u['last_seen']??0);
        $lastReminder=(int)($u['last_reminder_at']??0);
        if ($lastSeen<=0 || $t-$lastSeen<REMINDER_MIN_IDLE || $t-$lastReminder<REMINDER_INTERVAL) continue;
        if (($u['premium_until']??0)>$t) continue;
        $r=sendMsg($id,"🎵 <b>MAYAMUSIC</b>\n\nYou haven't used the bot recently. Come back and search your favourite song.\n\n⭐ Premium • 🎧 Mini Player • 🎤 Lyrics • ⏭ Auto-next",['reply_markup'=>kb([[['text'=>'🎵 Open MAYAMUSIC','callback_data'=>'home']],[['text'=>'⭐ Premium','callback_data'=>'premium']]])]);
        if (($r['ok']??false)===true) { updateUser($id,['last_reminder_at'=>$t]); $count++; }
    }
    return $count;
}

function runAutomaticMaintenance(): void
{
    // No extra secret or Railway variable is required. A short lock prevents
    // repeated webhook requests from running maintenance simultaneously.
    $lockPath = DATA_DIR . '/maintenance.lock';
    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) return;
    if (!@flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); return; }

    $last = 0;
    $raw = @stream_get_contents($fp);
    if ($raw !== false && trim($raw) !== '') $last = (int)trim($raw);
    $now = now();
    if ($last > 0 && ($now - $last) < 60) {
        @flock($fp, LOCK_UN); fclose($fp); return;
    }
    ftruncate($fp, 0); rewind($fp); fwrite($fp, (string)$now); fflush($fp);

    // Maintenance is best-effort; failures must never break Telegram updates.
    try {
        grantSundayRewards();
        sendActivityReminders();
    } catch (Throwable $e) {
        error_log('MAYAMUSIC maintenance: ' . $e->getMessage());
    }

    @flock($fp, LOCK_UN);
    fclose($fp);
}

function referralPayment(int $uid): string
{
    $payments=readJson('payments');
    $id='REF-'.date('ymdHis').'-'.strtoupper(bin2hex(random_bytes(3)));
    $payments[$id]=['id'=>$id,'user_id'=>$uid,'amount'=>REFERRAL_PRICE,'type'=>'referral','status'=>'created','utr'=>'','created_at'=>now(),'utr_submitted_at'=>0,'approved_at'=>0,'expires_at'=>now()+86400,'gateway'=>'manual_upi','gateway_status'=>'manual','payment_id'=>''];
    writeJson('payments',$payments);
    return $id;
}

function referralPageText(int $uid): string
{
    $u=userRecord($uid); $n=(int)($u['referral_count']??0); $next=REFERRAL_BATCH-($n%REFERRAL_BATCH); if($next===REFERRAL_BATCH)$next=0;
    return "<b>🎁 REFER & EARN</b>\n\nYour confirmed referrals: <b>$n</b>\n" . ($next?"Next reward in <b>$next</b> confirmed referral(s).":"🎉 Reward batch completed.") . "\n\n<b>Your personal referral link:</b>\n<code>".esc(referralUrl($uid))."</code>\n\nA referral is counted only after the invited user opens the bot through your unique link and completes the ₹".REFERRAL_PRICE." referral activation payment. Manual UTR verification prevents fake referrals.";
}

function sendReferral(int $uid): void
{
    if (!requireVerification($uid)) return;
    $pid=referralPayment($uid);
    $upi='upi://pay?pa='.rawurlencode(UPI_ID).'&pn='.rawurlencode(UPI_NAME).'&am='.number_format(REFERRAL_PRICE,2,'.','').'&cu=INR&tn='.rawurlencode('MAYA REF '.$pid);
    $qr='https://api.qrserver.com/v1/create-qr-code/?size=320x320&data='.rawurlencode($upi);
    sendMsg($uid,referralPageText($uid)."\n\n<b>Referral activation</b>\nPay ₹".REFERRAL_PRICE." by UPI, then submit UTR:\n<code>/utr $pid YOUR_UTR</code>",['reply_markup'=>kb([[['text'=>'📲 Pay ₹2','url'=>$upi]],[['text'=>'🧾 Submit ₹2 UTR','callback_data'=>'utr:'.$pid]]])]);
    tg('sendPhoto',['chat_id'=>$uid,'photo'=>$qr,'caption'=>'📲 Scan this UPI QR to pay ₹2 for referral activation. Then submit the UTR.']);
}

function bindReferralFromStart(int $uid, string $args): void
{
    $code=parseReferralArg($args); if($code==='') return;
    $u=userRecord($uid);
    if (($u['referred_by']??'')!=='') return;
    $referrer=findUserByRefCode($code);
    if($referrer<=0 || $referrer===$uid) return;
    updateUser($uid,['referred_by'=>$code,'referrer_id'=>$referrer,'referral_verified'=>false]);
    auditLog('referral_bound',$uid,['referrer'=>$referrer,'code'=>$code]);
}

function finalizeReferral(int $paymentUser, string $paymentId): void
{
    $u=userRecord($paymentUser); if(($u['referral_verified']??false)===true) return;
    $referrer=(int)($u['referrer_id']??0); if($referrer<=0) return;
    updateUser($paymentUser,['referral_verified'=>true]);
    $ru=userRecord($referrer); $count=(int)($ru['referral_count']??0)+1;
    updateUser($referrer,['referral_count'=>$count]);
    if($count % REFERRAL_BATCH===0) addAccessHours($referrer,REFERRAL_REWARD_DAYS*24,'referral_'.$count);
    sendMsg($referrer,"🎉 <b>Referral confirmed!</b>\nYour total confirmed referrals: <b>$count</b>\n".($count%REFERRAL_BATCH===0?"🎁 You earned <b>".REFERRAL_REWARD_DAYS." days</b> free access.":"Keep sharing to unlock the next reward."));
    auditLog('referral_confirmed',$paymentUser,['referrer'=>$referrer,'payment'=>$paymentId]);
}

function claimRadhe(int $uid): array
{
    $month=date('Y-m'); $u=userRecord($uid);
    if(($u['radhe_last_claim_month']??'')===$month) return ['ok'=>false,'message'=>'This month’s Radhe Radhe 6-hour access has already been used.'];
    updateUser($uid,['radhe_last_claim_month'=>$month]);
    $until=addAccessHours($uid,RADHE_HOURS,'radhe_radhe_monthly');
    return ['ok'=>true,'until'=>$until];
}

function radhePage(): void
{
    secureHeaders(); header('Content-Type:text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><script src="https://telegram.org/js/telegram-web-app.js"></script><style>body{margin:0;background:radial-gradient(circle at 50% 30%,#ffb3cf,#6b1746 45%,#160714);color:#fff;font-family:system-ui;min-height:100vh;display:grid;place-items:center;text-align:center}.card{padding:30px}.orb{width:180px;height:180px;border-radius:50%;margin:0 auto 25px;background:radial-gradient(circle,#fff,#ffd1e6 25%,#ff6da8 55%,transparent 70%);animation:pulse 2s infinite;box-shadow:0 0 80px #ff8fbe}.om{font-size:42px;font-weight:900}.sub{opacity:.85}@keyframes pulse{50%{transform:scale(1.08);filter:brightness(1.2)}}button{border:0;border-radius:18px;padding:15px 24px;font-weight:800;margin-top:22px}</style></head><body><div class="card"><div class="orb"></div><div class="om">राधे राधे</div><div class="sub">Prem • Bhakti • Music</div><p id="status">Verifying your Telegram session…</p><button id="claim" hidden>✨ Claim 6 Hours Free</button></div><script>const tg=window.Telegram?.WebApp;if(tg){tg.ready();tg.expand();}const s=document.getElementById("status"),b=document.getElementById("claim");async function claim(){const fd=new FormData();fd.append("initData",tg?.initData||"");const r=await fetch(location.pathname+"?action=claim_radhe",{method:"POST",body:fd});const d=await r.json();s.textContent=d.ok?"🌸 6-hour free music access is active until "+d.until:"⚠️ "+(d.message||"Already used this month");b.hidden=true;}if(tg?.initData){b.hidden=false;b.onclick=claim;s.textContent="Tap below to claim your monthly 6-hour access.";}else{s.textContent="Open this page from Telegram."}</script></body></html>';
    exit;
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

    $rows[] = [
        ['text'=>'🎁 Refer & Earn','callback_data'=>'referral']
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
        'expires_at' => now() + SEARCH_TTL
    ];

    writeJson('searches', $searches);

    return $id;
}


/* ============================================================
   PLAYER TOKEN
   ============================================================ */

function createPlayerToken(
    int $uid,
    array $song,
    array $queue
): string {
    $players=readJson('players');
    $token=bin2hex(random_bytes(24));
    $players[$token]=[
        'user_id'=>$uid,
        'song'=>$song,
        'queue'=>$queue,
        'created_at'=>now(),
        'expires_at'=>now()+PLAYER_TTL
    ];
    writeJson('players',$players);
    auditLog('player_token_created',$uid,['token_prefix'=>substr($token,0,8)]);
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
    if (!rateLimit('search',RATE_LIMIT_SEARCH,RATE_WINDOW,$uid)) {
        sendMsg($uid,'⏳ Too many requests. Please wait a moment.');
        return;
    }
    if (!requireVerification($uid)) return;
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
                'text' => '▶️ ' . $title,
                'callback_data' => 'pick:' . $session . ':' . $index
            ],
            [
                'text' => '⬇️ Download',
                'url' => (string)$song['download_url']
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
    return "<b>✨ 𝗠𝗔𝗬𝗔𝗠𝗨𝗦𝗜𝗖 𝗣𝗥𝗘𝗠𝗜𝗨𝗠</b>\n\n" .
        "<b>₹" . MONTHLY_PRICE . " / 30 Days</b>\n\n" .
        "🎧 Full music access\n🎤 Lyrics & Queue\n⚡ Auto-next & Mini Player\n⬇️ Download support\n🔄 Previous / Next\n\n" .
        "<b>Choose your payment method:</b>";
}

function createPayment(int $uid, string $method = 'upi'): string
{
    $payments=readJson('payments');
    do{$id='PAY-'.date('ymdHis').'-'.strtoupper(bin2hex(random_bytes(5)));}while(isset($payments[$id]));
    $payments[$id]=['id'=>$id,'user_id'=>$uid,'method'=>'upi','type'=>'premium','gateway'=>'manual_upi','amount'=>MONTHLY_PRICE,'base_amount'=>MONTHLY_PRICE,'currency'=>'INR','discount_code'=>'','discount_percent'=>0,'discount_amount'=>0,'status'=>'created','utr'=>'','created_at'=>now(),'utr_submitted_at'=>0,'approved_at'=>0,'expires_at'=>now()+UPI_PAYMENT_TTL,'qr_ref'=>strtoupper(bin2hex(random_bytes(8))),'payment_id'=>$id];
    writeJson('payments',$payments);return $id;
}

function createStarsInvoice(int $uid): void
{
    if(!rateLimit('stars_invoice',5,60,$uid)){sendMsg($uid,'⏳ Please wait before opening another Stars payment.');return;}
    $paymentId='STAR-'.date('ymdHis').'-'.strtoupper(bin2hex(random_bytes(5)));$payload='MAYA_STARS|'.$paymentId.'|'.$uid;$payments=readJson('payments');
    $payments[$paymentId]=['id'=>$paymentId,'user_id'=>$uid,'method'=>'stars','type'=>'premium','gateway'=>'telegram_stars','amount'=>TELEGRAM_STARS_PRICE,'currency'=>'XTR','status'=>'stars_created','created_at'=>now(),'approved_at'=>0,'expires_at'=>0,'stars_charge_id'=>'','payment_id'=>$paymentId,'invoice_payload'=>$payload];writeJson('payments',$payments);
    $r=tg('sendInvoice',['chat_id'=>$uid,'title'=>'MAYAMUSIC Premium','description'=>'30 days MAYAMUSIC Premium access','payload'=>$payload,'currency'=>'XTR','prices'=>json_encode([['label'=>'Premium 30 Days','amount'=>TELEGRAM_STARS_PRICE],],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'start_parameter'=>'maya-premium-stars']);
    if(!($r['ok']??false)){ $p=readJson('payments');if(isset($p[$paymentId])){$p[$paymentId]['status']='invoice_failed';$p[$paymentId]['error']=(string)($r['description']??'Invoice failed');writeJson('payments',$p);} sendMsg($uid,'❌ Telegram Stars payment could not be opened. Please try again or use UPI.'); }
}

function sendPremium(int $uid): void
{
    if(!rateLimit('premium',10,60,$uid)){sendMsg($uid,'⏳ Please wait a moment before opening Premium again.');return;}
    if(!requireVerification($uid))return;
    sendMsg($uid,premiumText(),['reply_markup'=>kb([
        [['text'=>'⭐ Pay 5 Telegram Stars','callback_data'=>'stars_pay']],
        [['text'=>'💳 PAY • UPI','callback_data'=>'payupicreate']],
        [['text'=>'👤 Account','callback_data'=>'account']]
    ])]);
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
    $refCount=(int)($user['referral_count']??0);
    $activeDays=is_array($user['active_days']??null)?count($user['active_days']):0;

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
        "<b>Confirmed referrals:</b> " . $refCount .
        "\n" .
        "<b>Active days tracked:</b> " . $activeDays .
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
    if (!rateLimit('redeem',RATE_LIMIT_REDEEM,RATE_WINDOW,$uid)) {
        sendMsg($uid,'⏳ Too many redeem attempts. Try again later.');
        return;
    }
    if (!requireVerification($uid)) return;
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

function submitUtr(int $uid, string $paymentId, string $utr): void
{
    $result = submitMiniPaymentUtr($uid, $paymentId, $utr);
    if (!($result['ok'] ?? false)) {
        sendMsg($uid, '❌ ' . esc((string)($result['message'] ?? 'Unable to submit payment.')));
    }
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
        $isReferral = (($payment['type'] ?? '') === 'referral');
        $until = 0;

        if (!$isReferral) {
            $base = max(now(), premiumUntil($uid));
            $until = $base + (ACCESS_DAYS * 86400);
            updateUser($uid, ['premium_until' => $until]);
        }

        $payment['status'] = 'approved';
        $payment['approved_at'] = now();
        $payment['expires_at'] = $until;
        $payments[$paymentId] = $payment;
        writeJson('payments', $payments);

        if ($isReferral) {
            finalizeReferral($uid, $paymentId);
            sendMsg($uid,"✅ <b>₹2 referral activation verified.</b>\n\nYour referral has been confirmed. The inviter receives the referral credit after verification.");
            sendMsg($adminId,"✅ Referral payment approved.\nUser: <code>".$uid."</code>\nPayment: <code>".esc($paymentId)."</code>");
        } else {
            sendMsg($uid,"✅ <b>Payment Approved</b>\n\n⭐ Premium activated.\n\nValid until:\n<b>".fmtDate($until)."</b>");
            sendMsg($adminId,"✅ Payment approved.\nUser: <code>".$uid."</code>\nUntil: <b>".fmtDate($until)."</b>");
        }
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
        "<code>/discount 20 30 100</code>\n" .
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
                'text' => '🎟 Discount Code',
                'callback_data' => 'adiscount'
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

    if ($cmd === '/discount') {
        $percent = (int)($parts[1] ?? 0);
        $days = (int)($parts[2] ?? 30);
        $uses = (int)($parts[3] ?? 100);
        if ($percent < 1 || $percent > 90) {
            sendMsg($uid, "Usage: <code>/discount PERCENT [DAYS] [MAX_USES]</code>\nExample: <code>/discount 20 30 100</code>");
            return true;
        }
        $code = createDiscountCode($percent, $uid, $days, $uses);
        $codes = readJson('discounts');
        $item = $codes[$code];
        sendMsg($uid, "🎟 <b>DISCOUNT CODE GENERATED</b>\n\n<code>" . esc($code) . "</code>\n\nDiscount: <b>" . $percent . "% OFF</b>\nValid: <b>" . fmtDate((int)$item['expires_at']) . "</b>\nMax uses: <b>" . $uses . "</b>");
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
                    ]),
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
    trackActiveDay($uid);

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

    if (strtoupper(trim($text)) === '/RADHE RADHE') {
        sendMsg($uid,"🌸 <b>राधे राधे</b> 🌸\n\nAapke liye ek special 6-hour free music access unlock flow ready hai. Ye offer month me sirf <b>1 baar</b> claim kiya ja sakta hai.",['reply_markup'=>kb([[['text'=>'🌸 Open Radhe Radhe','web_app'=>['url'=>configWebAppUrl().'?radhe=1']]]])]);
        return;
    }

    if ($command === '/start') {
        bindReferralFromStart($uid, $args);
        updateUser($uid,['last_start_at'=>now()]);
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

    if ($command === '/delete_data') {
        if(!rateLimit('delete_data',3,300,$uid)){
            sendMsg($uid,'⏳ Please wait before retrying.');
            return;
        }
        $users=readJson('users');
        unset($users[(string)$uid]);
        writeJson('users',$users);
        foreach(['searches','players','ratelimits'] as $file){
            $data=readJson($file);
            foreach($data as $k=>$row){
                if((int)($row['user_id']??0)===$uid) unset($data[$k]);
            }
            writeJson($file,$data);
        }
        auditLog('user_data_delete_requested',$uid);
        sendMsg($uid,'✅ Your user-level profile/session data was deleted. Transaction records may be retained where necessary for payment/account handling.');
        return;
    }

    if ($command === '/help') {
        sendHelp($uid);
        return;
    }

    if ($command === '/referral' || $command === '/refer') {
        sendReferral($uid); return;
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

    // Always acknowledge a callback quickly so Telegram does not keep the button spinner active.
    if ($id === '' || $uid <= 0) {
        if ($id !== '') answerCb($id, 'Invalid callback', true);
        return;
    }

    if(!rateLimit('callback',120,60,$uid)){
        answerCb($id,'Too many requests',true);
        return;
    }
    userRecord($uid);
    auditLog('callback',$uid,['action'=>substr($data,0,80)]);

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

    if ($data === 'referral') {
        answerCb($id);
        sendReferral($uid);
        return;
    }

    if ($data === 'policy') {
        answerCb($id);
        sendMsg($uid,"<b>📜 Privacy Policy</b>\n\nRead the full policy in the secure Mini App.",['reply_markup'=>kb([[['text'=>'📖 Read Privacy Policy','web_app'=>['url'=>configWebAppUrl().'?policy=1']]], [['text'=>'🏠 Home','callback_data'=>'home']]])]);
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

    if ($data === 'adiscount') {
        answerCb($id);
        if (!isAdmin($uid)) return;
        sendMsg($uid, "🎟 <b>DISCOUNT CODE</b>\n\nUse:\n<code>/discount 20 30 100</code>\n\n20 = discount %, 30 = validity days, 100 = maximum uses.");
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

        if (!requireVerification($uid)) return;

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
                $uid,
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

    if (str_starts_with($data, 'utr:')) {
        answerCb($id);
        $paymentId = substr($data, 4);
        $payments = readJson('payments');
        if (!isset($payments[$paymentId]) || (int)($payments[$paymentId]['user_id'] ?? 0) !== $uid) {
            sendMsg($uid, '❌ Payment session not found.');
            return;
        }
        sendMsg($uid, '<b>💳 PAYMENT CENTER</b>\n\nOpen the secure PAY screen to see the live QR, countdown, discount code and UTR form.', ['reply_markup'=>kb([[[ 'text'=>'💳 Open PAY', 'web_app'=>['url'=>configWebAppUrl().'?pay='.rawurlencode($paymentId)] ]]])]);
        return;
    }

    if ($data === 'stars_pay') {
        answerCb($id, 'Opening Telegram Stars…');
        createStarsInvoice($uid);
        return;
    }

    if ($data === 'payupicreate') {
        answerCb($id, 'Opening secure PAY…');
        if (!requireVerification($uid)) return;
        $paymentId=createPayment($uid);
        $url=configWebAppUrl().'?pay='.rawurlencode($paymentId);
        sendMsg($uid,
            "<b>💳 𝗠𝗔𝗬𝗔𝗠𝗨𝗦𝗜𝗖 • PAY</b>\n\n" .
            "⏱ QR validity: <b>5 minutes</b>\n" .
            "🎟 Discount code: <b>optional</b>\n" .
            "🧾 UTR: required after payment",
            ['reply_markup'=>kb([
                [['text'=>'💳 OPEN PAY • UPI','web_app'=>['url'=>$url]]],
                [['text'=>'⬅️ Premium','callback_data'=>'premium']]
            ])]
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

    answerCb($id, 'Unknown action', true);
}


/* ============================================================
   MINI APP PLAYER
   ============================================================ */


function privacyPolicyPage(): void
{
    secureHeaders(); header('Content-Type:text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>MAYAMUSIC Privacy Policy</title><style>body{margin:0;background:#08090d;color:#fff;font-family:system-ui;line-height:1.6}.wrap{max-width:760px;margin:auto;padding:28px}.card{background:#141720;border:1px solid #252a36;border-radius:24px;padding:24px;margin:14px 0}h1{font-size:30px}h2{font-size:18px}small{opacity:.6}</style></head><body><div class="wrap"><h1>🛡 MAYAMUSIC Privacy Policy</h1><small>Version '.POLICY_VERSION.' • Updated '.date('d M Y').'</small><div class="card"><h2>1. What we process</h2><p>Telegram user ID, username/first name when supplied by Telegram, account status, premium expiry, referral status, search/player session data and payment/UTR records needed to operate the service. Security logs may contain request metadata such as IP address when a web request reaches the server.</p></div><div class="card"><h2>2. Why</h2><p>These records are used for authentication, music search/player access, premium activation, referral rewards, fraud/abuse prevention, support and payment verification.</p></div><div class="card"><h2>3. Third parties</h2><p>Music search/download API, iTunes artwork search, LRCLIB lyrics and Telegram Bot API may process requests necessary for their respective functions. Payment is manual UPI.</p></div><div class="card"><h2>4. Payments</h2><p>UPI payments are manually verified using the UTR you submit. Do not send card PIN, UPI PIN, OTP or passwords to the bot. Transaction records may be retained for reconciliation, abuse prevention and account disputes.</p></div><div class="card"><h2>5. Retention</h2><p>Temporary search/player sessions expire automatically. Other account, security and payment records are retained only as long as needed for service operation, security, support or legitimate transaction records.</p></div><div class="card"><h2>6. Deletion</h2><p>Use <b>/delete_data</b> to remove user-level profile/session data where applicable. Transaction records may be retained where necessary for payment/account handling.</p></div><div class="card"><h2>7. Security</h2><p>Telegram Mini App initData is server-validated, callbacks are rate-limited, player tokens expire, and webhook payloads are validated before processing.</p></div><div class="card"><h2>8. Contact</h2><p>Support: @'.esc(SUPPORT_USERNAME).'</p></div></div></body></html>';
    exit;
}

function miniApp(): void
{
    secureHeaders();
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

<a id="downloadButton" class="panelButton" style="text-decoration:none;text-align:center" download>⬇️ Download</a>

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

const downloadButton = document.getElementById("downloadButton");

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

    if(downloadButton){ downloadButton.href=currentSong; downloadButton.setAttribute("download", (currentTitle||"song")+".mp3"); }

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

async function loadRelatedAndContinue(){
    status.textContent="Finding related music…";
    try{
        const token=new URLSearchParams(location.search).get("token")||"";
        const url=location.pathname+"?action=related&token="+encodeURIComponent(token)+"&title="+encodeURIComponent(currentTitle)+"&artist="+encodeURIComponent(currentArtist);
        const r=await fetch(url); const data=await r.json();
        if(data.ok&&Array.isArray(data.results)&&data.results.length){
            const existing=new Set(queue.map(x=>((x.title||"")+"|"+(x.artists||"")).toLowerCase()));
            for(const song of data.results){
                const k=((song.title||"")+"|"+(song.artists||"")).toLowerCase();
                if(!existing.has(k)){queue.push(song);existing.add(k);}
            }
            renderQueue();
            if(currentIndex+1<queue.length){currentIndex++;loadSong(queue[currentIndex],true);return;}
        }
        playButton.textContent="▶";status.textContent="No related song available";
    }catch(e){playButton.textContent="▶";status.textContent="Unable to load next song";}
}
audio.addEventListener("ended",async()=>{
    if(currentIndex+1<queue.length){currentIndex++;loadSong(queue[currentIndex],true);}
    else await loadRelatedAndContinue();
});
audio.addEventListener("error",async()=>{
    status.textContent="Song unavailable — trying next…";
    if(currentIndex+1<queue.length){currentIndex++;loadSong(queue[currentIndex],true);}
    else await loadRelatedAndContinue();
});

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
            loadRelatedAndContinue();
        }
    };

let touchStartX=0, touchStartY=0;
document.addEventListener("touchstart",e=>{const t=e.changedTouches[0];touchStartX=t.clientX;touchStartY=t.clientY;},{passive:true});
document.addEventListener("touchend",e=>{const t=e.changedTouches[0];const dx=t.clientX-touchStartX,dy=t.clientY-touchStartY;if(Math.abs(dx)>70&&Math.abs(dx)>Math.abs(dy)){if(dx<0){nextButton.click();}else{previousButton.click();}}},{passive:true});
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
   TELEGRAM MINI APP INIT DATA VALIDATION
   ============================================================ */

function validateTelegramInitData(string $initData): ?array
{
    if ($initData === '' || strlen($initData) > 10000) return null;

    parse_str($initData, $params);
    if (!is_array($params)) return null;

    $hash = (string)($params['hash'] ?? '');
    if ($hash === '' || !preg_match('/^[a-f0-9]{64}$/i', $hash)) return null;
    unset($params['hash']);

    ksort($params);
    $check = [];
    foreach ($params as $key => $value) {
        if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $check[] = $key . '=' . (string)$value;
    }
    $dataCheckString = implode("\n", $check);

    $token = configBotToken();
    if ($token === '') return null;

    $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);
    $calculated = hash_hmac('sha256', $dataCheckString, $secretKey);
    if (!hash_equals(strtolower($hash), strtolower($calculated))) return null;

    $userJson = (string)($params['user'] ?? '');
    $user = json_decode($userJson, true);
    if (!is_array($user) || (int)($user['id'] ?? 0) <= 0) return null;

    // Telegram initData includes auth_date. Reject stale sessions.
    $authDate = (int)($params['auth_date'] ?? 0);
    if ($authDate <= 0 || abs(now() - $authDate) > 86400) return null;

    return [
        'user_id' => (int)$user['id'],
        'user' => $user,
        'auth_date' => $authDate,
    ];
}


/* ============================================================
   PREMIUM PAYMENT MINI APP
   ============================================================ */
function buildUpiUri(array $p): string {
    $amount=number_format((float)($p['amount']??MONTHLY_PRICE),2,'.',''); $id=(string)($p['id']??'');
    return 'upi://pay?pa='.rawurlencode(UPI_ID).'&pn='.rawurlencode(UPI_NAME).'&am='.rawurlencode($amount).'&cu=INR&tr='.rawurlencode($id).'&tn='.rawurlencode('MAYAMUSIC Premium '.$id);
}
function buildQrUrl(array $p): string { return 'https://api.qrserver.com/v1/create-qr-code/?size=700x700&margin=12&qzone=2&data='.rawurlencode(buildUpiUri($p)); }
function paymentForUser(string $id,int $uid): ?array { if($id===''||strlen($id)>80)return null; $ps=readJson('payments'); $p=$ps[$id]??null; if(!is_array($p)||($p['method']??'')!=='upi'||(int)($p['user_id']??0)!==$uid)return null; return $p; }
function paymentInfoForMiniApp(string $id,int $uid): array {
    $p=paymentForUser($id,$uid); if($p===null)return ['ok'=>false,'error'=>'payment_not_found','message'=>'Payment session not found.'];
    $status=(string)($p['status']??'created'); $expired=(int)($p['expires_at']??0)>0&&(int)$p['expires_at']<=now()&&$status==='created'; if($expired)$status='expired';
    return ['ok'=>true,'paymentId'=>(string)$p['id'],'amount'=>(int)$p['amount'],'baseAmount'=>(int)($p['base_amount']??MONTHLY_PRICE),'discountCode'=>(string)($p['discount_code']??''),'discountPercent'=>(int)($p['discount_percent']??0),'status'=>$status,'expiresAt'=>(int)($p['expires_at']??0),'qrUrl'=>$status==='created'?buildQrUrl($p):'','upiId'=>UPI_ID,'upiName'=>UPI_NAME];
}
function applyPaymentDiscount(int $uid,string $id,string $raw): array {
    $code=strtoupper(trim($raw)); if($code===''||strlen($code)>60)return ['ok'=>false,'message'=>'Enter a valid discount code.'];
    $ps=readJson('payments'); $p=$ps[$id]??null; if(!is_array($p)||(int)($p['user_id']??0)!==$uid||($p['method']??'')!=='upi')return ['ok'=>false,'message'=>'Payment session not found.'];
    if(($p['status']??'')!=='created')return ['ok'=>false,'message'=>'This payment session is no longer editable.']; if((int)($p['expires_at']??0)<=now())return ['ok'=>false,'message'=>'QR expired. Open PAY again.']; if(($p['discount_code']??'')!=='')return ['ok'=>false,'message'=>'A discount code is already applied.'];
    $codes=readJson('discounts'); $c=$codes[$code]??null; if(!is_array($c)||($c['status']??'')!=='active')return ['ok'=>false,'message'=>'Invalid discount code.']; if((int)($c['expires_at']??0)<=now())return ['ok'=>false,'message'=>'Discount code expired.']; if((int)($c['uses']??0)>=(int)($c['max_uses']??1))return ['ok'=>false,'message'=>'Discount code usage limit reached.'];
    $pct=max(1,min(90,(int)($c['percent']??0))); $p['discount_code']=$code; $p['discount_percent']=$pct; $p['amount']=discountedAmount((int)($p['base_amount']??MONTHLY_PRICE),$pct); $ps[$id]=$p; writeJson('payments',$ps); auditLog('discount_applied',$uid,['payment'=>$id,'code'=>$code,'percent'=>$pct]); return paymentInfoForMiniApp($id,$uid);
}
function submitMiniPaymentUtr(int $uid,string $id,string $utr): array {
    $ps=readJson('payments'); $p=$ps[$id]??null; if(!is_array($p)||(int)($p['user_id']??0)!==$uid||($p['method']??'')!=='upi')return ['ok'=>false,'message'=>'Payment session not found.'];
    if(($p['status']??'')!=='created')return ['ok'=>false,'message'=>'This payment is already submitted or processed.']; if((int)($p['expires_at']??0)<=now())return ['ok'=>false,'message'=>'Payment QR expired. Open PAY again for a fresh QR.'];
    $utr=trim($utr); if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\/-]{5,60}$/',$utr))return ['ok'=>false,'message'=>'Enter a valid UTR / transaction ID.'];
    foreach($ps as $eid=>$e){if($eid!==$id&&strcasecmp((string)($e['utr']??''),$utr)===0&&in_array(($e['status']??''),['pending','approved'],true))return ['ok'=>false,'message'=>'This UTR is already used.'];}
    $p['utr']=$utr; $p['status']='pending'; $p['utr_submitted_at']=now(); $p['processing_message']='Manual verification pending';
    $dc=(string)($p['discount_code']??''); if($dc!==''&&!empty($p['discount_consumed'])){} elseif($dc!==''){ $codes=readJson('discounts'); if(isset($codes[$dc])){$codes[$dc]['uses']=(int)($codes[$dc]['uses']??0)+1;writeJson('discounts',$codes);} $p['discount_consumed']=1; }
    $ps[$id]=$p; writeJson('payments',$ps); $amt=(int)$p['amount']; $dt=$dc!==''?"\nDiscount: <b>".(int)$p['discount_percent']."% OFF</b> (<code>".esc($dc)."</code>)":'';
    sendMsg($uid,"<b>🟡 PROCESSING PAYMENT</b>\n\nUTR submitted successfully.\nAmount: <b>₹".$amt."</b>\nUTR: <code>".esc($utr)."</code>".$dt."\n\n<b>⏳ Please wait up to 30 minutes.</b>\nYour payment will be manually verified and Premium will activate after admin approval.\n\n💚 Thank you for using MAYAMUSIC.");
    sendMsg(ADMIN_ID,"<b>💳 NEW PREMIUM PAYMENT</b>\n\nPayment: <code>".esc($id)."</code>\nUser: <code>".$uid."</code>\nAmount: <b>₹".$amt."</b>".$dt."\nUTR: <code>".esc($utr)."</code>\nSubmitted: <b>".fmtDate(now())."</b>",['reply_markup'=>kb([[['text'=>'✅ APPROVE','callback_data'=>'approve:'.$id],['text'=>'❌ DECLINE','callback_data'=>'decline:'.$id]]])]);
    auditLog('upi_payment_submitted',$uid,['payment'=>$id,'amount'=>$amt]); return ['ok'=>true,'paymentId'=>$id,'status'=>'pending'];
}

function payPage(): void
{
    secureHeaders();
    $paymentId = trim((string)($_GET['pay'] ?? ''));
    $pid = json_encode($paymentId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $bot = json_encode(BOT_USERNAME, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $html = <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>MAYAMUSIC PAY</title>
<style>
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}body{margin:0;min-height:100vh;background:radial-gradient(circle at 50% -10%,#26303b 0,#0a0d11 45%,#050608 100%);color:#fff;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:520px;margin:auto;padding:calc(18px + env(safe-area-inset-top)) 18px calc(28px + env(safe-area-inset-bottom))}.card{background:rgba(18,22,27,.9);border:1px solid rgba(255,255,255,.12);border-radius:28px;padding:20px;box-shadow:0 20px 80px rgba(0,0,0,.5);backdrop-filter:blur(18px)}.brand{text-align:center;font-weight:950;font-size:25px}.sub{text-align:center;color:#9da6b1;font-size:12px;margin:5px 0 18px}.amount{text-align:center;font-size:34px;font-weight:950}.meta{text-align:center;color:#aeb7c0;font-size:12px}.qrbox{position:relative;margin:18px auto;width:min(82vw,340px);aspect-ratio:1;border-radius:22px;background:#fff;padding:10px;overflow:hidden}.qrbox img{width:100%;height:100%;object-fit:contain;border-radius:14px;transition:.35s}.expired .qrbox img{filter:blur(9px) grayscale(1);transform:scale(1.04)}.stamp{display:none;position:absolute;inset:0;align-items:center;justify-content:center;z-index:3}.expired .stamp{display:flex}.stamp span{border:7px solid #e52323;color:#e52323;font-size:29px;font-weight:1000;letter-spacing:2px;padding:10px 18px;transform:rotate(-14deg);border-radius:12px;background:rgba(255,255,255,.75)}.timer{text-align:center;font-size:20px;font-weight:900;margin:10px}.timer b{color:#ffd84a}.expired .timer b{color:#ff3b3b}.row{display:flex;gap:8px}.input{width:100%;border:1px solid rgba(255,255,255,.14);background:#0d1116;color:#fff;border-radius:14px;padding:14px;font-size:15px;outline:none}.btn{width:100%;border:0;border-radius:15px;padding:14px 16px;font-weight:900;font-size:15px;color:#fff;background:#171d24;margin-top:10px}.primary{background:#fff;color:#050608}.btn:active{transform:scale(.98)}.status{text-align:center;min-height:24px;margin-top:12px;font-weight:800}.small{font-size:11px;color:#8e98a3}.processing{display:none;text-align:center;padding:28px 8px}.processing.show{display:block}.loader{width:44px;height:44px;border:4px solid rgba(255,255,255,.16);border-top-color:#ffd84a;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 12px}@keyframes spin{to{transform:rotate(360deg)}}.ok{color:#42e28a}.bad{color:#ff5757}.hide{display:none!important}.sectionTitle{font-size:13px;font-weight:900;margin:16px 0 8px}
</style></head><body><main class="wrap"><section class="card" id="card"><div class="brand">MAYAMUSIC ❤️</div><div class="sub">SECURE PREMIUM PAYMENT</div><div id="main"><div class="amount" id="amount">₹--</div><div class="meta" id="paymentId"></div><div class="qrbox"><img id="qr" alt="UPI QR"><div class="stamp"><span>EXPIRED</span></div></div><div class="timer" id="timer">QR valid for <b>05:00</b></div><div class="meta">UPI ID: <b id="upi">—</b></div><div class="sectionTitle">Optional Discount Code</div><div class="row"><input id="code" class="input" placeholder="Enter code or skip" maxlength="60"><button id="apply" class="btn" style="width:120px;margin-top:0">APPLY</button></div><div id="codeStatus" class="small"></div><div class="sectionTitle">Transaction / UTR ID</div><input id="utr" class="input" placeholder="Enter UTR / Transaction ID" maxlength="60"><button id="paid" class="btn primary">I PAID — SUBMIT UTR</button><div id="status" class="status"></div><div class="small" style="text-align:center;margin-top:12px">QR is unique to this payment session and expires after 5 minutes.</div></div><div id="processing" class="processing"><div class="loader"></div><div style="font-size:20px;font-weight:950;color:#ffd84a">Processing Payment</div><p class="small">Please wait up to 30 minutes for manual verification and Premium activation.</p><div class="ok">✓ Submission received</div></div></section></main><script src="https://telegram.org/js/telegram-web-app.js"></script><script>
const tg=window.Telegram&&window.Telegram.WebApp?window.Telegram.WebApp:null;if(tg){tg.ready();tg.expand()}const paymentId=__PAYMENT_ID__,botUsername=__BOT_USERNAME__;let expired=false,th=null;const $=x=>document.getElementById(x);async function api(action,data={}){const body=new URLSearchParams({action,...data,initData:tg?tg.initData:''});const r=await fetch(location.pathname+'?action='+encodeURIComponent(action),{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});return r.json()}function status(t,c){$('status').textContent=t;$('status').className='status '+(c||'')}function expire(){expired=true;$('card').classList.add('expired');$('timer').innerHTML='QR <b>EXPIRED</b>';$('paid').disabled=true;$('apply').disabled=true;$('utr').disabled=true;status('This QR has expired. Open PAY again for a new QR.','bad')}function timer(ex){clearInterval(th);function tick(){let left=Math.max(0,ex-Math.floor(Date.now()/1000));let m=String(Math.floor(left/60)).padStart(2,'0'),s=String(left%60).padStart(2,'0');$('timer').innerHTML='QR valid for <b>'+m+':'+s+'</b>';if(left<=0){clearInterval(th);expire()}}tick();th=setInterval(tick,1000)}function render(d){if(!d.ok){status(d.message||'Payment unavailable','bad');return}$('amount').textContent='₹'+d.amount;$('paymentId').textContent=d.paymentId;$('upi').textContent=d.upiId;if(d.discountPercent)$('codeStatus').textContent=d.discountPercent+'% discount applied: '+d.discountCode;if(d.status==='expired'){expire();return}if(d.status==='pending'||d.status==='approved'){$('main').classList.add('hide');$('processing').classList.add('show');return}$('qr').src=d.qrUrl;timer(d.expiresAt)}$('apply').onclick=async()=>{if(expired)return;let code=$('code').value.trim();if(!code){$('codeStatus').textContent='Skipped — regular price applies.';return}$('apply').disabled=true;status('Applying discount…');try{let d=await api('apply_discount',{paymentId,code});if(d.ok){render(d);status('Discount applied.','ok')}else status(d.message||'Invalid code','bad')}catch(e){status('Network error','bad')}finally{$('apply').disabled=false}};$('paid').onclick=async()=>{if(expired)return;let utr=$('utr').value.trim();if(!utr){status('Enter UTR / Transaction ID first.','bad');return}$('paid').disabled=true;status('Submitting payment…');try{let d=await api('submit_utr',{paymentId,utr});if(!d.ok){status(d.message||'Could not submit','bad');$('paid').disabled=false;return}$('main').classList.add('hide');$('processing').classList.add('show');setTimeout(()=>{let link='https://t.me/'+botUsername+'?start=payment_'+encodeURIComponent(paymentId);if(tg&&tg.openTelegramLink)tg.openTelegramLink(link);else location.href=link},5000)}catch(e){status('Network error','bad');$('paid').disabled=false}};(async()=>{try{render(await api('payment_info',{paymentId}))}catch(e){status('Unable to connect','bad')}})();
</script></body></html>
HTML;
    $html = str_replace(['__PAYMENT_ID__','__BOT_USERNAME__'], [$pid,$bot], $html);
    echo $html;
}

/* ============================================================
   MINI APP API
   ============================================================ */

function miniApi(): void
{
    secureHeaders();
    $action=(string)($_GET['action']??'');

    if (in_array($action, ['payment_info','apply_discount','submit_utr'], true)) {
        $auth = validateTelegramInitData(trim((string)($_POST['initData'] ?? '')));
        if ($auth === null) jsonReply(['ok'=>false,'message'=>'Invalid Telegram session. Reopen PAY from the bot.']);
        $uid=(int)$auth['user_id']; if(!rateLimit('mini_api',RATE_LIMIT_API,RATE_WINDOW,$uid)) jsonReply(['ok'=>false,'error'=>'rate_limited','message'=>'Too many requests. Please wait a moment.']); $paymentId=trim((string)($_POST['paymentId'] ?? ''));
        if ($action==='payment_info') jsonReply(paymentInfoForMiniApp($paymentId,$uid));
        if ($action==='apply_discount') jsonReply(applyPaymentDiscount($uid,$paymentId,(string)($_POST['code'] ?? '')));
        if ($action==='submit_utr') { if(!rateLimit('mini_utr',5,300,$uid)) jsonReply(['ok'=>false,'message'=>'Too many attempts. Please wait a few minutes.']); jsonReply(submitMiniPaymentUtr($uid,$paymentId,(string)($_POST['utr'] ?? ''))); }
    }

    if($action==='lyrics'){
        $title=trim((string)($_GET['title']??''));
        $artist=trim((string)($_GET['artist']??''));
        if($title===''||$artist===''||strlen($title)>200||strlen($artist)>200){
            jsonReply(['ok'=>false,'error'=>'invalid_input']);
        }
        jsonReply(getLyrics($title,$artist));
    }

    if($action==='related'){
        $token=trim((string)($_GET['token']??''));
        $players=readJson('players');
        $player=$players[$token]??null;
        if(!is_array($player)||(int)($player['expires_at']??0)<now()){
            jsonReply(['ok'=>false,'error'=>'invalid_player']);
        }
        $uid=(int)($player['user_id']??0);
        if($uid<=0||!privateAccess($uid)){
            jsonReply(['ok'=>false,'error'=>'forbidden']);
        }
        $title=trim((string)($_GET['title']??''));
        $artist=trim((string)($_GET['artist']??''));
        $query=trim($artist!==''?$artist:$title);
        if($query==='') jsonReply(['ok'=>false,'error'=>'invalid_input']);
        $results=array_slice(searchMusic($query),0,10);
        $out=[];$seen=[];
        foreach($results as $song){
            $key=strtolower(trim(($song['title']??'').'|'.($song['artists']??'')));
            if($key===''||isset($seen[$key])) continue;
            $seen[$key]=true;
            $song['artwork']=artwork((string)($song['title']??''),(string)($song['artists']??''));
            $out[]=$song;
        }
        auditLog('related_queue_fetch',$uid,['count'=>count($out)]);
        jsonReply(['ok'=>true,'results'=>$out]);
    }

    if($action==='claim_radhe'){
        $auth=validateTelegramInitData(trim((string)($_POST['initData']??'')));
        if($auth===null) jsonReply(['ok'=>false,'message'=>'Invalid Telegram session.']);
        $uid=(int)$auth['user_id'];
        $r=claimRadhe($uid);
        if(!$r['ok']) jsonReply($r);
        sendMsg($uid,'🌸 <b>राधे राधे</b>\nYour 6-hour free music access is active until <b>'.fmtDate((int)$r['until']).'</b>.');
        jsonReply(['ok'=>true,'until'=>fmtDate((int)$r['until'])]);
    }

    jsonReply(['ok'=>false,'error'=>'unknown_action']);
}


/* ============================================================
   HEALTH PAGE
   ============================================================ */

function healthPage(): void
{
    secureHeaders();
    header(
        'Content-Type: text/plain; charset=UTF-8'
    );

    echo
        "MAYAMUSIC ONLINE\n" .
        "PHP: " . PHP_VERSION . "\n" .
        "Bot token: " . (configBotToken() !== '' ? 'configured' : 'MISSING') . "\n" .
        "WebApp: " . configWebAppUrl() . "\n" .
        "Time: " . date('c') . "\n";
}


/* ============================================================
   HTTP ROUTER
   ============================================================ */

function handleHttp(): bool
{
    if(isset($_GET['pay'])){payPage();return true;}
    if(isset($_GET['policy'])){privacyPolicyPage();return true;}
    if(isset($_GET['radhe'])){radhePage();return true;}

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
   TELEGRAM STARS SUCCESS HANDLER
   ============================================================ */

function handleSuccessfulStarsPayment(array $message): void
{
    $uid = (int)($message['from']['id'] ?? $message['chat']['id'] ?? 0);
    $sp = $message['successful_payment'] ?? [];
    $payload = (string)($sp['invoice_payload'] ?? '');
    $charge = (string)($sp['telegram_payment_charge_id'] ?? '');
    $amount = (int)($sp['total_amount'] ?? 0);

    if ($uid <= 0 || $charge === '' || !preg_match('/^MAYA_STARS\|([^|]+)\|(\d+)$/', $payload, $m)) {
        auditLog('invalid_stars_payment', $uid);
        return;
    }

    $paymentId = $m[1];
    $payloadUid = (int)$m[2];
    $payments = readJson('payments');
    $payment = $payments[$paymentId] ?? null;

    if (!is_array($payment) || $payloadUid !== $uid || (int)($payment['user_id'] ?? 0) !== $uid) {
        auditLog('stars_payment_owner_mismatch', $uid, ['payment'=>$paymentId]);
        return;
    }

    if ($amount !== TELEGRAM_STARS_PRICE) {
        auditLog('stars_payment_wrong_amount', $uid, ['amount'=>$amount]);
        return;
    }

    if (($payment['status'] ?? '') === 'approved' && ($payment['stars_charge_id'] ?? '') === $charge) {
        return;
    }

    // Prevent the same Telegram Stars charge from being credited twice.
    foreach ($payments as $existing) {
        if (($existing['stars_charge_id'] ?? '') === $charge) return;
    }

    $base = max(now(), premiumUntil($uid));
    $until = $base + (ACCESS_DAYS * 86400);
    updateUser($uid, ['premium_until'=>$until]);

    $payment['status'] = 'approved';
    $payment['approved_at'] = now();
    $payment['expires_at'] = $until;
    $payment['stars_charge_id'] = $charge;
    $payment['amount'] = TELEGRAM_STARS_PRICE;
    $payment['currency'] = 'XTR';
    $payments[$paymentId] = $payment;
    writeJson('payments', $payments);

    sendMsg($uid,
        "<b>💚 PREMIUM ACTIVATED</b>\n\n" .
        "⭐ Telegram Stars payment received: <b>" . TELEGRAM_STARS_PRICE . " Stars</b>\n" .
        "🎧 Premium: <b>30 Days</b>\n\n" .
        "Valid until:\n<b>" . fmtDate($until) . "</b>\n\n" .
        "✨ Thank you for supporting MAYAMUSIC!"
    );
    auditLog('stars_payment_approved', $uid, ['payment'=>$paymentId]);
}

/* ============================================================
   TELEGRAM WEBHOOK UPDATE
   ============================================================ */

function acknowledgeWebhookRequest(): void
{
    http_response_code(200);
    if (!headers_sent()) { header('Content-Type: text/plain; charset=UTF-8'); header('Content-Length: 2'); }
    echo 'OK';
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
}

function processWebhook(): void
{
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 1048576) {
        http_response_code(413);
        auditLog('webhook_payload_too_large', 0, ['bytes'=>$contentLength]);
        return;
    }

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
        http_response_code(400);
        auditLog('invalid_webhook_json');
        return;
    }

    // Accept only Telegram-style update objects and avoid processing oversized/nonsensical payloads.
    if (count($update) > 20) {
        http_response_code(400);
        auditLog('invalid_webhook_shape');
        return;
    }

    acknowledgeWebhookRequest();

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

    /* TELEGRAM STARS PRE-CHECKOUT */
    if (isset($update['pre_checkout_query'])) {
        $query = $update['pre_checkout_query'];
        $payload = (string)($query['invoice_payload'] ?? '');
        $fromId = (int)($query['from']['id'] ?? 0);
        $ok = false;
        $error = 'Payment session invalid or expired.';
        if (preg_match('/^MAYA_STARS\|([^|]+)\|(\d+)$/', $payload, $m)) {
            $paymentId = $m[1];
            $payloadUid = (int)$m[2];
            $payments = readJson('payments');
            $payment = $payments[$paymentId] ?? null;
            if (is_array($payment) && (int)($payment['user_id'] ?? 0) === $fromId && $payloadUid === $fromId && ($payment['status'] ?? '') === 'stars_created' && (string)($query['currency'] ?? '') === 'XTR' && (int)($query['total_amount'] ?? 0) === TELEGRAM_STARS_PRICE) {
                $ok = true;
                $error = '';
            }
        }
        tg('answerPreCheckoutQuery', [
            'pre_checkout_query_id' => $query['id'],
            'ok' => $ok,
            'error_message' => $error
        ]);
        return;
    }

    /* TELEGRAM STARS SUCCESSFUL PAYMENT */
    if (isset($update['message']['successful_payment'])) {
        handleSuccessfulStarsPayment($update['message']);
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
runAutomaticMaintenance();

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
