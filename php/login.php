<?php
namespace Login;
/*
 * User Login API
 * 
 * Handles authentication and session token generation:
 * - Email verification using prepared statements
 * - bcrypt password validation
 * - Redis-based session token storage (1-hour TTL)
 * - Token-based authentication for subsequent API calls
 */

// Load .env file with custom parser (handles special characters like MongoDB URI)
function loadEnv($path) {
    $env = [];
    if (!file_exists($path)) return false;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') === false || $line[0] === '#') continue;
        list($key, $value) = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
    }
    return $env;
}

$env = loadEnv(__DIR__ . '/../.env');

if ($env === false || empty($env['DATABASE_HOST'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration file not found']);
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$mysqli = new \mysqli($env['DATABASE_HOST'], $env['DATABASE_USER'], $env['DATABASE_PASSWORD'], $env['DATABASE_NAME']);
// For local testing, use: $mysqli = new \mysqli('DATABASE_HOST', 'DATABASE_USER', 'DATABASE_PASSWORD', 'DATABASE_NAME');

if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['email']) || !isset($data['password'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email and password are required']);
    exit;
}

$email = trim($data['email']);
$password = trim($data['password']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

if (empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password is required']);
    exit;
}

// Prepared Statement for User Lookup
// Email is passed as a placeholder (?), never concatenated into SQL
// This is critical for security: even if email contains SQL characters, they're treated as data
$stmt = $mysqli->prepare('SELECT id, password_hash FROM users WHERE email = ?');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// bind_param('s', $email): Bind email as string parameter
// Database driver handles escaping safely before execution
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
    $stmt->close();
    $mysqli->close();
    exit;
}

$row = $result->fetch_assoc();
$stmt->close();

if (!password_verify($password, $row['password_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
    $mysqli->close();
    exit;
}

$user_id = $row['id'];

// Redis for Session Storage (NOT PHP $_SESSION)
// After successful password verification, create a session token and store in Redis
try {
    $redis = new \Redis();
    
    // Parse UPSTASH_REDIS_URL: redis://default:password@host:port
    $redisUrl = parse_url($env['UPSTASH_REDIS_URL']);
    $host = $redisUrl['host'] ?? 'localhost';
    $port = $redisUrl['port'] ?? 6379;
    $password = $redisUrl['pass'] ?? '';
    
    // Upstash requires SSL/TLS connection
    $connected = $redis->connect('tls://' . $host, $port, 2);
    
    if (!$connected) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Session service unavailable', 'error' => 'Redis connection failed']);
        exit;
    }
    
    if (!empty($password)) {
        $redis->auth($password);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Session service unavailable', 'error' => $e->getMessage()]);
    $mysqli->close();
    exit;
}

// Generate secure random 64-character hex token (32 bytes of random data)
// Frontend stores this in localStorage and sends it with every future request
$sessionToken = bin2hex(random_bytes(32));

// Redis Session Storage (No PHP $_SESSION)
// setex(key, ttl, value): Set key with expiration time in seconds
// Key: 'session:' + token -> Value: user_id (from MySQL)
// TTL: 3600 seconds (1 hour) - Redis auto-deletes after expiration
// Browser localStorage + Redis backend = token-based authentication (no PHP sessions)
$redis->setex('session:' . $sessionToken, 3600, $user_id);

$mysqli->close();

http_response_code(200);
echo json_encode(['success' => true, 'token' => $sessionToken]);
?>
