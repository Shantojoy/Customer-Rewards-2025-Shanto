<?php
// Global configuration and helper functions for the Rewards Points System.

// Database credentials
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';
$dbName = getenv('DB_NAME') ?: 'rewards_system';

// Session management
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session timeout in seconds (30 minutes)
define('SESSION_TIMEOUT', 1800);

if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['timeout'] = true;
}
$_SESSION['last_activity'] = time();

// Database connection
$conn = @new mysqli($dbHost, $dbUser, $dbPass, $dbName);
if ($conn->connect_errno) {
    die('Database connection failed: ' . htmlspecialchars($conn->connect_error));
}

$conn->set_charset('utf8mb4');

// Load global settings
global $globalSettings;
$globalSettings = [
    'auto_points_per_visit' => 0,
];

$result = $conn->query('SELECT auto_points_per_visit FROM settings ORDER BY id DESC LIMIT 1');
if ($result && $result->num_rows) {
    $globalSettings = $result->fetch_assoc();
}

// Helper functions
function db(): mysqli
{
    global $conn;
    return $conn;
}

function global_settings(): array
{
    global $globalSettings;
    return $globalSettings;
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    if (!is_post()) {
        return;
    }
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('Invalid CSRF token.');
    }
}

function sha2_hash(string $password): string
{
    return hash('sha256', $password);
}

function current_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    static $cachedId = null;
    static $cachedAdmin = null;
    if ($cachedId === $_SESSION['admin_id'] && $cachedAdmin) {
        return $cachedAdmin;
    }
    $stmt = db()->prepare('SELECT id, username, email, role, created_at FROM admins WHERE id = ?');
    $stmt->bind_param('i', $_SESSION['admin_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $cachedAdmin = $result->fetch_assoc() ?: null;
    $cachedId = $cachedAdmin['id'] ?? null;
    $stmt->close();
    return $cachedAdmin;
}

function require_login(): void
{
    if (!current_admin()) {
        header('Location: login.php');
        exit;
    }
}

function require_role(string $role): void
{
    $admin = current_admin();
    if (!$admin || $admin['role'] !== $role) {
        http_response_code(403);
        die('Access denied.');
    }
}

function is_superadmin(): bool
{
    $admin = current_admin();
    return $admin && $admin['role'] === 'superadmin';
}

function record_activity(?int $adminId, string $action, string $details = ''): void
{
    if ($adminId === null) {
        $stmt = db()->prepare('INSERT INTO activity_log (admin_id, action, details) VALUES (NULL, ?, ?)');
        $stmt->bind_param('ss', $action, $details);
    } else {
        $stmt = db()->prepare('INSERT INTO activity_log (admin_id, action, details) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $adminId, $action, $details);
    }
    $stmt->execute();
    $stmt->close();
}

function total_points(int $customerId): int
{
    $sql = "SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('add','edit') THEN points WHEN transaction_type IN ('redeem','subtract') THEN -points ELSE 0 END), 0) AS balance FROM points_transactions WHERE customer_id = ?";
    $stmt = db()->prepare($sql);
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $balance = $stmt->get_result()->fetch_assoc()['balance'] ?? 0;
    $stmt->close();
    return (int)$balance;
}

function total_redeemed_points(int $customerId): int
{
    $stmt = db()->prepare("SELECT COALESCE(SUM(points),0) AS redeemed FROM points_transactions WHERE customer_id = ? AND transaction_type = 'redeem'");
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $redeemed = $stmt->get_result()->fetch_assoc()['redeemed'] ?? 0;
    $stmt->close();
    return (int)$redeemed;
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function paginate(int $page, int $perPage): array
{
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;
    return ['limit' => $perPage, 'offset' => $offset];
}

function format_date(?string $date, string $format = 'M d, Y h:i A'): string
{
    if (!$date) {
        return '—';
    }
    $dt = new DateTime($date);
    return $dt->format($format);
}

?>
