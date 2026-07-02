<?php
// ── Database Configuration ──────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');          // ← Replace in production
define('DB_PASS', 'your_db_password');      // ← Replace in production
define('DB_NAME', 'your_database_name');    // ← Replace in production
define('DB_CHARSET', 'utf8mb4');

// ── Admin Credentials ───────────────────────────────────────────────────────
define('ADMIN_USER', 'admin');              // ← Change if needed
define('ADMIN_PASS_HASH', 'your_admin_hash'); 
// Example: password_hash('your_password', PASSWORD_BCRYPT)

// ── Security ────────────────────────────────────────────────────────────────
define('CSRF_TOKEN_LIFETIME', 3600);
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/');
define('ALLOWED_MIME', [
    'image/jpeg','image/png','image/webp',
    'image/gif','image/svg+xml','image/svg'
]);
define('MAX_FILE_SIZE', 2 * 1024 * 1024); // 2MB

// ── App ─────────────────────────────────────────────────────────────────────
define('APP_NAME', 'Match Prediction – Win 50€');
define('PRIZE_LABEL', '50€ Prize Service');

// ── Session start (safe) ────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false, // set true in production with HTTPS
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── PDO Connection ──────────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}

// ── CSRF Helpers ────────────────────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_time']) ||
        (time() - $_SESSION['csrf_time']) > CSRF_TOKEN_LIFETIME) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_time']  = time();
    }
    return $_SESSION['csrf_token'];
}

function csrfVerify(string $token): bool {
    if (empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

// ── Input Helpers ───────────────────────────────────────────────────────────
function sanitizeStr(string $val, int $maxLen = 200): string {
    return htmlspecialchars(mb_substr(trim($val), 0, $maxLen), ENT_QUOTES, 'UTF-8');
}

function jsonOut(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// ── Admin Auth ──────────────────────────────────────────────────────────────
function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function requireAdmin(): void {
    if (!isAdminLoggedIn()) {
        header('Location: adv741.php');
        exit;
    }
}
