<?php
/*
 * User Registration API
 * 
 * Dual-database schema for user registration:
 * - MySQL: Stores core user data (id, full_name, email, password_hash, created_at)
 * - MongoDB: Stores extended profile (firstName, lastName, phone, dob, etc.) - created but empty initially
 * 
 * On successful registration:
 * 1. Generate UUID v7 for unique user identification
 * 2. Hash password with bcrypt for security
 * 3. Insert core data to MySQL (full_name, email, password_hash)
 * 4. Create empty MongoDB profile entry for future updates
 * 5. Return success with user_id for session management
 */

// Load configuration from .env file
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

// Generate UUID v7 (Unix timestamp-based)
class UUIDv7Generator {
    public static function generate() {
        // Extract current timestamp in milliseconds (48-bit)
        $timestamp_ms = floor(microtime(true) * 1000);
        
        // Convert to 12-character hex representation
        $time_hex = str_pad(dechex($timestamp_ms), 12, '0', STR_PAD_LEFT);
        if (strlen($time_hex) > 12) {
            $time_hex = substr($time_hex, -12);
        }
        
        // Generate random bytes for variant portions
        $random_hex = bin2hex(random_bytes(10));
        
        // Construct UUID v7 format: 8-4-4-4-12
        $uuid = substr($time_hex, 0, 8) . '-' .
                substr($time_hex, 8, 4) . '-' .
                '7' . substr($random_hex, 0, 3) . '-' .
                dechex((hexdec($random_hex[6] . $random_hex[7]) & 0x3F) | 0x80) . substr($random_hex, 8, 2) . '-' .
                substr($random_hex, 10, 12);
        
        return $uuid;
    }
}

// Database connection
// For development, use: $mysqli = new \mysqli('DATABASE_HOST', 'DATABASE_USER', 'DATABASE_PASSWORD', 'DATABASE_NAME');
$mysqli = new \mysqli($env['DATABASE_HOST'], $env['DATABASE_USER'], $env['DATABASE_PASSWORD'], $env['DATABASE_NAME']);

if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['email']) || !isset($data['password']) || !isset($data['fullName'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email, password, and full name are required']);
    exit;
}

$email = trim($data['email']);
$password = trim($data['password']);
$fullName = trim($data['fullName']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
    exit;
}

if (strlen($fullName) < 2) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Full name must be at least 2 characters']);
    exit;
}

// Secure query using prepared statements
// The ? placeholder prevents SQL injection - data is passed separately from SQL
$stmt = $mysqli->prepare('SELECT id FROM users WHERE email = ?');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Bind email parameter securely ('s' = string type)
// Email value is sent separately, not embedded in the SQL
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email already registered']);
    $stmt->close();
    $mysqli->close();
    exit;
}

$stmt->close();

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Generate UUID v7 for user_id
$user_id = UUIDv7Generator::generate();

// Prepared Statement for INSERT with Multiple Parameters
// Core user data: id, full_name, email, password_hash, created_at
// All values are bound using ? placeholders to prevent SQL injection
$stmt = $mysqli->prepare('INSERT INTO guvi_database (id, full_name, email, password_hash, created_at) VALUES (?, ?, ?, ?, NOW())');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// bind_param('ssss'): Four 's' string type parameters
// Values passed separately from SQL structure - safe from injection
$stmt->bind_param('ssss', $user_id, $fullName, $email, $hashedPassword);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Registration failed']);
    $stmt->close();
    $mysqli->close();
    exit;
}

$stmt->close();

try {
    $mongoUri = $env['MONGODB_URI'];
    // For local, use: $mongoUri = 'MONGODB_URI';
    $manager = new MongoDB\Driver\Manager($mongoUri);
    
    // Create empty MongoDB profile entry for future updates
    $bulk = new MongoDB\Driver\BulkWrite();
    $bulk->insert([
        'user_id' => $user_id,
        'first_name' => '',
        'last_name' => '',
        'phone' => '',
        'dob' => '',
        'designation' => '',
        'gender' => '',
        'country' => '',
        'state' => '',
        'city' => '',
        'created_at' => new MongoDB\BSON\UTCDateTime(time() * 1000)
    ]);
    
    $manager->executeBulkWrite('guvi_database.users', $bulk);
} catch (Exception $e) {
    $deleteStmt = $mysqli->prepare('DELETE FROM users WHERE id = ?');
    if ($deleteStmt) {
        $deleteStmt->bind_param('s', $user_id);
        $deleteStmt->execute();
        $deleteStmt->close();
    }
    $mysqli->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Profile creation failed']);
    exit;
}

$mysqli->close();

http_response_code(201);
echo json_encode(['success' => true, 'message' => 'Registration successful']);
?>
