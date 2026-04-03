<?php
// =========================================================
//  BodyLog API  api.php  v2.1 (gemini-2.5-flash)
//  PHP 7.4+ / PDO MySQL 8.0
// =========================================================

// ── PHP エラー出力（本番: off / デバッグ時のみ '1' に変更）──
error_reporting(E_ALL);
ini_set('display_errors', '0');

// ── CORS & Headers ───────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
// CORS: 本番ドメインまたはVPS IPのみ許可
$allowedOrigins = [
    'https://bodylog.jp',
    'https://www.bodylog.jp',
    'capacitor://localhost',   // Capacitor iOS
    'http://localhost',        // Capacitor Android
    'https://localhost',       // Capacitor Android (TLS)
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: {$origin}");
}
// else は何も返さない
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ── Config ───────────────────────────────────────────────
$cfg = __DIR__ . '/config.php';
if (file_exists($cfg)) require_once $cfg;
if (!defined('JWT_SECRET') || JWT_SECRET === '' || JWT_SECRET === 'default-insecure-secret-change-me') {
    http_response_code(500);
    error_log('[BodyLog FATAL] JWT_SECRET が config.php で設定されていません。');
    echo json_encode(['error' => 'サーバー設定エラー。管理者にお問い合わせください。']);
    exit;
}
if (!defined('JWT_TTL'))               define('JWT_TTL',               86400 * 7);
if (!defined('GOOGLE_CLIENT_ID'))      define('GOOGLE_CLIENT_ID',      '');
if (!defined('GOOGLE_CLIENT_SECRET'))  define('GOOGLE_CLIENT_SECRET',  '');
if (!defined('GOOGLE_REDIRECT_URI'))   define('GOOGLE_REDIRECT_URI',   '');
if (!defined('STRIPE_SECRET_KEY'))     define('STRIPE_SECRET_KEY',     '');
if (!defined('STRIPE_PUBLISHABLE_KEY'))define('STRIPE_PUBLISHABLE_KEY','');
if (!defined('STRIPE_PRICE_ID_MONTHLY'))define('STRIPE_PRICE_ID_MONTHLY','');
if (!defined('APP_BASE_URL'))          define('APP_BASE_URL',          'https://bodylog.jp/');
if (!defined('RESEND_API_KEY'))        define('RESEND_API_KEY',         '');
if (!defined('APP_VERSION'))           define('APP_VERSION',           '2');
if (!defined('DB_HOST'))               define('DB_HOST',               'localhost');
if (!defined('DB_NAME'))               define('DB_NAME',               '');
if (!defined('DB_USER'))               define('DB_USER',               '');
if (!defined('DB_PASS'))               define('DB_PASS',               '');
if (!defined('DB_CHARSET'))            define('DB_CHARSET',            'utf8mb4');
// ── AI プロバイダー設定 ────────────────────────────────────
// config.php で使用するプロバイダーを選択:
//   define('AI_PROVIDER', 'gemini');    // Gemini 2.5 Flash（デフォルト・無料枠あり）
//   define('AI_PROVIDER', 'anthropic'); // Claude Haiku
if (!defined('AI_PROVIDER'))           define('AI_PROVIDER',           'gemini');

// Gemini API キー（Google AI Studio で取得）
// config.php で設定: define('GEMINI_API_KEY', 'AIza...');
if (!defined('GEMINI_API_KEY'))        define('GEMINI_API_KEY',        '');

// Anthropic API キー（フォールバック用）
// config.php で設定: define('ANTHROPIC_API_KEY', 'sk-ant-...');
if (!defined('ANTHROPIC_API_KEY'))     define('ANTHROPIC_API_KEY',     '');

// クロン起動用シークレット: define('AI_CRON_SECRET', 'your-secret');
if (!defined('AI_CRON_SECRET'))        define('AI_CRON_SECRET',        '');
// 毎日AI解析を実行するベース時刻（ローカル時間の時）
if (!defined('AI_SCHEDULE_HOUR'))      define('AI_SCHEDULE_HOUR',      9);

// ── Database (MySQL) ─────────────────────────────────────
$db = null;
$_dbLastError = '';
for ($__retry = 0; $__retry < 3; $__retry++) {
    try {
        $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
        $db  = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        break; // 接続成功
    } catch (Exception $e) {
        $_dbLastError = $e->getMessage();
        error_log('[BodyLog DB] attempt ' . ($__retry + 1) . ' failed: ' . $_dbLastError);
        if ($__retry < 2) sleep(1); // 1秒待ってリトライ
    }
}
if ($db === null) {
    http_response_code(500);
    echo json_encode(['error' => 'サーバーに接続できませんでした。しばらくしてから再試行してください。']); exit;
}

// ── DB初期化（フラグファイルがない初回だけ実行。以降はスキップして高速化）
$_DB_FLAG = __DIR__ . '/.db_initialized';
if (!file_exists($_DB_FLAG)) {
    try { $db->exec("CREATE TABLE IF NOT EXISTS users (
        id                    VARCHAR(36)  NOT NULL PRIMARY KEY,
        email                 VARCHAR(255) UNIQUE NOT NULL,
        password_hash         VARCHAR(255),
        google_id             VARCHAR(255) UNIQUE,
        name                  VARCHAR(255) NOT NULL DEFAULT '',
        plan                  VARCHAR(20)  NOT NULL DEFAULT 'free',
        plan_expires_at       DATETIME,
        stripe_customer_id    VARCHAR(255),
        email_verified        TINYINT      NOT NULL DEFAULT 0,
        password_reset_token  VARCHAR(64)  DEFAULT NULL,
        password_reset_expires DATETIME    DEFAULT NULL,
        created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

    try { $db->exec("CREATE TABLE IF NOT EXISTS logs (
        id              INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id         VARCHAR(36)  NOT NULL,
        log_date        DATE         NOT NULL,
        active_blocks   TEXT         NOT NULL,
        symptoms        TEXT         NOT NULL,
        bad_day_reasons TEXT         NOT NULL,
        positive_items  TEXT         NULL,
        note            TEXT         NOT NULL,
        steps           INT,
        sleep_hours     FLOAT,
        heart_rate      INT,
        updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_date (user_id, log_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

    try { $db->exec("CREATE TABLE IF NOT EXISTS user_settings (
        user_id         VARCHAR(36)  NOT NULL PRIMARY KEY,
        settings_json   MEDIUMTEXT   NULL,
        profile_json    MEDIUMTEXT   NULL,
        updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

    try { $db->exec("CREATE TABLE IF NOT EXISTS community_stats (
        symptom  VARCHAR(255) NOT NULL,
        log_date DATE         NOT NULL,
        cnt      INT          NOT NULL DEFAULT 1,
        PRIMARY KEY (symptom, log_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

    try { $db->exec("CREATE TABLE IF NOT EXISTS self_answers (
        id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id     VARCHAR(36)  NOT NULL,
        question_id VARCHAR(10)  NOT NULL,
        answer_json TEXT         NOT NULL,
        answered_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_question (user_id, question_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

    // 既存テーブルへのカラム追加
    $alters = [
        "ALTER TABLE logs  ADD COLUMN positive_items       TEXT         NULL",
        "ALTER TABLE users ADD COLUMN password_reset_token VARCHAR(64)  DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN password_reset_expires DATETIME   DEFAULT NULL",
        "ALTER TABLE logs  ADD COLUMN ai_analysis          TEXT         NULL",
        "ALTER TABLE logs  ADD COLUMN ai_generated_at      DATETIME     NULL",
        "ALTER TABLE logs  ADD COLUMN weather_temp_min     FLOAT        NULL",
        "ALTER TABLE logs  ADD COLUMN weather_temp_max     FLOAT        NULL",
        "ALTER TABLE logs  ADD COLUMN weather_temp_diff    FLOAT        NULL",
        "ALTER TABLE logs  ADD COLUMN weather_pressure_avg FLOAT        NULL",
        "ALTER TABLE logs  ADD COLUMN weather_pressure_min FLOAT        NULL",
        "ALTER TABLE logs  ADD COLUMN weather_pressure_max FLOAT        NULL",
        "ALTER TABLE logs  ADD COLUMN weather_pressure_diff FLOAT       NULL",
        "ALTER TABLE logs  ADD COLUMN weather_humidity_avg FLOAT        NULL",
        "ALTER TABLE logs  ADD COLUMN weather_humidity_min FLOAT        NULL",
        "ALTER TABLE logs  ADD COLUMN weather_humidity_max FLOAT        NULL",
        "ALTER TABLE logs  ADD COLUMN weather_pm25         FLOAT        NULL",
        "ALTER TABLE logs  ADD COLUMN weather_dust         FLOAT        NULL",
        "ALTER TABLE logs  ADD COLUMN weather_kp           FLOAT        NULL",
        "ALTER TABLE logs  ADD COLUMN weather_description  VARCHAR(255) NULL",
        "ALTER TABLE logs  ADD COLUMN ai_feedback          VARCHAR(20)  NULL",
        "ALTER TABLE logs  ADD COLUMN ai_feedback_text     TEXT         NULL",
        "ALTER TABLE logs  ADD COLUMN ai_feedback_at       DATETIME     NULL",
        "ALTER TABLE logs  ADD COLUMN ai_teaser            TEXT         NULL",
        "ALTER TABLE logs  ADD COLUMN ai_teaser_at         DATETIME     NULL",
    ];
    foreach ($alters as $sql) { try { $db->exec($sql); } catch(Exception $e){} }

    // magic_tokens テーブル（マジックリンクログイン用）
    try { $db->exec("CREATE TABLE IF NOT EXISTS magic_tokens (
        id         VARCHAR(36)  NOT NULL PRIMARY KEY,
        user_id    VARCHAR(36)  NOT NULL,
        token      VARCHAR(64)  NOT NULL UNIQUE,
        expires_at DATETIME     NOT NULL,
        used_at    DATETIME     DEFAULT NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

    try { $db->exec("CREATE TABLE IF NOT EXISTS oauth_states (
        state       VARCHAR(64) NOT NULL PRIMARY KEY,
        created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

    try { $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        ip_action   VARCHAR(80) NOT NULL PRIMARY KEY,
        attempts    INT         NOT NULL DEFAULT 1,
        first_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_at     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_first (first_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

    // 初期化完了フラグを書き込む（次回以降はここをスキップ）
    @file_put_contents($_DB_FLAG, date('Y-m-d H:i:s'));
}

// ── DB v2 初期化（郵便番号キャッシュ・気象キャッシュ）────────────
$_DB_V2_FLAG = __DIR__ . '/.db_v2_initialized';
if (!file_exists($_DB_V2_FLAG)) {
    // 郵便番号→lat/lon キャッシュ（認証不要で参照）
    try { $db->exec("CREATE TABLE IF NOT EXISTS zip_cache (
        zipcode    CHAR(7)       NOT NULL PRIMARY KEY,
        lat        DECIMAL(8,6)  NOT NULL,
        lon        DECIMAL(9,6)  NOT NULL,
        address    VARCHAR(255)  NOT NULL DEFAULT '',
        created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

    // 気象データ共有キャッシュ（郵便番号上3桁単位）
    try { $db->exec("CREATE TABLE IF NOT EXISTS weather_cache (
        zip_prefix   CHAR(3)      NOT NULL,
        log_date     DATE         NOT NULL,
        lat          DECIMAL(8,6) NOT NULL,
        lon          DECIMAL(9,6) NOT NULL,
        weather_json MEDIUMTEXT   NOT NULL,
        updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (zip_prefix, log_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

    @file_put_contents($_DB_V2_FLAG, date('Y-m-d H:i:s'));
}

// ── DB v3 初期化（気象庁警報キャッシュ）────────────────────────
$_DB_V3_FLAG = __DIR__ . '/.db_v3_initialized';
if (!file_exists($_DB_V3_FLAG)) {
    try { $db->exec("CREATE TABLE IF NOT EXISTS jma_cache (
        pref_code     CHAR(2)      NOT NULL PRIMARY KEY,
        headline      TEXT         NOT NULL,
        warnings_json MEDIUMTEXT   NOT NULL,
        updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}

    @file_put_contents($_DB_V3_FLAG, date('Y-m-d H:i:s'));
}

// ── DB v4 初期化（体感自己評価 + グリッド気象キャッシュ）─────
$_DB_V4_FLAG = __DIR__ . '/.db_v4_initialized';
if (!file_exists($_DB_V4_FLAG)) {
    try { $db->exec("ALTER TABLE logs ADD COLUMN self_score TINYINT NULL COMMENT '体感自己評価 1=動けなかった〜5=よかった'"); } catch(Exception $e){}
    // グリッド気象キャッシュ（新システム用: 旧weather_cacheと別テーブル）
    try { $db->exec("CREATE TABLE IF NOT EXISTS grid_weather_cache (
        zip_prefix  CHAR(3)    NOT NULL,
        log_date    DATE       NOT NULL,
        weather_json MEDIUMTEXT NOT NULL,
        updated_at  DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (zip_prefix, log_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}
    @file_put_contents($_DB_V4_FLAG, date('Y-m-d H:i:s'));
}

$_DB_V5_FLAG = __DIR__ . '/.db_v5_initialized';
if (!file_exists($_DB_V5_FLAG)) {
    try { $db->exec("ALTER TABLE logs ADD COLUMN body_temp DECIMAL(4,1) NULL COMMENT '体温(℃)' AFTER sleep_hours"); } catch(Exception $e){}
    @file_put_contents($_DB_V5_FLAG, date('Y-m-d H:i:s'));
}

// ── DB v6 初期化（保護者同意テーブル）────────────────────────
$_DB_V6_FLAG = __DIR__ . '/.db_v6_initialized';
if (!file_exists($_DB_V6_FLAG)) {
    try { $db->exec("CREATE TABLE IF NOT EXISTS guardian_consents (
        id             VARCHAR(36)  NOT NULL PRIMARY KEY,
        user_id        VARCHAR(36)  NOT NULL,
        guardian_email VARCHAR(255) NOT NULL,
        token          VARCHAR(64)  NOT NULL UNIQUE,
        approved_at    DATETIME     DEFAULT NULL,
        expires_at     DATETIME     NOT NULL,
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(Exception $e){}
    @file_put_contents($_DB_V6_FLAG, date('Y-m-d H:i:s'));
}

// ── JWT Helpers ──────────────────────────────────────────
function b64u($d)  { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
function b64ud($d) { return base64_decode(strtr($d, '-_', '+/') . str_repeat('=', (4 - strlen($d) % 4) % 4)); }

function makeJWT($uid) {
    $h = b64u(json_encode(['typ'=>'JWT','alg'=>'HS256']));
    $p = b64u(json_encode(['sub'=>$uid,'iat'=>time(),'exp'=>time()+JWT_TTL]));
    $s = b64u(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    return "$h.$p.$s";
}
function verifyJWT($tok) {
    if (!$tok) return null;
    $parts = explode('.', $tok);
    if (count($parts) !== 3) return null;
    [$h,$p,$s] = $parts;
    if (!hash_equals(b64u(hash_hmac('sha256',"$h.$p",JWT_SECRET,true)), $s)) return null;
    $data = json_decode(b64ud($p), true);
    if (!$data || ($data['exp']??0) < time()) return null;
    return $data['sub'];
}
function getBearerToken() {
    // ① Authorizationヘッダー（複数ソース）
    $hdr = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $hdr = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (!empty($headers['Authorization']))       $hdr = $headers['Authorization'];
        elseif (!empty($headers['authorization']))   $hdr = $headers['authorization'];
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $hdr, $m)) return $m[1];
    return null;
}
function requireAuth() {
    $uid = verifyJWT(getBearerToken());
    if (!$uid) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }
    return $uid;
}

// ── AI Helpers ───────────────────────────────────────────

/**
 * Gemini 2.0 Flash API を呼び出してテキストを返す
 * エンドポイント: generativelanguage.googleapis.com (Google AI Studio)
 * 無料枠: 1,500 req/day, 1,000,000 tokens/day
 */
function callGemini(string $prompt, int $maxTokens = 2048, string $systemPrompt = ''): ?string {
    if (!GEMINI_API_KEY) return null;
    $model   = 'gemini-2.5-flash';
    $url     = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;
    $body = [
        'contents' => [
            ['parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'maxOutputTokens' => $maxTokens,
            'temperature'     => 0.75,
        ],
        'safetySettings' => [
            ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
        ],
    ];
    if ($systemPrompt !== '') {
        $body['system_instruction'] = ['parts' => [['text' => $systemPrompt]]];
    }
    $payload = json_encode($body);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $resp     = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        error_log("[callGemini] cURL error: {$curlErr}");
        return null;
    }
    if (!$resp) {
        error_log("[callGemini] Empty response (HTTP {$httpCode})");
        return null;
    }
    $data = json_decode($resp, true);
    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? $resp;
        error_log("[callGemini] HTTP {$httpCode}: {$errMsg}");
        return null;
    }
    // candidates[0].content.parts[0].text
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text === null) {
        // finishReason を記録（SAFETY / MAX_TOKENS / RECITATION など）
        $reason = $data['candidates'][0]['finishReason'] ?? 'unknown';
        error_log("[callGemini] No text in response. finishReason={$reason}. raw=" . substr($resp, 0, 300));
    }
    return $text;
}

/**
 * Anthropic Claude Haiku を呼び出してテキストを返す
 */
function callClaude(string $prompt, string $systemPrompt = ''): ?string {
    if (!ANTHROPIC_API_KEY) return null;
    $body = [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 800,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ];
    if ($systemPrompt !== '') {
        $body['system'] = $systemPrompt;
    }
    $payload = json_encode($body);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: '         . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if (!$resp || $err) return null;
    $data = json_decode($resp, true);
    return $data['content'][0]['text'] ?? null;
}

/**
 * AI_PROVIDER 設定に応じてプロバイダーを選択して呼び出す
 * Gemini → 失敗したら Claude にフォールバック
 */
function callAI(string $prompt, string $systemPrompt = ''): ?string {
    if (AI_PROVIDER === 'gemini') {
        $result = callGemini($prompt, 2048, $systemPrompt);
        if ($result !== null) return $result;
        // Gemini が失敗した場合 Anthropic にフォールバック
        return callClaude($prompt, $systemPrompt);
    }
    return callClaude($prompt, $systemPrompt);
}

/**
 * 日次AIコメント用 system_instruction（ペルソナ・背景知識・禁止事項）
 */
function buildAiSystemPrompt(): string {
    return <<<SYSTEM
あなたは気象病・慢性疲労・自律神経失調症に詳しい、信頼できるカウンセラーです。
このユーザーとは数年来の付き合いで、お互いのことをよく知っています。
言葉遣いはですます調を基本とし、温かみがあり距離が近い。専門家としての深さも持っている。

【このアプリのユーザーが共通して抱えている背景】
あなたはこのユーザーの日常をよく知っている。以下は彼ら・彼女らが「あるある」と感じている体験だ：

＜気象病・天気との戦い＞
- 雨が降る前日から体がだるくなる。天気予報より先に体が気づく
- 台風・低気圧が近づくと頭が重くなる、耳がつまる感じがする
- 気圧の変化で偏頭痛やめまいが起きやすく、「人間バロメーター」状態になっている
- 梅雨・秋雨前線・季節の変わり目が特につらい
- 晴れていても「気圧変動が大きい日」はなぜか体調が悪い

＜体力・エネルギー管理の難しさ＞
- 「今日調子いい！」と思って動きすぎると、翌日・翌々日にガクッとくる（ペーシングの失敗）
- 良い日と悪い日の差が大きく、予定が立てにくい
- 少しでも動けると「もっとやらなきゃ」とつい無理をしてしまう
- 「普通の人なら簡単なこと」が自分には大仕事になることがある
- 回復に時間がかかる。翌日には治ると思ったのに3日かかることもある

＜心のしんどさ＞
- 「なんで動けないんだろう」と自分を責めることがある
- 見た目は元気そうに見えるので、周囲に理解されにくい
- 体調の記録をつけているのは、少しでも自分を知りたい・コントロールしたいから
- よかったことが思いつかない日は、それ自体がしんどさのサイン
- 「動けた」ことが素直に嬉しい反面、「また動きすぎたかも」という不安が常にある

＜体調管理のリアル＞
- 症状の「心当たり」を探す習慣がついている
- 睡眠の質が体調全体に直結していることを体感で知っている
- 頑張れた日も頑張れなかった日も、記録すること自体が回復への一歩

＜月経周期と体調の波（女性ユーザーの場合）＞
月経周期は体調に大きく影響する。記録に生理・月経関連のデータがある場合はこの知識を自然に活かす：

【月経期：生理1〜5日目頃 ― 最もしんどい時期のひとつ】
- プロスタグランジン分泌 → 子宮収縮 → 下腹部痛・腰痛
- プロスタグランジンが全身を循環 → 頭痛・吐き気・下痢・倦怠感
- ホルモン急低下 → 自律神経の乱れ → 気分の落ち込み・不安定
- 出血による貧血傾向 → だるさ・立ちくらみ
- 特に1〜2日目が最もしんどい（プロスタグランジンがピーク）

【卵胞期：生理終了後〜排卵前 ― 最も安定する時期】
- エストロゲン上昇 → 気力・体力が戻ってくる「ゴールデンタイム」
- 前向きな気分になりやすく、比較的動きやすい

【黄体期：排卵後〜生理前 ― PMSの時期】
- プロゲステロン優位 → 体温上昇・むくみ・便秘・眠気
- 生理3〜10日前からPMS症状が始まる
  身体：腹部膨満感・乳房の張り・頭痛・腰痛・むくみ・食欲増加（甘いもの）
  精神：イライラ・情緒不安定・不安感・憂うつ・集中力低下・不眠or過眠
- 生理直前1〜2日が精神・身体ともに最もしんどくなりやすい

【月経×気象病の相乗効果】
- ホルモン変動がもともと自律神経を揺らしやすくしている
- 低気圧の日と黄体期・月経期が重なると「ダブルパンチ」になりやすい
- 気象病患者の7〜8割は女性で、ホルモン変動と気圧変化の相乗作用が背景にあるとされる

気象と体調の関係についての専門知識（自然に使う）：
- 気圧低下（-5hPa以上/24h）は内耳を刺激し、頭痛・めまい・倦怠感を起こしやすい
- 寒暖差7℃超は自律神経の調節限界を超え、消耗が大きい
- PM2.5高濃度・黄砂は気道炎症・頭重感・疲労と関連する
- 地磁気Kp3以上は一部の人で頭痛・不眠・倦怠感と相関が報告されている

【絶対に越えてはいけない一線】
- 「〜症です」「〜病の可能性があります」など病名・診断名を断定・示唆しない
- 「〜の薬を飲んでみては」「〜療法を試して」など治療・服薬・医療行為を勧めない
- 「受診してください」「病院に行った方がいい」と断定的に受診を促さない（「気になるようなら主治医に相談するのもいいかもしれませんね」程度はOK）
- 検査値・数値の正常・異常を判定しない
- このアプリは体調の記録と振り返りを支援するツールであり、医療・診断行為ではない。その立場を守って語る
SYSTEM;
}

/**
 * プロンプトを組み立てる
 */
// ── ティザー用短縮プロンプト（無料ユーザー向け・出力80文字以内） ──────────────
function buildTeaserPrompt(array $log, string $date): string {
    $syms  = json_decode($log['symptoms'] ?? '[]', true) ?: [];
    $active = $log['active_blocks'] ? json_decode($log['active_blocks'], true) : [];
    $activeMins = array_reduce($active, function($c, $b) {
        if (!isset($b['start'],$b['end'])) return $c;
        [$sh,$sm] = array_map('intval', explode(':', $b['start']));
        [$eh,$em] = array_map('intval', explode(':', $b['end']));
        return $c + max(0, ($eh*60+$em) - ($sh*60+$sm));
    }, 0);
    $sleep = isset($log['sleep_hours']) && $log['sleep_hours'] !== null ? "{$log['sleep_hours']}時間" : '不明';

    $month = (int)date('m', strtotime($date));
    if      ($month >= 3 && $month <= 5)  $season = '春';
    elseif  ($month >= 6 && $month <= 8)  $season = '夏';
    elseif  ($month >= 9 && $month <= 11) $season = '秋';
    else                                  $season = '冬';

    $h = intdiv($activeMins, 60); $m = $activeMins % 60;
    $activeLabel = $activeMins === 0 ? '0分' : (($h > 0 ? "{$h}時間" : "") . ($m > 0 ? "{$m}分" : ""));
    $symLabel = count($syms) ? implode('・', $syms) : 'なし';

    return <<<PROMPT
以下の体調記録から、ユーザーに「続きが読みたい」と思わせる気づきを2〜3文（日本語120文字以内）で書いてください。

ルール：
- 挨拶・前置き・締め文・絵文字・記号は一切禁止。いきなり本文から始めること
- 断定的な医療表現は禁止（「〜かもしれません」「〜の可能性があります」などを使う）
- 最後の一文は「気圧・睡眠・月経周期などとの関係は詳しい解析で確認できます」のように、まだ明かしていない視点をほのめかして終わること
- 事実をベースに、本人が気づいていないパターンや仮説を1つ示すこと

【症状】{$symLabel}
【活動時間】{$activeLabel}
【睡眠】{$sleep}
【季節】{$season}
PROMPT;
}

function buildAiPrompt(array $log, array $weather, array $profile, string $date, array $settings = [], array $recentLogs = [], array $recentComments = []): string {
    $active = $log['active_blocks'] ? json_decode($log['active_blocks'], true) : [];
    $activeMins = array_reduce($active, function($carry, $b) {
        if (!isset($b['start'],$b['end'])) return $carry;
        [$sh,$sm] = array_map('intval', explode(':', $b['start']));
        [$eh,$em] = array_map('intval', explode(':', $b['end']));
        $diff = ($eh*60+$em) - ($sh*60+$sm);
        return $carry + max(0, $diff);
    }, 0);
    $timeBlocks = array_map(fn($b) => ($b['start']??'').'〜'.($b['end']??''), $active);
    $syms    = json_decode($log['symptoms']       ?? '[]', true) ?: [];
    $reasons = json_decode($log['bad_day_reasons'] ?? '[]', true) ?: [];
    $goods   = json_decode($log['positive_items']  ?? '[]', true) ?: [];
    $note    = trim($log['note'] ?? '');

    // ── 慢性的な背景（settings から抽出）
    $proneSymptoms   = $settings['proneSymptoms']   ?? [];
    $habitualReasons = $settings['habitualReasons'] ?? [];

    // ── 直近14日の傾向を集計
    $recentMinsList   = [];
    $recentSymCounts  = [];
    $recentGoodCounts = [];
    $symFreq          = [];
    foreach ($recentLogs as $rl) {
        $rActive = $rl['active_blocks'] ? json_decode($rl['active_blocks'], true) : [];
        $rMins = array_reduce($rActive, function($c, $b) {
            if (!isset($b['start'],$b['end'])) return $c;
            [$sh,$sm] = array_map('intval', explode(':', $b['start']));
            [$eh,$em] = array_map('intval', explode(':', $b['end']));
            return $c + max(0, ($eh*60+$em)-($sh*60+$sm));
        }, 0);
        $recentMinsList[]   = $rMins;
        $rSyms = json_decode($rl['symptoms'] ?? '[]', true) ?: [];
        $recentSymCounts[]  = count($rSyms);
        foreach ($rSyms as $s) $symFreq[$s] = ($symFreq[$s] ?? 0) + 1;
        $recentGoodCounts[] = count(json_decode($rl['positive_items'] ?? '[]', true) ?: []);
    }
    // 中央値活動時間
    $medianMins = 0;
    if (count($recentMinsList) > 0) {
        sort($recentMinsList);
        $mid = intdiv(count($recentMinsList), 2);
        $medianMins = count($recentMinsList) % 2 === 0
            ? intdiv($recentMinsList[$mid-1] + $recentMinsList[$mid], 2)
            : $recentMinsList[$mid];
    }
    $medH = intdiv($medianMins, 60); $medM = $medianMins % 60;
    $medLabel = $medianMins > 0
        ? ($medH > 0 ? "{$medH}時間" : "") . ($medM > 0 ? "{$medM}分" : "")
        : "ほぼ動けない日が続いている";
    // 平均症状数
    $avgSym = count($recentSymCounts) > 0 ? round(array_sum($recentSymCounts)/count($recentSymCounts),1) : 0;
    // 頻出症状 top3
    arsort($symFreq);
    $topSyms = array_slice(array_keys($symFreq), 0, 3);
    // 平均よかったこと数
    $avgGood = count($recentGoodCounts) > 0 ? round(array_sum($recentGoodCounts)/count($recentGoodCounts),1) : 0;
    // 今日 vs 中央値の差
    $diffMins = $activeMins - $medianMins;
    $diffLabel = '';
    if (count($recentMinsList) > 0) {
        $absDiff = abs($diffMins);
        $dh = intdiv($absDiff, 60); $dm = $absDiff % 60;
        $dStr = ($dh > 0 ? "{$dh}時間" : "") . ($dm > 0 ? "{$dm}分" : "0分");
        $diffLabel = $diffMins >= 0 ? "中央値より{$dStr}多い" : "中央値より{$dStr}少ない";
    }

    $tempMin      = $weather['temp_min']      ?? null;
    $tempMax      = $weather['temp_max']      ?? null;
    $tempDiff     = $weather['temp_diff']     ?? null;
    $pressureAvg  = $weather['pressure_avg']  ?? $weather['pressure'] ?? null;
    $pressureMin  = $weather['pressure_min']  ?? null;
    $pressureMax  = $weather['pressure_max']  ?? null;
    $pressureDiff = $weather['pressure_diff'] ?? null;
    $humidityAvg  = $weather['humidity_avg']  ?? $weather['humidity'] ?? null;
    $humidityMin  = $weather['humidity_min']  ?? null;
    $humidityMax  = $weather['humidity_max']  ?? null;
    $pm25         = $weather['pm25']          ?? null;
    $dust         = $weather['dust']          ?? null;
    $kp           = $weather['kp']            ?? null;
    $wdesc        = $weather['description']   ?? null;

    $name   = $profile['name']   ?? '';
    $age    = $profile['age']    ?? '';
    $gender = $profile['gender'] ?? '';
    $existingConditions = trim($profile['existingConditions'] ?? '');

    // 分 → 時間表記に変換
    $activeHoursLabel = '0分（動けなかった）';
    if ($activeMins > 0) {
        $h = intdiv($activeMins, 60);
        $m = $activeMins % 60;
        $activeHoursLabel = ($h > 0 ? "{$h}時間" : "") . ($m > 0 ? "{$m}分" : "");
        if ($timeBlocks) $activeHoursLabel .= " (" . implode(', ', $timeBlocks) . ")";
    }

    // 季節情報のみ渡す（具体的な日付はAIに渡さない＝出力させない）
    $month = (int)date('m', strtotime($date));
    if ($month >= 3 && $month <= 5)       $season = '春（寒暖差が大きい時期）';
    elseif ($month >= 6 && $month <= 8)   $season = '夏（高温・蒸し暑い時期）';
    elseif ($month >= 9 && $month <= 11)  $season = '秋（気圧変動が多い時期）';
    else                                  $season = '冬（気温低下・乾燥の時期）';

    $lines = [];
    $lines[] = "【季節】{$season}";
    $lines[] = "【動けた時間】{$activeHoursLabel}";
    $lines[] = "【よかったこと】" . (count($goods)   ? implode('、', $goods)   : "未入力（空欄＝よかったことがなかったわけではない）");
    $lines[] = "【症状】"         . (count($syms)    ? implode('、', $syms)    : "なし");
    $lines[] = "【心当たり】"     . (count($reasons) ? implode('、', $reasons) : "なし");
    if ($note) $lines[] = "【メモ】{$note}";
    if ($tempMin !== null && $tempMax !== null) {
        $line = "【気温】{$tempMin}〜{$tempMax}°C";
        if ($tempDiff !== null) $line .= "（48h変動幅{$tempDiff}°C）";
        $lines[] = $line;
    }
    if ($pressureAvg !== null) {
        $line = "【気圧】平均{$pressureAvg}hPa";
        if ($pressureMin !== null && $pressureMax !== null) $line .= "（{$pressureMin}〜{$pressureMax}hPa）";
        if ($pressureDiff !== null) $line .= "（48h変動幅{$pressureDiff}hPa）";
        $lines[] = $line;
    }
    if ($humidityAvg !== null) {
        $line = "【湿度】平均{$humidityAvg}%";
        if ($humidityMin !== null && $humidityMax !== null) $line .= "（{$humidityMin}〜{$humidityMax}%）";
        $lines[] = $line;
    }
    if ($pm25 !== null)  $lines[] = "【PM2.5】{$pm25}μg/m³" . ($pm25>=50?" ⚠️高濃度":($pm25>=25?" 注意":""));
    if ($dust !== null && $dust > 0) $lines[] = "【黄砂】{$dust}μg/m³" . ($dust>=200?" ⚠️飛来中":"");
    if ($kp  !== null)  $lines[] = "【地磁気Kp】{$kp}" . ($kp>=5?" ⚠️磁気嵐":($kp>=3?" 乱れあり":""));
    if ($wdesc)         $lines[] = "【天候】{$wdesc}";
    if ($age)           $lines[] = "【年齢】{$age}歳";
    if ($existingConditions) $lines[] = "【既往症・慢性疾患（背景情報）】{$existingConditions}";

    $data = implode("\n", $lines);

    // ── 慢性的背景セクション（settings がある場合のみ）
    $chronicSection = '';
    if (!empty($proneSymptoms) || !empty($habitualReasons)) {
        $chronicSection = "\n【このユーザーの慢性的な背景（背景情報 — レポートの主軸にしないこと）】\n";
        $chronicSection .= "以下はこのユーザーにとって「当たり前の日常」であり、今日も出現している場合でも毎回指摘するのは避けること。\n";
        $chronicSection .= "代わりに「今日はいつもと何が違うか」「なぜ今日はいつもより動けた／動けなかったのか」に焦点を当てること：\n";
        if (!empty($proneSymptoms)) $chronicSection .= "- 慢性的に出やすい症状: " . implode('、', $proneSymptoms) . "\n";
        if (!empty($habitualReasons)) $chronicSection .= "- 習慣的な心当たり: " . implode('、', $habitualReasons) . "\n";
    }

    // ── 直近14日傾向セクション
    $recentSection = '';
    if (count($recentLogs) > 0) {
        $recentSection = "\n【直近" . count($recentLogs) . "日間の傾向（比較参考）】\n";
        $recentSection .= "- 活動時間の中央値: {$medLabel}";
        if ($diffLabel) $recentSection .= "（今日は{$diffLabel}）";
        $recentSection .= "\n";
        $recentSection .= "- 平均症状数: {$avgSym}個";
        if (!empty($topSyms)) $recentSection .= "（頻出: " . implode('、', $topSyms) . "）";
        $recentSection .= "\n";
        $recentSection .= "- 平均よかったこと数: {$avgGood}個\n";
        $recentSection .= "今日のデータがこの傾向からどう外れているか（良い意味でも悪い意味でも）を意識して分析すること。\n";
    }

    // ── 過去コメント履歴セクション（視点バリエーション確保）
    $historySection = '';
    if (!empty($recentComments)) {
        $historySection = "\n【直近のコメント履歴（同じ視点・同じアドバイスは繰り返さない）】\n";
        foreach ($recentComments as $c) {
            $snippet = mb_substr($c['ai_analysis'], 0, 60);
            $historySection .= "- 「{$snippet}…」\n";
        }
        $historySection .= "↑ 上記と同じ切り口・同じアドバイスは絶対に繰り返さないこと。今回は別の角度・視点で書くこと。\n";
    }

    return <<<PROMPT
以下の記録を読んで、このユーザーへのコメントを書いてください。

{$data}
{$chronicSection}{$recentSection}{$historySection}
---
【出力ルール・厳守】
- 300〜400字
- ですます調で統一。長年の付き合いがある親しいカウンセラーとして語りかける
- 【冒頭ルール・絶対厳守】出力の最初の文は必ず「活動時間が〜」「気圧が〜」「症状が〜」「倦怠感が〜」のような観察・データ・状態描写から始めること。「〇〇さん」「今日」「先日」「〜しました」「〜ですね」から始めることは禁止
- ユーザー名（「〇〇さん」）は文中でも一切使わない
- 「あなた」という二人称も使わない。「この期間」「その日」など文脈で伝える
- 「またお話聞かせてください」「今日もお疲れ様でした」などの定型的な締め文は書かない
- 絵文字・記号（☀️😴 など）は一切使わない
- 活動時間は「〇時間〇分」の表記を使う（「〇〇分」という言い方は不自然）
- 「よかったこと未入力」は「よかったことがなかった」と解釈しない。あくまで空欄であることに留意
- 気象データが記録にある場合のみ気象と症状を結びつける。データのない推測はしない
- 「〜かもしれませんね」の推測は1回まで。重ねて使わない
- 「痛いほど伝わります」「胸が締め付けられる」などの過剰な共感表現は使わない
- 「どうかご自身を責めないでください」は、実際に自責しているサインがある場合のみ使う
- 記録に生理・月経関連のデータがある場合は、体への影響として自然に触れる
- 上から目線・お説教・過度な励ましにならない。対等に寄り添う
- 日付・前置き・計画説明は書かない。コメント本文だけ返す
- 【多角的な視点を必ず入れること】：「今日がいつもと何が違ったのか」「なぜ今日はいつもより動けた/動けなかったのか」を軸に分析し、本人がまだ気づいていない可能性のある相関やパターンを1つ以上提示すること
- 慢性的な背景として示した症状・心当たりが今日も現れている場合、それだけを理由に指摘するのは避ける。「今日はいつもより〜だった」という差分の文脈でのみ言及してよい
PROMPT;
}

/**
 * 指定ユーザー・日付のAI解析を生成してDBに保存
 */
function generateAndSaveAnalysis(PDO $db, string $uid, string $date, array $weatherData = []): ?string {
    // ログ取得
    $stmt = $db->prepare("SELECT * FROM logs WHERE user_id=? AND log_date=?");
    $stmt->execute([$uid, $date]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$log) return null;

    // プロフィール取得
    $ps = $db->prepare("SELECT profile_json, settings_json FROM user_settings WHERE user_id=?");
    $ps->execute([$uid]);
    $pr = $ps->fetch(PDO::FETCH_ASSOC);
    $profile  = $pr ? (json_decode($pr['profile_json']  ?? '{}', true) ?: []) : [];
    $settings = $pr ? (json_decode($pr['settings_json'] ?? '{}', true) ?: []) : [];

    // ユーザー名取得（users.name）
    $ns = $db->prepare("SELECT name FROM users WHERE id=?");
    $ns->execute([$uid]);
    $nr = $ns->fetch(PDO::FETCH_ASSOC);
    if (!empty($nr['name'])) $profile['name'] = $nr['name'];

    // 直近14日のログ取得（今日より前）
    $past = $db->prepare(
        "SELECT log_date, active_blocks, symptoms, positive_items, bad_day_reasons
         FROM logs
         WHERE user_id=? AND log_date < ? AND log_date >= DATE_SUB(?, INTERVAL 14 DAY)
         ORDER BY log_date DESC"
    );
    $past->execute([$uid, $date, $date]);
    $recentLogs = $past->fetchAll(PDO::FETCH_ASSOC);

    // 渡された天気データが空の場合はDBの保存済み天気を使う
    if (empty($weatherData)) {
        $weatherData = [
            'temp_min'      => $log['weather_temp_min']      ?? null,
            'temp_max'      => $log['weather_temp_max']      ?? null,
            'temp_diff'     => $log['weather_temp_diff']     ?? null,
            'pressure_avg'  => $log['weather_pressure_avg']  ?? null,
            'pressure_min'  => $log['weather_pressure_min']  ?? null,
            'pressure_max'  => $log['weather_pressure_max']  ?? null,
            'pressure_diff' => $log['weather_pressure_diff'] ?? null,
            'humidity_avg'  => $log['weather_humidity_avg']  ?? null,
            'humidity_min'  => $log['weather_humidity_min']  ?? null,
            'humidity_max'  => $log['weather_humidity_max']  ?? null,
            'pm25'          => $log['weather_pm25']          ?? null,
            'dust'          => $log['weather_dust']          ?? null,
            'kp'            => $log['weather_kp']            ?? null,
            'description'   => $log['weather_description']   ?? null,
        ];
    }
    // 直近5日のAIコメント取得（視点バリエーション確保）
    $pcStmt = $db->prepare(
        "SELECT log_date, ai_analysis FROM logs
         WHERE user_id=? AND log_date < ? AND ai_analysis IS NOT NULL AND ai_analysis != ''
         ORDER BY log_date DESC LIMIT 5"
    );
    $pcStmt->execute([$uid, $date]);
    $recentComments = $pcStmt->fetchAll(PDO::FETCH_ASSOC);

    $systemPrompt = buildAiSystemPrompt();
    $prompt       = buildAiPrompt($log, $weatherData, $profile, $date, $settings, $recentLogs, $recentComments);

    // 自動リトライ（途中切れ対策：200字未満 or 句点で終わっていない場合は再試行）
    $analysis = null;
    $lastResult = null;
    for ($retry = 0; $retry < 3; $retry++) {
        $result = callAI($prompt, $systemPrompt);
        $lastResult = $result;
        if ($result && mb_strlen($result) >= 200 && preg_match('/[。！？ね。よ。す。い。]$/u', $result)) {
            $analysis = $result;
            break;
        }
        error_log("[AI retry {$retry}] len=" . mb_strlen($result ?? '') . " date={$date}");
    }
    if (!$analysis) $analysis = $lastResult; // 全試行失敗でも最後の結果を使う
    if (!$analysis) return null;

    // 保存（ai_analysisのみ更新）
    $db->prepare("UPDATE logs SET ai_analysis=?, ai_generated_at=NOW() WHERE user_id=? AND log_date=?")
       ->execute([$analysis, $uid, $date]);
    return $analysis;
}

// ── Helpers ──────────────────────────────────────────────
function uuid4() {
    $bytes = random_bytes(16);
    $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
    $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}
function checkRateLimit(PDO $db, string $action, int $maxAttempts = 5, int $windowSec = 900): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = substr($ip, 0, 45) . ':' . $action;
    $db->prepare("DELETE FROM rate_limits WHERE first_at < DATE_SUB(NOW(), INTERVAL ? SECOND)")->execute([$windowSec]);
    $stmt = $db->prepare("SELECT attempts, first_at FROM rate_limits WHERE ip_action=?");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if ($row['attempts'] >= $maxAttempts) {
            $retryAfter = $windowSec - (time() - strtotime($row['first_at']));
            http_response_code(429);
            echo json_encode(['error' => 'リクエスト回数の上限に達しました。' . max(1, ceil($retryAfter / 60)) . '分後に再試行してください。']);
            exit;
        }
        $db->prepare("UPDATE rate_limits SET attempts=attempts+1 WHERE ip_action=?")->execute([$key]);
    } else {
        $db->prepare("INSERT INTO rate_limits (ip_action) VALUES (?)")->execute([$key]);
    }
}
function ok($d=[])  { echo json_encode(array_merge(['ok'=>true],$d)); exit; }
function err($m,$c=400) { http_response_code($c); echo json_encode(['error'=>$m]); exit; }

// ── Resend メール送信ヘルパー ────────────────────────────────
function sendEmail(string $to, string $subject, string $message): void {
    $from    = defined('RESEND_FROM') ? RESEND_FROM : 'BodyLog <onboarding@resend.dev>';
    $payload = json_encode(['from' => $from, 'to' => [$to], 'subject' => $subject, 'text' => $message]);
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . RESEND_API_KEY,
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    } else {
        @file_get_contents('https://api.resend.com/emails', false, stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Authorization: Bearer " . RESEND_API_KEY . "\r\nContent-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
            'ssl' => ['verify_peer' => true],
        ]));
    }
}

// ── Router ───────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

try { switch ($action) {

// ─────────────────────────────────────────────────────────
//  PUBLIC ENDPOINTS
// ─────────────────────────────────────────────────────────

case 'version':
case 'ping':
    ok(['version'=>APP_VERSION, 'time'=>date('Y-m-d H:i:s'),
        'stripe_key'=>STRIPE_PUBLISHABLE_KEY]);

// ── Register ─────────────────────────────────────────────
case 'register':
    checkRateLimit($db, 'register', 3, 3600);
    $email = strtolower(trim($body['email'] ?? ''));
    $pass  = trim($body['password'] ?? '');
    $name  = trim($body['name'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('メールアドレスの形式が正しくありません');
    if (mb_strlen($pass) < 8)                       err('パスワードは8文字以上にしてください');
    if (!preg_match('/[a-zA-Z]/', $pass))           err('パスワードには英字（a-z / A-Z）を1文字以上含めてください');
    if (!preg_match('/[0-9]/', $pass))              err('パスワードには数字（0-9）を1文字以上含めてください');
    $stmt = $db->prepare("SELECT id FROM users WHERE email=?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) err('このメールアドレスは既に登録されています');
    $id = uuid4();
    $db->prepare("INSERT INTO users (id,email,password_hash,name,email_verified) VALUES (?,?,?,?,1)")
       ->execute([$id, $email, password_hash($pass, PASSWORD_DEFAULT), $name]);
    ok(['token'=>makeJWT($id), 'user'=>['id'=>$id,'email'=>$email,'name'=>$name,'plan'=>'free']]);

// ── Login ────────────────────────────────────────────────
case 'login':
    checkRateLimit($db, 'login', 5, 900);
    $email = strtolower(trim($body['email'] ?? ''));
    $pass  = $body['password'] ?? '';
    $stmt  = $db->prepare("SELECT id,password_hash,name,plan,plan_expires_at FROM users WHERE email=?");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['password_hash'] || !password_verify($pass, $row['password_hash']))
        err('メールアドレスまたはパスワードが正しくありません', 401);
    // プラン期限チェック
    $plan = $row['plan'];
    if ($plan==='premium' && $row['plan_expires_at'] && strtotime($row['plan_expires_at']) < time()) {
        $plan = 'free';
        $db->prepare("UPDATE users SET plan='free' WHERE id=?")->execute([$row['id']]);
    }
    ok(['token'=>makeJWT($row['id']), 'user'=>['id'=>$row['id'],'email'=>$email,'name'=>$row['name'],'plan'=>$plan]]);

// ── Forgot Password ──────────────────────────────────────
case 'forgot_password':
    checkRateLimit($db, 'forgot_password', 3, 3600);
    $email = strtolower(trim($body['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('メールアドレスの形式が正しくありません');
    // セキュリティのため、アドレスの存在有無にかかわらず同じレスポンスを返す
    $stmt = $db->prepare("SELECT id FROM users WHERE email=?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1時間有効
        $db->prepare("UPDATE users SET password_reset_token=?, password_reset_expires=? WHERE id=?")
           ->execute([$token, $expires, $user['id']]);
        $resetUrl = APP_BASE_URL . 'bodylog.html?reset_token=' . urlencode($token);
        $subject  = '[BodyLog] パスワードリセット';
        $message  = "BodyLogのパスワードリセットをリクエストしました。\r\n\r\n"
                  . "以下のリンクから新しいパスワードを設定してください（有効期限: 1時間）:\r\n\r\n"
                  . $resetUrl . "\r\n\r\n"
                  . "このメールに心当たりがない場合は無視してください。\r\n\r\n"
                  . "-- BodyLog チーム";
        // リセットURLはメールのみで送信。レスポンスには絶対に含めない（セキュリティ）
        sendEmail($email, $subject, $message);
        ok(['sent' => true]);
    }
    // 未登録メールも同じレスポンスを返す（存在確認攻撃対策）
    ok(['sent' => true]);

// ── Magic Link: リクエスト ────────────────────────────────
case 'request_magic_link':
    checkRateLimit($db, 'request_magic_link', 5, 900);
    $email = strtolower(trim($body['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('メールアドレスを正しく入力してください');
    $stmt = $db->prepare("SELECT id, name FROM users WHERE email=?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        // 古いトークンを削除（同一ユーザーの未使用トークン）
        $db->prepare("DELETE FROM magic_tokens WHERE user_id=? AND used_at IS NULL")->execute([$user['id']]);
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 900); // 15分有効
        $tokenId = function_exists('uuid4') ? uuid4() : bin2hex(random_bytes(18));
        $db->prepare("INSERT INTO magic_tokens (id, user_id, token, expires_at) VALUES (?,?,?,?)")
           ->execute([$tokenId, $user['id'], $token, $expires]);
        $loginUrl = APP_BASE_URL . 'bodylog.html?magic_token=' . urlencode($token);
        $subject  = '[BodyLog] ログインリンク';
        $message  = ($user['name'] ? $user['name'] . 'さん、' : '') . "こんにちは。\r\n\r\n"
                  . "以下のリンクをクリックするとBodyLogにログインできます（15分間有効）：\r\n\r\n"
                  . $loginUrl . "\r\n\r\n"
                  . "このメールに心当たりがない場合は無視してください。\r\n\r\n"
                  . "-- BodyLog チーム";
        sendEmail($email, $subject, $message);
    }
    // 未登録メールも同じレスポンス（存在確認攻撃対策）
    ok(['sent' => true]);

// ── Magic Link: 検証 ──────────────────────────────────────
case 'verify_magic_link':
    $token = trim($body['token'] ?? '');
    if (!$token) err('トークンが必要です');
    $stmt = $db->prepare("SELECT mt.id, mt.user_id, mt.expires_at, mt.used_at, u.email, u.name, u.plan, u.plan_expires_at
                          FROM magic_tokens mt JOIN users u ON u.id=mt.user_id WHERE mt.token=?");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) err('このリンクは無効または既に使用されています', 400);
    if ($row['used_at'] !== null) err('このリンクは既に使用されています', 400);
    if (strtotime($row['expires_at']) < time()) err('このリンクは期限切れです（15分以内にお使いください）', 400);
    // 使用済みにマーク
    $db->prepare("UPDATE magic_tokens SET used_at=NOW() WHERE id=?")->execute([$row['id']]);
    $plan = $row['plan'];
    if ($plan === 'premium' && $row['plan_expires_at'] && strtotime($row['plan_expires_at']) < time()) {
        $plan = 'free';
        $db->prepare("UPDATE users SET plan='free' WHERE id=?")->execute([$row['user_id']]);
    }
    ok(['token' => makeJWT($row['user_id']), 'user' => ['id' => $row['user_id'], 'email' => $row['email'], 'name' => $row['name'], 'plan' => $plan]]);

// ── Reset Password ────────────────────────────────────────
case 'reset_password':
    $token = trim($body['token'] ?? '');
    $pass  = trim($body['password'] ?? '');
    if (!$token) err('トークンが必要です');
    if (mb_strlen($pass) < 8)             err('パスワードは8文字以上にしてください');
    if (!preg_match('/[a-zA-Z]/', $pass)) err('パスワードには英字（a-z / A-Z）を1文字以上含めてください');
    if (!preg_match('/[0-9]/', $pass))    err('パスワードには数字（0-9）を1文字以上含めてください');
    $stmt = $db->prepare("SELECT id, password_reset_expires FROM users WHERE password_reset_token=?");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) err('このリンクは無効または既に使用されています', 400);
    if (strtotime($user['password_reset_expires']) < time()) err('このリンクは期限切れです（1時間以内にお使いください）', 400);
    $db->prepare("UPDATE users SET password_hash=?, password_reset_token=NULL, password_reset_expires=NULL WHERE id=?")
       ->execute([password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
    ok(['message' => 'パスワードを更新しました。新しいパスワードでログインしてください。']);

// ── Google OAuth ─────────────────────────────────────────
case 'google_start':
    if (!GOOGLE_CLIENT_ID) {
        header('Content-Type: text/html; charset=utf-8', true);
        header('Location: '.APP_BASE_URL.'bodylog.html?auth_error=config', true, 302); exit;
    }
    $state = bin2hex(random_bytes(32));
    // 古いstateを削除（10分以上前）
    try { $db->exec("DELETE FROM oauth_states WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"); } catch(Exception $e){}
    $db->prepare("INSERT INTO oauth_states (state) VALUES (?)")->execute([$state]);
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'    => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type'=> 'code',
        'scope'        => 'openid email profile',
        'access_type'  => 'online',
        'state'        => $state,
    ]);
    header('Content-Type: text/html; charset=utf-8', true);
    header('Location: '.$url, true, 302); exit;

case 'google_callback':
    // state パラメータの検証（CSRF対策）
    $state = $_GET['state'] ?? '';
    if (!$state) {
        header('Content-Type: text/html; charset=utf-8', true);
        header('Location: '.APP_BASE_URL.'bodylog.html?auth_error=invalid_state', true, 302); exit;
    }
    $stateStmt = $db->prepare("SELECT state FROM oauth_states WHERE state=? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $stateStmt->execute([$state]);
    $stateRow = $stateStmt->fetch(PDO::FETCH_ASSOC);
    if (!$stateRow) {
        header('Content-Type: text/html; charset=utf-8', true);
        header('Location: '.APP_BASE_URL.'bodylog.html?auth_error=invalid_state', true, 302); exit;
    }
    // 使用済みstateを削除（リプレイ攻撃防止）
    $db->prepare("DELETE FROM oauth_states WHERE state=?")->execute([$state]);

    $code = $_GET['code'] ?? '';
    if (!$code) {
        header('Content-Type: text/html; charset=utf-8', true);
        header('Location: '.APP_BASE_URL.'bodylog.html?auth_error=1', true, 302); exit;
    }
    // ① curlでトークン取得
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $td = json_decode(curl_exec($ch), true); curl_close($ch);
    $at = $td['access_token'] ?? '';
    if (!$at) {
        header('Content-Type: text/html; charset=utf-8', true);
        header('Location: '.APP_BASE_URL.'bodylog.html?auth_error=2', true, 302); exit;
    }
    // ② curlでユーザー情報取得
    $ch2 = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$at],
    ]);
    $ui  = json_decode(curl_exec($ch2), true); curl_close($ch2);
    $gid = $ui['sub']   ?? ''; $email = strtolower($ui['email'] ?? '');
    $nm  = $ui['name']  ?? '';
    if (!$email) {
        header('Content-Type: text/html; charset=utf-8', true);
        header('Location: '.APP_BASE_URL.'bodylog.html?auth_error=3', true, 302); exit;
    }
    // ③ ユーザー検索 or 作成
    $stmt = $db->prepare("SELECT id,plan FROM users WHERE google_id=? OR email=?");
    $stmt->execute([$gid, $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $uid = $row['id'];
        $db->prepare("UPDATE users SET google_id=?,name=?,email_verified=1 WHERE id=?")->execute([$gid,$nm,$uid]);
    } else {
        $uid = uuid4();
        $db->prepare("INSERT INTO users (id,email,google_id,name,email_verified) VALUES (?,?,?,?,1)")
           ->execute([$uid,$email,$gid,$nm]);
    }
    header('Content-Type: text/html; charset=utf-8', true);
    header('Location: '.APP_BASE_URL.'bodylog.html?jwt='.urlencode(makeJWT($uid)), true, 302); exit;

// ─────────────────────────────────────────────────────────
//  AUTHENTICATED ENDPOINTS
// ─────────────────────────────────────────────────────────

case 'get_user':
    $uid  = requireAuth();
    $stmt = $db->prepare("SELECT id,email,name,plan,plan_expires_at FROM users WHERE id=?");
    $stmt->execute([$uid]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) err('User not found',404);
    if ($row['plan']==='premium' && $row['plan_expires_at'] && strtotime($row['plan_expires_at'])<time()) {
        $row['plan']='free';
        $db->prepare("UPDATE users SET plan='free' WHERE id=?")->execute([$uid]);
    }
    ok(['user'=>['id'=>$row['id'],'email'=>$row['email'],'name'=>$row['name'],'plan'=>$row['plan']]]);

case 'update_password':
    $uid = requireAuth();
    $op  = $body['old_password'] ?? ''; $np = $body['new_password'] ?? '';
    if (mb_strlen($np) < 8) err('パスワードは8文字以上にしてください');
    $row = $db->prepare("SELECT password_hash FROM users WHERE id=?");
    $row->execute([$uid]); $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r['password_hash'] && !password_verify($op, $r['password_hash'])) err('現在のパスワードが正しくありません',401);
    if (!preg_match('/[a-zA-Z]/', $np)) err('パスワードには英字（a-z / A-Z）を1文字以上含めてください');
    if (!preg_match('/[0-9]/', $np))    err('パスワードには数字（0-9）を1文字以上含めてください');
    $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($np,PASSWORD_DEFAULT),$uid]);
    ok();

// ── Log CRUD ─────────────────────────────────────────────
case 'saveLog':
    $uid  = requireAuth();
    $date = $body['log_date'] ?? date('Y-m-d');
    $w = $body['weather'] ?? [];
    $wf = fn($k) => isset($w[$k]) && $w[$k] !== null ? (float)$w[$k] : null;
    $db->prepare("
        INSERT INTO logs (user_id,log_date,active_blocks,symptoms,bad_day_reasons,positive_items,note,steps,sleep_hours,body_temp,self_score,
                          weather_temp_min,weather_temp_max,weather_temp_diff,
                          weather_pressure_avg,weather_pressure_min,weather_pressure_max,weather_pressure_diff,
                          weather_humidity_avg,weather_humidity_min,weather_humidity_max,
                          weather_pm25,weather_dust,weather_kp,weather_description,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
            active_blocks=VALUES(active_blocks), symptoms=VALUES(symptoms),
            bad_day_reasons=VALUES(bad_day_reasons), positive_items=VALUES(positive_items),
            note=VALUES(note), steps=VALUES(steps), sleep_hours=VALUES(sleep_hours),
            body_temp=VALUES(body_temp), self_score=VALUES(self_score),
            weather_temp_min=COALESCE(VALUES(weather_temp_min),weather_temp_min),
            weather_temp_max=COALESCE(VALUES(weather_temp_max),weather_temp_max),
            weather_temp_diff=COALESCE(VALUES(weather_temp_diff),weather_temp_diff),
            weather_pressure_avg=COALESCE(VALUES(weather_pressure_avg),weather_pressure_avg),
            weather_pressure_min=COALESCE(VALUES(weather_pressure_min),weather_pressure_min),
            weather_pressure_max=COALESCE(VALUES(weather_pressure_max),weather_pressure_max),
            weather_pressure_diff=COALESCE(VALUES(weather_pressure_diff),weather_pressure_diff),
            weather_humidity_avg=COALESCE(VALUES(weather_humidity_avg),weather_humidity_avg),
            weather_humidity_min=COALESCE(VALUES(weather_humidity_min),weather_humidity_min),
            weather_humidity_max=COALESCE(VALUES(weather_humidity_max),weather_humidity_max),
            weather_pm25=COALESCE(VALUES(weather_pm25),weather_pm25),
            weather_dust=COALESCE(VALUES(weather_dust),weather_dust),
            weather_kp=COALESCE(VALUES(weather_kp),weather_kp),
            weather_description=COALESCE(VALUES(weather_description),weather_description),
            updated_at=VALUES(updated_at)
    ")->execute([
        $uid, $date,
        json_encode($body['active_blocks']   ?? []),
        json_encode($body['symptoms']        ?? []),
        json_encode($body['bad_day_reasons'] ?? []),
        json_encode($body['positive_items']  ?? []),
        $body['note'] ?? '',
        isset($body['steps'])       ? (int)$body['steps']        : null,
        isset($body['sleep_hours']) ? (float)$body['sleep_hours'] : null,
        isset($body['body_temp'])   ? (float)$body['body_temp']    : null,
        isset($body['self_score'])  ? (int)$body['self_score']    : null,
        $wf('temp_min'), $wf('temp_max'), $wf('temp_diff'),
        $wf('pressure_avg'), $wf('pressure_min'), $wf('pressure_max'), $wf('pressure_diff'),
        $wf('humidity_avg'), $wf('humidity_min'), $wf('humidity_max'),
        $wf('pm25'), $wf('dust'), $wf('kp'),
        $w['description'] ?? null,
    ]);
    // コミュニティ統計更新
    foreach (($body['symptoms'] ?? []) as $s) {
        $db->prepare("INSERT INTO community_stats(symptom,log_date,cnt) VALUES(?,?,1)
            ON DUPLICATE KEY UPDATE cnt=cnt+1")
           ->execute([$s, date('Y-m-d')]);
    }
    ok();

case 'getLogs':
    $uid  = requireAuth();
    $stmt = $db->prepare("SELECT * FROM logs WHERE user_id=? ORDER BY log_date DESC");
    $stmt->execute([$uid]);
    $logs = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $logs[$r['log_date']] = [
            'activeBlocks'  => json_decode($r['active_blocks'],true),
            'symptoms'      => json_decode($r['symptoms'],true),
            'badDayReasons' => json_decode($r['bad_day_reasons'],true),
            'positiveItems' => json_decode($r['positive_items'] ?? '[]',true),
            'note'          => $r['note'],
            'steps'         => $r['steps'],
            'sleepHours'    => $r['sleep_hours'],
            'bodyTemp'      => $r['body_temp'] !== null ? (float)$r['body_temp'] : null,
            'self_score'    => $r['self_score'] !== null ? (int)$r['self_score'] : null,
            'aiAnalysis'    => $r['ai_analysis'] ?? null,
            'aiGeneratedAt' => $r['ai_generated_at'] ?? null,
            'aiTeaser'      => $r['ai_teaser'] ?? null,
        ];
    }
    ok(['logs' => $logs]);

case 'importLocalData':
    $uid  = requireAuth();
    $logs = $body['logs'] ?? [];
    $stmt = $db->prepare("
        INSERT IGNORE INTO logs (user_id,log_date,active_blocks,symptoms,bad_day_reasons,positive_items,note,steps,sleep_hours,body_temp,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
    ");
    $n = 0;
    foreach ($logs as $date => $log) {
        $stmt->execute([
            $uid, $date,
            json_encode($log['activeBlocks']   ?? []),
            json_encode($log['symptoms']       ?? []),
            json_encode($log['badDayReasons']  ?? []),
            json_encode($log['positiveItems']  ?? []),
            $log['note'] ?? '',
            isset($log['steps'])      ? (int)$log['steps']        : null,
            isset($log['sleepHours']) ? (float)$log['sleepHours'] : null,
            isset($log['bodyTemp'])   ? (float)$log['bodyTemp']   : null,
        ]); $n++;
    }
    ok(['imported' => $n]);

// ── Settings ─────────────────────────────────────────────
case 'saveSettings':
    $uid = requireAuth();
    $db->prepare("
        INSERT INTO user_settings(user_id,settings_json,profile_json,updated_at)
        VALUES(?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
            settings_json=VALUES(settings_json), profile_json=VALUES(profile_json),
            updated_at=VALUES(updated_at)
    ")->execute([$uid, json_encode($body['settings']??[]), json_encode($body['profile']??[])]);
    ok();

case 'getSettings':
    $uid  = requireAuth();
    $stmt = $db->prepare("SELECT settings_json,profile_json FROM user_settings WHERE user_id=?");
    $stmt->execute([$uid]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    ok(['settings' => $row ? json_decode($row['settings_json'],true) : null,
        'profile'  => $row ? json_decode($row['profile_json'],true)  : null]);

// ── Stripe Checkout ──────────────────────────────────────
case 'create_checkout':
    $uid = requireAuth();
    if (!STRIPE_SECRET_KEY || !STRIPE_PRICE_ID_MONTHLY) err('Stripe は config.php で設定が必要です');
    $stmt = $db->prepare("SELECT email,stripe_customer_id FROM users WHERE id=?");
    $stmt->execute([$uid]); $u = $stmt->fetch(PDO::FETCH_ASSOC);
    $cid = $u['stripe_customer_id'] ?? '';
    // Stripeカスタマー作成（なければ）
    if (!$cid) {
        $ch = curl_init('https://api.stripe.com/v1/customers');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_POST=>1,
            CURLOPT_USERPWD=>STRIPE_SECRET_KEY.':',
            CURLOPT_POSTFIELDS=>http_build_query(['email'=>$u['email'],'metadata[user_id]'=>$uid])]);
        $c = json_decode(curl_exec($ch),true); curl_close($ch);
        $cid = $c['id'] ?? '';
        if ($cid) $db->prepare("UPDATE users SET stripe_customer_id=? WHERE id=?")->execute([$cid,$uid]);
    }
    // Checkout Session作成
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_POST=>1,
        CURLOPT_USERPWD=>STRIPE_SECRET_KEY.':',
        CURLOPT_POSTFIELDS=>http_build_query([
            'customer'                 => $cid,
            'mode'                     => 'subscription',
            'line_items[0][price]'     => STRIPE_PRICE_ID_MONTHLY,
            'line_items[0][quantity]'  => 1,
            'success_url'              => APP_BASE_URL.'bodylog.html?payment_success=1',
            'cancel_url'               => APP_BASE_URL.'bodylog.html',
            'metadata[user_id]'        => $uid,
            'allow_promotion_codes'    => 'true',
        ])]);
    $sess = json_decode(curl_exec($ch),true); curl_close($ch);
    if (!isset($sess['url'])) err('Stripe Checkout の作成に失敗しました: '.($sess['error']['message']??'unknown'));
    ok(['url' => $sess['url']]);

case 'cancel_subscription':
    $uid = requireAuth();
    if (!STRIPE_SECRET_KEY) err('Stripe は config.php で設定が必要です');
    $stmt = $db->prepare("SELECT stripe_customer_id FROM users WHERE id=?");
    $stmt->execute([$uid]); $u = $stmt->fetch(PDO::FETCH_ASSOC);
    $cid = $u['stripe_customer_id'] ?? '';
    if (!$cid) err('Stripeアカウントが見つかりません');
    // サブスクリプション一覧取得
    $ch = curl_init('https://api.stripe.com/v1/subscriptions?customer='.$cid.'&status=active');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_USERPWD=>STRIPE_SECRET_KEY.':']);
    $subs = json_decode(curl_exec($ch),true); curl_close($ch);
    foreach ($subs['data'] ?? [] as $sub) {
        $ch2 = curl_init('https://api.stripe.com/v1/subscriptions/'.$sub['id']);
        curl_setopt_array($ch2,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_CUSTOMREQUEST=>'DELETE',CURLOPT_USERPWD=>STRIPE_SECRET_KEY.':']);
        curl_exec($ch2); curl_close($ch2);
    }
    $db->prepare("UPDATE users SET plan='free',plan_expires_at=NULL WHERE id=?")->execute([$uid]);
    ok();

// ── AI フィードバック保存 ──────────────────────────────────
case 'saveAiFeedback':
    $uid  = requireAuth();
    $date = trim($body['date'] ?? '');
    $fb   = trim($body['feedback'] ?? '');   // 'good' | 'bad' | 'comment'
    $text = trim($body['feedbackText'] ?? '');
    if (!$date || !in_array($fb, ['good','bad','comment'], true)) err('パラメータ不正');
    $db->prepare("UPDATE logs SET ai_feedback=?, ai_feedback_text=?, ai_feedback_at=NOW()
                  WHERE user_id=? AND log_date=?")
       ->execute([$fb, $text ?: null, $uid, $date]);
    ok();

// ── AI Debug (開発用) ─────────────────────────────────────
// ── AI Teaser（無料ユーザー向け・80文字の一言コメント） ──────────────────────
case 'generateAiTeaser':
    $uid  = requireAuth();
    if (!GEMINI_API_KEY && !ANTHROPIC_API_KEY) err('AI機能はAPIキーが設定されていません', 503);
    $date = $body['date'] ?? date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM logs WHERE user_id=? AND log_date=?");
    $stmt->execute([$uid, $date]);
    $log  = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$log) err('記録がありません');
    // 既存のティザーがあればそのまま返す
    if (!empty($log['ai_teaser'])) { ok(['teaser' => $log['ai_teaser']]); }
    $prompt = buildTeaserPrompt($log, $date);
    // maxOutputTokens を 220 に制限（日本語120文字 ≈ 220トークン）
    $teaser = callGemini($prompt, 220) ?? callClaude($prompt);
    if (!$teaser) err('AI解析の生成に失敗しました');
    $teaser = mb_substr(trim($teaser), 0, 140); // 念のため140文字でカット
    $db->prepare("UPDATE logs SET ai_teaser=?, ai_teaser_at=NOW() WHERE user_id=? AND log_date=?")
       ->execute([$teaser, $uid, $date]);
    ok(['teaser' => $teaser]);

case 'generateAiAnalysis':
    $uid  = requireAuth();
    if (!GEMINI_API_KEY && !ANTHROPIC_API_KEY) err('AI機能はAPIキーが設定されていません', 503);
    $date    = $body['date'] ?? date('Y-m-d');
    $weather = $body['weather'] ?? [];
    $result  = generateAndSaveAnalysis($db, $uid, $date, $weather);
    if ($result === null) err('AI解析の生成に失敗しました');
    ok(['analysis' => $result]);

// ── AI Analysis (scheduled cron) ──────────────────────────
// GET: ?action=scheduledAiAnalysis&secret=AI_CRON_SECRET
// ロリポップのCron設定例:
//   */5 * * * * curl -s "https://your-domain/api.php?action=scheduledAiAnalysis&secret=YOUR_SECRET" > /dev/null
//
// 仕組み: 各ユーザーのIDハッシュからオフセット（0〜59分）を計算し、
//         AI_SCHEDULE_HOUR時の「オフセット分」に前日分の解析を生成する。
//         5分ごとにcronが動き、該当ウィンドウのユーザーだけ処理する。
case 'scheduledAiAnalysis':
    $secret = $_GET['secret'] ?? '';
    if (!AI_CRON_SECRET || $secret !== AI_CRON_SECRET) {
        http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit;
    }
    if (!GEMINI_API_KEY && !ANTHROPIC_API_KEY) { ok(['skipped' => 'No API key']); }

    // 現在時刻（分単位）
    $nowH = (int)date('H');
    $nowM = (int)date('i');
    $nowTotal = $nowH * 60 + $nowM;

    // ベース時刻（分単位）
    $baseTotal = (int)AI_SCHEDULE_HOUR * 60;

    // 全ユーザー取得
    $users = $db->query("SELECT id FROM users")->fetchAll(PDO::FETCH_ASSOC);

    $processed = 0; $errors = 0;
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    foreach ($users as $u) {
        $uid = $u['id'];
        // ユーザーごとのオフセット（0〜59分）= crc32(userId) mod 60
        $offset = abs(crc32($uid)) % 60;
        $targetTotal = $baseTotal + $offset;

        // 現在の5分ウィンドウ内かチェック
        // （例: offset=17 → 9:17〜9:22に処理）
        if ($nowTotal < $targetTotal || $nowTotal >= $targetTotal + 5) continue;

        // 既に生成済みならスキップ
        $chk = $db->prepare("SELECT ai_generated_at FROM logs WHERE user_id=? AND log_date=?");
        $chk->execute([$uid, $yesterday]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue; // ログ自体がない
        if (!empty($row['ai_generated_at'])) continue; // 生成済み

        // 天気データはクライアント側で保存しないためweatherは空
        $result = generateAndSaveAnalysis($db, $uid, $yesterday, []);
        if ($result) $processed++;
        else         $errors++;
    }
    ok(['processed' => $processed, 'errors' => $errors, 'target_date' => $yesterday,
        'cron_time' => date('Y-m-d H:i:s')]);

// ── AI Insights Report ───────────────────────────────────
// POST: { period, recordDays, symRate, posRate, avgActiveMins,
//         topSymptoms:[{symptom,count}], topReasons:[{reason,count}],
//         topGoodItems:[{item,count}], profile:{name,age,gender} }
// ── 自分を知る: 回答送信 + コミュニティ統計返却 ────────────────────────────
case 'submitSelfAnswer':
    $uid = requireAuth();
    $qid        = trim($body['questionId'] ?? '');
    $answer     = $body['answer'] ?? null;  // string or array
    $answeredAt = $body['answeredAt'] ?? null; // クライアントからの回答日時（ISO8601）
    if (!$qid || $answer === null) err('questionIdとanswerは必須です', 400);

    $answerJson = json_encode($answer, JSON_UNESCAPED_UNICODE);

    // answeredAtのバリデーションとMySQL形式への変換
    $atFormatted = null;
    if ($answeredAt) {
        $dt = date_create($answeredAt);
        if ($dt) $atFormatted = date_format($dt, 'Y-m-d H:i:s');
    }
    $atSql = $atFormatted ? $atFormatted : date('Y-m-d H:i:s');

    // UPSERT: answered_atは初回回答日を保持（ON DUPLICATE時は上書きしない）
    $stmt = $db->prepare(
        "INSERT INTO self_answers(user_id, question_id, answer_json, answered_at)
         VALUES(?,?,?,?)
         ON DUPLICATE KEY UPDATE answer_json=VALUES(answer_json)"
    );
    $stmt->execute([$uid, $qid, $answerJson, $atSql]);

    // 全ユーザーの集計
    $stmt = $db->prepare("SELECT answer_json FROM self_answers WHERE question_id=?");
    $stmt->execute([$qid]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $dist = [];
    foreach ($rows as $row) {
        $a = json_decode($row, true);
        if (is_array($a)) { foreach ($a as $opt) $dist[$opt] = ($dist[$opt] ?? 0) + 1; }
        else               { $dist[$a] = ($dist[$a] ?? 0) + 1; }
    }
    arsort($dist);
    ok(['stats' => ['total' => count($rows), 'distribution' => $dist]]);

// ── 自分を知る: ユーザー自身の全回答をDBから取得 ───────────────────────────
case 'getUserSelfAnswers':
    $uid = requireAuth();
    $stmt = $db->prepare(
        "SELECT question_id, answer_json, answered_at
         FROM self_answers WHERE user_id=? ORDER BY answered_at ASC"
    );
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $result[$row['question_id']] = [
            'answer'     => json_decode($row['answer_json'], true),
            'answeredAt' => $row['answered_at'],
        ];
    }
    ok(['selfAnswers' => $result]);

case 'generateSelfProfile':
    $uid = requireAuth();
    if (!GEMINI_API_KEY && !ANTHROPIC_API_KEY) err('AI機能はAPIキーが設定されていません', 503);

    $answers = $body['answers'] ?? [];
    if (count($answers) < 5) err('回答数が不足しています（最低5問必要）', 400);

    // カテゴリ別に整理
    $cats = ['health'=>'体・健康', 'emotion'=>'感情・思考の癖', 'lifestyle'=>'ライフスタイル・習慣', 'values'=>'価値観・人生観'];
    $ansText = '';
    foreach ($cats as $key => $label) {
        $catAnswers = array_filter($answers, fn($a) => ($a['cat']??'') === $key);
        if (empty($catAnswers)) continue;
        $ansText .= "\n【{$label}】\n";
        foreach ($catAnswers as $a) {
            $q = htmlspecialchars_decode($a['question'] ?? '');
            $ans = htmlspecialchars_decode($a['answer'] ?? '');
            $ansText .= "・{$q}\n  → {$ans}\n";
        }
    }

    $prompt = <<<PROMPT
あなたは体質・気質カウンセラーです。以下のアンケート回答をもとに、この方の傾向を「あなたのプロフィール」としてまとめてください。

{$ansText}

【出力の条件】
- 「あなたは」から始まる200〜350文字の文章で書くこと
- 本人が読んで「そうそう」と感じられる内容にすること
- 体質・気質・ライフスタイルの傾向として記述する（診断・病名は一切含めない）
- 前向きで、自己理解を深める表現にする
- 箇条書きは使わず、自然な日本語の文章で書く

【絶対に越えてはいけない一線】
- 病名・診断名を断定・示唆しない
- 治療・服薬・医療行為を勧めない
- 法律・法的事項・権利義務・補償などの法務的な内容には一切触れない
PROMPT;

    $profile = callAI($prompt);
    ok(['profile' => trim($profile)]);

case 'generateInsightsReport':
    $uid = requireAuth();
    if (!GEMINI_API_KEY && !ANTHROPIC_API_KEY) err('AI機能はAPIキーが設定されていません', 503);

    $period      = (int)($body['period']       ?? 30);
    $recDays     = (int)($body['recordDays']   ?? 0);
    $symRate     = (int)($body['symRate']      ?? 0);
    $posRate     = (int)($body['posRate']      ?? 0);
    $avgActive   = (int)($body['avgActiveMins']?? 0);
    $topSyms     = $body['topSymptoms']  ?? [];
    $topReasons  = $body['topReasons']   ?? [];
    $topGoods    = $body['topGoodItems'] ?? [];
    $prof        = $body['profile']      ?? [];

    $name   = trim($prof['name']   ?? '');
    $age    = trim($prof['age']    ?? '');
    $gender = trim($prof['gender'] ?? '');
    $existingConditions = trim($prof['existingConditions'] ?? '');

    $periodLabel = $period <= 7 ? '1週間' : ($period <= 15 ? '2週間' : ($period <= 30 ? '1ヶ月' : '長期'));

    $h = intdiv($avgActive, 60); $m = $avgActive % 60;
    $activeLabel = $h > 0 ? "{$h}時間{$m}分" : "{$m}分";

    $lines = [];
    $lines[] = "【集計期間】直近{$period}日間（記録日数: {$recDays}日）";
    $lines[] = "【症状あり日率】{$symRate}%";
    $lines[] = "【よかったこと記録日率】{$posRate}%";
    $lines[] = "【平均動けた時間】{$activeLabel}/日";
    if (!empty($topSyms)) {
        $s = implode('、', array_map(fn($x) => "{$x['symptom']}（{$x['count']}回）", $topSyms));
        $lines[] = "【頻度の高い症状】{$s}";
    }
    if (!empty($topReasons)) {
        $s = implode('、', array_map(fn($x) => "{$x['reason']}（{$x['count']}回）", $topReasons));
        $lines[] = "【心当たりとして多いもの】{$s}";
    }
    if (!empty($topGoods)) {
        $s = implode('、', array_map(fn($x) => "{$x['item']}（{$x['count']}回）", $topGoods));
        $lines[] = "【よかったこととして多いもの】{$s}";
    }
    if ($name)   $lines[] = "【ユーザー名】{$name}";
    if ($age)    $lines[] = "【年齢】{$age}歳";
    if ($gender) $lines[] = "【性別】{$gender}";
    if ($existingConditions) $lines[] = "【既往症・慢性疾患（背景情報）】{$existingConditions}";

    $statsText = implode("\n", $lines);

    $systemPrompt = <<<SYSTEM
あなたは気象病・慢性疲労・自律神経失調症に詳しい、信頼できるカウンセラーです。
このユーザーとは数年来の付き合いで、お互いのことをよく知っています。
言葉遣いはですます調を基本とし、温かみがあり距離が近い。専門家としての深さも持っている。

【絶対に越えてはいけない一線】
- 病名・診断名を断定・示唆しない
- 治療・服薬・医療行為を勧めない
- 受診を断定的に促さない
- 法律・法的事項・権利義務・補償などの法務的な内容には一切触れない
SYSTEM;

    $prompt = <<<PROMPT
以下は、ユーザーの直近{$period}日間の体調記録の集計データです。
このデータをもとに、パターンと傾向を読み解いた{$periodLabel}総合レポートを書いてください。

{$statsText}

---
【出力ルール・厳守】
- 文字数：350〜450字
- ですます調で統一。温かみのある、寄り添うトーン
- 冒頭の挨拶・前置きは一切なし。データの分析・考察から即始めること
- 見出し・箇条書きは使わない。段落の流れ文章のみ
- この期間で見えてきた傾向・パターンをまず伝える
- 「〜が多い時期に症状が出やすい」などの相関を自然な言葉で表現する
- 頑張れた点（または記録を続けていること自体）を1つ具体的に認める
- 次の期間に活かせる1〜2個の視点・気づきを提示する（お説教にならないように）
- 絵文字・記号（☀️😴 など）は一切使わない
- ユーザー名が記録にある場合、文中で自然に呼んでよい
- 「どうかご自身を責めないでください」「お大事に」などの定型表現は使わない
PROMPT;

    $report = callAI($prompt, $systemPrompt);
    if (!$report) err('レポートの生成に失敗しました');
    ok(['report' => trim($report)]);

// ── 郵便番号キャッシュ検索（認証不要） ────────────────────────────
case 'lookupZipCache':
    $zip = preg_replace('/[^0-9]/', '', $_GET['zip'] ?? '');
    if (strlen($zip) !== 7) err('郵便番号は7桁で指定してください');

    // ① DBキャッシュを確認
    $stmt = $db->prepare("SELECT lat, lon, address FROM zip_cache WHERE zipcode=?");
    $stmt->execute([$zip]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cached) {
        ok(['lat' => (float)$cached['lat'], 'lon' => (float)$cached['lon'],
            'address' => $cached['address'], 'source' => 'cache']);
    }

    // ② キャッシュになければ zipcloud 経由で取得（サーバー側プロキシ）
    $apiUrl = "https://zipcloud.ibsnet.co.jp/api/search?zipcode={$zip}";
    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'BodyLog/2.0']);
        $raw = curl_exec($ch); curl_close($ch);
    }
    if (!$raw) $raw = @file_get_contents($apiUrl);
    if (!$raw) err('住所が見つかりませんでした', 404);

    $data = json_decode($raw, true);
    if (empty($data['results'][0])) err('住所が見つかりませんでした', 404);
    $r    = $data['results'][0];
    $lat  = (float)$r['latitude'];
    $lon  = (float)$r['longitude'];
    $addr = $r['address1'] . $r['address2'] . $r['address3'];

    // ③ DBに保存（次回以降はキャッシュヒット）
    try {
        $db->prepare("INSERT INTO zip_cache (zipcode,lat,lon,address) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE lat=VALUES(lat),lon=VALUES(lon),address=VALUES(address)")
           ->execute([$zip, $lat, $lon, $addr]);
    } catch(Exception $e){}

    ok(['lat' => $lat, 'lon' => $lon, 'address' => $addr, 'source' => 'zipcloud']);

// ── 気象キャッシュ保存 ─────────────────────────────────────────
case 'saveWeatherCache':
    $uid = requireAuth();
    $b   = json_decode(file_get_contents('php://input'), true) ?? [];
    $zp   = preg_replace('/[^0-9]/', '', $b['zip_prefix'] ?? '');
    $date = $b['date'] ?? '';
    $lat  = (float)($b['lat']  ?? 0);
    $lon  = (float)($b['lon']  ?? 0);
    if (strlen($zp) !== 3 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !$lat || !$lon)
        err('パラメータエラー');

    // 受け入れるフィールドのホワイトリスト（camelCase、weatherHistと同一キー）
    $allowed = [
        'temp','tempMax','tempMin','tempDiff48h',
        'pressureAvg','pressureMin','pressureMax','pressureDiff48h',
        'humidityAvg','humidityMin','humidityMax',
        'weatherCode',
        'windMax','windGusts','windDirection','windAvg','windCurrent','windDirectionCurrent',
        'precipSum','rainSum','snowfallSum','precipProbMax','precipCurrent',
        'uvIndexMax','sunshineDuration',
        'visibilityAvg','cloudCoverAvg','cloudCoverCurrent',
        'pm25','pm10','dust','ozone','no2','kp',
        'hour',
    ];
    $wj = json_encode(array_intersect_key($b, array_flip($allowed)));
    try {
        $db->prepare("INSERT INTO weather_cache (zip_prefix,log_date,lat,lon,weather_json)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE weather_json=VALUES(weather_json),
                lat=VALUES(lat),lon=VALUES(lon),updated_at=CURRENT_TIMESTAMP")
           ->execute([$zp, $date, $lat, $lon, $wj]);
    } catch(Exception $e){}
    ok();

// ── 気象キャッシュ取得（POST: {zip_prefix, dates:[...]}） ──────────
case 'getWeatherCache':
    $uid  = requireAuth();
    $b    = json_decode(file_get_contents('php://input'), true) ?? [];
    $zp   = preg_replace('/[^0-9]/', '', $b['zip_prefix'] ?? '');
    $dates = array_filter((array)($b['dates'] ?? []),
        fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d));
    if (strlen($zp) !== 3 || empty($dates)) err('パラメータエラー');

    $ph   = implode(',', array_fill(0, count($dates), '?'));
    $stmt = $db->prepare("SELECT log_date, weather_json
        FROM weather_cache WHERE zip_prefix=? AND log_date IN ({$ph})");
    $stmt->execute(array_merge([$zp], array_values($dates)));

    $data = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $data[$row['log_date']] = json_decode($row['weather_json'], true) ?? [];
    }
    ok(['data' => $data]);

// ── 気象グリッドキャッシュ取得（認証不要・cron配信用）──────────
case 'getWeatherGrid':
    $zp   = preg_replace('/[^0-9]/', '', $_GET['zip_prefix'] ?? '');
    $date = $_GET['date'] ?? date('Y-m-d');
    if (strlen($zp) !== 3 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
        err('パラメータエラー');
    $stmt = $db->prepare("SELECT weather_json, updated_at
        FROM grid_weather_cache WHERE zip_prefix=? AND log_date=?");
    $stmt->execute([$zp, $date]);
    $row = $stmt->fetch();
    if (!$row) ok(['data' => null, 'cached' => false]);
    ok([
        'data'       => json_decode($row['weather_json'], true) ?? [],
        'updated_at' => $row['updated_at'],
        'cached'     => true,
    ]);

// ── 気象庁警報キャッシュ取得（認証不要・公共情報）─────────────
case 'getJmaWarnings':
    $pref = preg_replace('/[^0-9]/', '', $_GET['pref'] ?? '');
    if (strlen($pref) !== 2) err('パラメータエラー');
    $stmt = $db->prepare("SELECT headline, warnings_json, updated_at
        FROM jma_cache WHERE pref_code=?");
    $stmt->execute([$pref]);
    $row = $stmt->fetch();
    if (!$row) ok(['headline' => '', 'warnings' => [], 'cached' => false]);
    ok([
        'headline'   => $row['headline'],
        'warnings'   => json_decode($row['warnings_json'], true) ?? [],
        'updated_at' => $row['updated_at'],
        'cached'     => true,
    ]);

// ── Community ────────────────────────────────────────────
case 'community':
    requireAuth();
    $stmt = $db->prepare("SELECT symptom,SUM(cnt) as total FROM community_stats
        WHERE log_date>=? GROUP BY symptom ORDER BY total DESC LIMIT 10");
    $stmt->execute([date('Y-m-d',strtotime('-7 days'))]);
    $stats = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $stats[] = ['symptom'=>$r['symptom'],'cnt'=>(int)$r['total']];
    echo json_encode($stats); exit;

// ── アカウント削除 ──────────────────────────────────────────
case 'delete_account':
    $uid = requireAuth();
    // 関連データをすべて削除
    $db->prepare("DELETE FROM logs WHERE user_id=?")->execute([$uid]);
    $db->prepare("DELETE FROM user_settings WHERE user_id=?")->execute([$uid]);
    $db->prepare("DELETE FROM self_answers WHERE user_id=?")->execute([$uid]);
    $db->prepare("DELETE FROM magic_tokens WHERE user_id=?")->execute([$uid]);
    $db->prepare("DELETE FROM guardian_consents WHERE user_id=?")->execute([$uid]);
    // ユーザー自体を削除
    $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
    ok(['deleted' => true]);

// ── 保護者同意リクエスト ─────────────────────────────────────
case 'request_guardian_consent':
    $uid = requireAuth();
    $guardianEmail = strtolower(trim($body['guardian_email'] ?? ''));
    if (!filter_var($guardianEmail, FILTER_VALIDATE_EMAIL))
        err('保護者のメールアドレスの形式が正しくありません');

    // 既存の未承認リクエストを削除
    $db->prepare("DELETE FROM guardian_consents WHERE user_id=? AND approved_at IS NULL")->execute([$uid]);

    $token   = bin2hex(random_bytes(32));
    $id      = uuid4();
    $expires = date('Y-m-d H:i:s', time() + 72 * 3600); // 72時間有効

    $db->prepare("INSERT INTO guardian_consents (id, user_id, guardian_email, token, expires_at) VALUES (?,?,?,?,?)")
       ->execute([$id, $uid, $guardianEmail, $token, $expires]);

    // 保護者にメール送信
    $approveUrl = APP_BASE_URL . 'api.php?action=verify_guardian_consent&token=' . urlencode($token);
    $subject    = '[BodyLog] お子様のアカウント利用に関する保護者同意のお願い';
    $message    = "このメールは BodyLog（体調管理アプリ）からお送りしています。\r\n\r\n"
                . "お子様（18歳未満のユーザー）が BodyLog のご利用を希望しており、\r\n"
                . "保護者の方の同意が必要です。\r\n\r\n"
                . "以下のリンクをクリックすると、お子様の利用を承認できます（72時間有効）：\r\n\r\n"
                . $approveUrl . "\r\n\r\n"
                . "このメールに心当たりがない場合は無視してください。\r\n\r\n"
                . "-- BodyLog チーム（合同会社カプラス）\r\n"
                . "support@bodylog.jp";
    sendEmail($guardianEmail, $subject, $message);

    ok(['sent' => true, 'expires_at' => $expires]);

// ── 保護者同意の検証（GETアクセス・リダイレクト） ────────────
case 'verify_guardian_consent':
    $token = trim($_GET['token'] ?? '');
    if (!$token) err('トークンが必要です');

    $stmt = $db->prepare("SELECT id, user_id, expires_at, approved_at FROM guardian_consents WHERE token=?");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // HTML レスポンスを返す
    header('Content-Type: text/html; charset=utf-8');

    if (!$row) {
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BodyLog - 保護者同意</title></head>'
           . '<body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f0f4ff">'
           . '<div style="text-align:center;padding:32px;max-width:400px"><div style="font-size:48px;margin-bottom:16px">❌</div>'
           . '<div style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:12px">無効なリンクです</div>'
           . '<div style="font-size:14px;color:#64748b">このリンクは無効または既に使用されています。</div></div></body></html>';
        exit;
    }

    if ($row['approved_at'] !== null) {
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BodyLog - 保護者同意</title></head>'
           . '<body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f0f4ff">'
           . '<div style="text-align:center;padding:32px;max-width:400px"><div style="font-size:48px;margin-bottom:16px">✅</div>'
           . '<div style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:12px">既に承認済みです</div>'
           . '<div style="font-size:14px;color:#64748b">この同意リクエストは既に承認されています。</div></div></body></html>';
        exit;
    }

    if (strtotime($row['expires_at']) < time()) {
        echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BodyLog - 保護者同意</title></head>'
           . '<body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f0f4ff">'
           . '<div style="text-align:center;padding:32px;max-width:400px"><div style="font-size:48px;margin-bottom:16px">⏰</div>'
           . '<div style="font-size:18px;font-weight:700;color:#1e293b;margin-bottom:12px">リンクの有効期限が切れています</div>'
           . '<div style="font-size:14px;color:#64748b">お子様に再度リクエストを送信してもらってください（72時間有効）。</div></div></body></html>';
        exit;
    }

    // 承認を記録
    $db->prepare("UPDATE guardian_consents SET approved_at=NOW() WHERE id=?")->execute([$row['id']]);

    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BodyLog - 保護者同意完了</title></head>'
       . '<body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:linear-gradient(160deg,#f0f4ff 0%,#ede9fe 100%)">'
       . '<div style="text-align:center;padding:32px;max-width:400px;background:white;border-radius:24px;box-shadow:0 4px 24px rgba(0,0,0,0.08)">'
       . '<div style="font-size:48px;margin-bottom:16px">🎉</div>'
       . '<div style="font-size:20px;font-weight:800;color:#1e293b;margin-bottom:12px">同意が完了しました</div>'
       . '<div style="font-size:14px;color:#64748b;line-height:1.7;margin-bottom:20px">お子様の BodyLog 利用を承認しました。<br>お子様はアプリを引き続きご利用いただけます。</div>'
       . '<div style="font-size:12px;color:#94a3b8">このページを閉じても問題ありません。</div></div></body></html>';
    exit;

// ── 保護者同意ステータス確認 ─────────────────────────────────
case 'check_guardian_consent':
    $uid = requireAuth();
    $stmt = $db->prepare("SELECT approved_at, expires_at FROM guardian_consents WHERE user_id=? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) ok(['status' => 'none']);
    if ($row['approved_at'] !== null) ok(['status' => 'approved', 'approved_at' => $row['approved_at']]);
    if (strtotime($row['expires_at']) < time()) ok(['status' => 'expired']);
    ok(['status' => 'pending', 'expires_at' => $row['expires_at']]);

default:
    err('Unknown action: '.$action, 404);

}} catch (Exception $e) {
    error_log('[BodyLog API] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'サーバーエラーが発生しました。しばらくしてから再試行してください。']);
}
