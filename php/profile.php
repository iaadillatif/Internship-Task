<?php
/*
 * User Profile Management API
 * 
 * Dual-database schema for profile management:
 * - MySQL: Stores core user data (id, full_name, email, password_hash, created_at)
 * - MongoDB: Stores extended profile data
 * 
 * Authentication Flow:
 * 1. Client sends request with sessionToken from localStorage
 * 2. Backend validates token exists in Redis (gets user_id)
 * 3. Fetch: Get user data from MySQL + check MongoDB for extended profile
 * 4. Update: Save extended profile to MongoDB, core data remains in MySQL
 * 5. Token automatically expires after 1 hour TTL
 */

if (PHP_SAPI === 'cli-server') {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
    $projectRoot = realpath(__DIR__ . '/..');

    if ($uri !== '/' && str_ends_with($uri, '.html')) {
        $cleanUrl = rtrim(substr($uri, 0, -5), '/');
        if ($cleanUrl === '') {
            $cleanUrl = '/';
        }
        header('Location: ' . $cleanUrl, true, 301);
        exit;
    }

    $requestedFile = $projectRoot . $uri;
    if ($uri !== '/' && is_file($requestedFile) && $uri !== '/php/profile.php') {
        return false;
    }

    if ($uri === '/' || $uri === '/index') {
        readfile($projectRoot . '/index.html');
        exit;
    }

    $cleanPath = trim($uri, '/');
    if ($cleanPath !== '' && !str_starts_with($cleanPath, 'php/')) {
        $htmlFile = $projectRoot . '/' . $cleanPath . '.html';
        if (is_file($htmlFile)) {
            readfile($htmlFile);
            exit;
        }
    }

    if ($uri !== '/php/profile.php' && str_starts_with($uri, '/php/')) {
        return false;
    }

    if ($uri !== '/php/profile.php') {
        http_response_code(404);
        echo '404 Not Found';
        exit;
    }
}

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

// Start session early for profile sections
session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse request body once
$inputData = file_get_contents('php://input');
$data = json_decode($inputData, true);

// Route profile sections using same body-based action pattern (fetch/update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($data) && isset($data['section'])) {
    handleProfileSectionRequest($data);
    exit;
}

// Only require token for regular profile requests (not profile sections)

if (!$data || !isset($data['token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Token is required', 'debug' => [
        'has_data' => !empty($data),
        'has_token' => isset($data['token']) ? true : false,
        'input' => substr($inputData, 0, 100)
    ]]);
    exit;
}

$token = trim($data['token']);

// Every request validates token against Redis (session management)
// Token exists in Redis = user is authenticated
// Token not in Redis = 401 Unauthorized, must login again
try {
    $redis = new \Redis();
    
    // Parse UPSTASH_REDIS_URL: redis://default:password@host:port
    $redisUrl = parse_url($env['UPSTASH_REDIS_URL']);
    $host = $redisUrl['host'] ?? 'localhost';
    $port = $redisUrl['port'] ?? 6379;
    $password = $redisUrl['pass'] ?? '';
    
    // Upstash requires SSL/TLS connection
    $redis->connect('tls://' . $host, $port);
    
    if (!empty($password)) {
        $redis->auth($password);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Session service unavailable', 'error' => $e->getMessage()]);
    exit;
}

// Getting User ID from Redis Session
// $redis->get('session:' . token) returns user_id if token exists
// Returns false if token was never issued, deleted, or expired (TTL passed)
// This is how we know if user has valid session without PHP $_SESSION
$user_id = $redis->get('session:' . $token);

if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
    exit;
}

$mongoUri = $env['MONGODB_URI'];
// For local, use: $mongoUri = 'MONGODB_URI';
$manager = new \MongoDB\Driver\Manager($mongoUri);

// MySQL connection for core user data
$mysqli = new \mysqli($env['DATABASE_HOST'], $env['DATABASE_USER'], $env['DATABASE_PASSWORD'], $env['DATABASE_NAME']);
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$requestMethod = $_SERVER['REQUEST_METHOD'];
$action = $data['action'] ?? null;

if ($requestMethod === 'GET' || ($requestMethod === 'POST' && $action === 'fetch')) {
    // Fetch Profile: Combine MySQL (core) + MongoDB (extended) data
    try {
        // 1. Get user data from MySQL (email, full_name)
        $stmt = $mysqli->prepare('SELECT id, full_name, email FROM users WHERE id = ?');
        $stmt->bind_param('s', $user_id);
        $stmt->execute();
        $sqlResult = $stmt->get_result();
        
        if ($sqlResult->num_rows === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found']);
            $stmt->close();
            $mysqli->close();
            exit;
        }
        
        $sqlUser = $sqlResult->fetch_assoc();
        $stmt->close();
        
        // 2. Try to get extended profile from MongoDB
        $filter = ['user_id' => $user_id];
        $query = new \MongoDB\Driver\Query($filter);
        $mongoResult = $manager->executeQuery('users.profiles', $query);
        $profiles = $mongoResult->toArray();
        
        // 3. Merge data based on what exists
        $profileData = [
            'email' => $sqlUser['email'],  // Always from SQL
        ];
        
        if (!empty($profiles)) {
            // Profile exists in MongoDB (user has updated profile)
            $profile = $profiles[0];
            $profileData['firstName'] = $profile->first_name ?? '';
            $profileData['lastName'] = $profile->last_name ?? '';
            $profileData['phone'] = $profile->phone ?? '';
            $profileData['dob'] = $profile->dob ?? '';
            $profileData['designation'] = $profile->designation ?? '';
            $profileData['gender'] = $profile->gender ?? '';
            $profileData['country'] = $profile->country ?? '';
            $profileData['state'] = $profile->state ?? '';
            $profileData['city'] = $profile->city ?? '';
        } else {
            // New user (no MongoDB profile yet) - use SQL data
            $nameParts = explode(' ', $sqlUser['full_name'], 2);
            $profileData['firstName'] = $nameParts[0] ?? '';
            $profileData['lastName'] = $nameParts[1] ?? '';
            $profileData['phone'] = '';
            $profileData['dob'] = '';
            $profileData['designation'] = '';
            $profileData['gender'] = '';
            $profileData['country'] = '';
            $profileData['state'] = '';
            $profileData['city'] = '';
        }
        
        $profileData['sections'] = getAllSectionsData($manager, $user_id);

        $mysqli->close();
        http_response_code(200);
        echo json_encode(['success' => true, 'data' => $profileData]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Profile service unavailable']);
        $mysqli->close();
        exit;
    }

} elseif ($requestMethod === 'POST' && $action === 'update') {
    // Update profile in MongoDB with extended profile data
    if (!isset($data['firstName']) || !isset($data['lastName']) || !isset($data['phone']) || 
        !isset($data['dob']) || !isset($data['designation']) || !isset($data['gender']) || 
        !isset($data['country']) || !isset($data['state']) || !isset($data['city'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing profile fields']);
        $mysqli->close();
        exit;
    }

    // Sanitize inputs
    $firstName = trim($data['firstName']) ?: null;
    $lastName = trim($data['lastName']) ?: null;
    $phone = trim($data['phone']) ?: null;
    $dob = trim($data['dob']) ?: null;
    $designation = trim($data['designation']) ?: null;
    $gender = trim($data['gender']) ?: null;
    $country = trim($data['country']) ?: null;
    $state = trim($data['state']) ?: null;
    $city = trim($data['city']) ?: null;

    try {
        // Save extended profile to MongoDB
        $updateData = [
            'user_id' => $user_id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'dob' => $dob,
            'designation' => $designation,
            'gender' => $gender,
            'country' => $country,
            'state' => $state,
            'city' => $city,
            'updated_at' => new \MongoDB\BSON\UTCDateTime(time() * 1000)
        ];

        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->update(
            ['user_id' => $user_id],
            ['$set' => $updateData],
            ['upsert' => true]
        );

        $manager->executeBulkWrite('users.profiles', $bulk);

        $mysqli->close();
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Profile update failed']);
        $mysqli->close();
        exit;
    }

} elseif ($requestMethod === 'POST' && $action === 'logout') {
    // Immediate Logout via Redis Deletion
    try {
        $redis->del('session:' . $token);
        $mysqli->close();
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Logout failed']);
        $mysqli->close();
        exit;
    }

} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    $mysqli->close();
}

// ======================== PROFILE SECTIONS OPERATIONS ========================
// All profile sections CRUD operations (About Me, Education, Experience, Portfolio, Projects, Skills, Certifications)

function handleProfileSectionRequest($data) {
    global $env;

    $action = $data['action'] ?? null;
    $section = $data['section'] ?? null;
    $operation = $data['operation'] ?? null;
    
    // Extract token
    $token = isset($data['token']) ? trim($data['token']) : null;
    
    if (!$token) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Token is required for profile sections']);
        exit();
    }
    
    // Validate token via Redis and get user_id
    try {
        $redis = new \Redis();
        $redisUrl = parse_url($env['UPSTASH_REDIS_URL']);
        $host = $redisUrl['host'] ?? 'localhost';
        $port = $redisUrl['port'] ?? 6379;
        $password = $redisUrl['pass'] ?? '';
        
        $redis->connect('tls://' . $host, $port);
        
        if (!empty($password)) {
            $redis->auth($password);
        }
        
        // Get user_id from token in Redis
        $auth_user_id = $redis->get('session:' . $token);
        
        if (!$auth_user_id) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
            exit();
        }
        
    } catch (Exception $e) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Authentication service unavailable: ' . $e->getMessage()]);
        exit();
    }
    
    try {
        if ($action !== 'fetch' && $action !== 'update') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit();
        }

        // Create MongoDB Manager for profile sections (same as main profile code)
        $mongoUri = $env['MONGODB_URI'];
        $manager = new \MongoDB\Driver\Manager($mongoUri);

        switch ($section) {
            case 'about':
                if ($action === 'fetch') {
                    getAboutMe($manager, $auth_user_id);
                } else {
                    saveAboutMe($manager, $auth_user_id);
                }
                break;

            case 'education':
                if ($action === 'fetch') {
                    getEducation($manager, $auth_user_id);
                } elseif ($operation === 'delete') {
                    deleteEducation($manager, $auth_user_id);
                } else {
                    addEducation($manager, $auth_user_id);
                }
                break;

            case 'experience':
                if ($action === 'fetch') {
                    getExperience($manager, $auth_user_id);
                } elseif ($operation === 'delete') {
                    deleteExperience($manager, $auth_user_id);
                } else {
                    addExperience($manager, $auth_user_id);
                }
                break;

            case 'portfolio':
                if ($action === 'fetch') {
                    getPortfolio($manager, $auth_user_id);
                } else {
                    savePortfolio($manager, $auth_user_id);
                }
                break;

            case 'projects':
                if ($action === 'fetch') {
                    getProjects($manager, $auth_user_id);
                } elseif ($operation === 'delete') {
                    deleteProject($manager, $auth_user_id);
                } else {
                    addProject($manager, $auth_user_id);
                }
                break;

            case 'skills':
                if ($action === 'fetch') {
                    getSkills($manager, $auth_user_id);
                } else {
                    saveSkills($manager, $auth_user_id);
                }
                break;

            case 'certifications':
                if ($action === 'fetch') {
                    getCertifications($manager, $auth_user_id);
                } elseif ($operation === 'delete') {
                    deleteCertification($manager, $auth_user_id);
                } else {
                    addCertification($manager, $auth_user_id);
                }
                break;

            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid section']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ========== ABOUT ME FUNCTIONS ==========
function getAboutMe($manager, $user_id) {
    $filter = ['user_id' => $user_id];
    $query = new \MongoDB\Driver\Query($filter);
    $result = $manager->executeQuery('guvi_profiles.about_me', $query);
    $docs = iterator_to_array($result);
    
    if (!empty($docs)) {
        $doc = $docs[0];
        echo json_encode([
            'success' => true,
            'data' => [
                'content' => $doc->content ?? '',
                'updated_at' => $doc->updated_at ?? date('Y-m-d H:i:s')
            ]
        ]);
    } else {
        echo json_encode(['success' => true, 'data' => ['content' => '', 'updated_at' => '']]);
    }
}

function saveAboutMe($manager, $user_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['content'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing content']);
        return;
    }
    
    $bulk = new \MongoDB\Driver\BulkWrite();
    $bulk->update(
        ['user_id' => $user_id],
        ['$set' => [
            'user_id' => $user_id,
            'content' => $data['content'],
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ]],
        ['upsert' => true]
    );
    
    $manager->executeBulkWrite('guvi_profiles.about_me', $bulk);
    echo json_encode(['success' => true, 'message' => 'About Me saved successfully']);
}

// ========== EDUCATION FUNCTIONS ==========
function getEducation($manager, $user_id) {
    $filter = ['user_id' => $user_id];
    $options = ['sort' => ['created_at' => -1]];
    $query = new \MongoDB\Driver\Query($filter, $options);
    $result = $manager->executeQuery('guvi_profiles.education', $query);
    
    $education = [];
    foreach ($result as $doc) {
        $education[] = formatEducationDoc($doc);
    }
    
    echo json_encode(['success' => true, 'data' => $education]);
}

function addEducation($manager, $user_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required = ['level', 'school_name', 'board', 'grade', 'start_year', 'end_year', 'summary'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing field: $field"]);
            return;
        }
    }
    
    $doc = [
        'user_id' => $user_id,
        'level' => $data['level'],
        'school_name' => $data['school_name'],
        'board' => $data['board'],
        'grade' => $data['grade'],
        'start_month' => $data['start_month'] ?? '',
        'start_year' => $data['start_year'],
        'end_month' => $data['end_month'] ?? '',
        'end_year' => $data['end_year'],
        'summary' => $data['summary'],
        'created_at' => new \MongoDB\BSON\UTCDateTime(),
        'updated_at' => new \MongoDB\BSON\UTCDateTime()
    ];
    
    $bulk = new \MongoDB\Driver\BulkWrite();
    $insertedId = $bulk->insert($doc);
    $manager->executeBulkWrite('guvi_profiles.education', $bulk);
    
    echo json_encode([
        'success' => true,
        'message' => 'Education added successfully',
        'data' => ['_id' => (string)$insertedId]
    ]);
}

function deleteEducation($manager, $user_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing education ID']);
        return;
    }
    
    $bulk = new \MongoDB\Driver\BulkWrite();
    $bulk->delete(
        ['_id' => new \MongoDB\BSON\ObjectId($data['id']), 'user_id' => $user_id],
        ['limit' => 1]
    );
    $manager->executeBulkWrite('guvi_profiles.education', $bulk);
    
    echo json_encode(['success' => true, 'message' => 'Education deleted successfully']);
}

// ========== EXPERIENCE FUNCTIONS ==========
function getExperience($manager, $user_id) {
    $filter = ['user_id' => $user_id];
    $options = ['sort' => ['created_at' => -1]];
    $query = new \MongoDB\Driver\Query($filter, $options);
    $result = $manager->executeQuery('guvi_profiles.experience', $query);
    
    $experience = [];
    foreach ($result as $doc) {
        $experience[] = formatExperienceDoc($doc);
    }
    
    echo json_encode(['success' => true, 'data' => $experience]);
}

function addExperience($manager, $user_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required = ['job_title', 'company', 'employment_type', 'location', 'start_year', 'summary'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing field: $field"]);
            return;
        }
    }
    
    $doc = [
        'user_id' => $user_id,
        'job_title' => $data['job_title'],
        'company' => $data['company'],
        'employment_type' => $data['employment_type'],
        'location' => $data['location'],
        'start_month' => $data['start_month'] ?? '',
        'start_year' => $data['start_year'],
        'end_month' => $data['end_month'] ?? '',
        'end_year' => $data['end_year'] ?? null,
        'currently_working' => $data['currently_working'] ?? false,
        'summary' => $data['summary'],
        'created_at' => new \MongoDB\BSON\UTCDateTime(),
        'updated_at' => new \MongoDB\BSON\UTCDateTime()
    ];
    
    $bulk = new \MongoDB\Driver\BulkWrite();
    $insertedId = $bulk->insert($doc);
    $manager->executeBulkWrite('guvi_profiles.experience', $bulk);
    
    echo json_encode([
        'success' => true,
        'message' => 'Experience added successfully',
        'data' => ['_id' => (string)$insertedId]
    ]);
}

function deleteExperience($manager, $user_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing experience ID']);
        return;
    }
    
    $bulk = new \MongoDB\Driver\BulkWrite();
    $bulk->delete(
        ['_id' => new \MongoDB\BSON\ObjectId($data['id']), 'user_id' => $user_id],
        ['limit' => 1]
    );
    $manager->executeBulkWrite('guvi_profiles.experience', $bulk);
    
    echo json_encode(['success' => true, 'message' => 'Experience deleted successfully']);
}

// ========== PORTFOLIO FUNCTIONS ==========
function getPortfolio($manager, $user_id) {
    $filter = ['user_id' => $user_id];
    $query = new \MongoDB\Driver\Query($filter);
    $result = $manager->executeQuery('guvi_profiles.portfolio', $query);
    $docs = iterator_to_array($result);
    
    if (!empty($docs)) {
        echo json_encode(['success' => true, 'data' => formatPortfolioDoc($docs[0])]);
    } else {
        echo json_encode(['success' => true, 'data' => ['website_url' => '', 'linkedin_url' => '', 'github_url' => '', 'twitter_url' => '']]);
    }
}

function savePortfolio($manager, $user_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $bulk = new \MongoDB\Driver\BulkWrite();
    $bulk->update(
        ['user_id' => $user_id],
        ['$set' => [
            'user_id' => $user_id,
            'website_url' => $data['website_url'] ?? '',
            'linkedin_url' => $data['linkedin_url'] ?? '',
            'github_url' => $data['github_url'] ?? '',
            'twitter_url' => $data['twitter_url'] ?? '',
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ]],
        ['upsert' => true]
    );
    
    $manager->executeBulkWrite('guvi_profiles.portfolio', $bulk);
    echo json_encode(['success' => true, 'message' => 'Portfolio saved successfully']);
}

// ========== PROJECTS FUNCTIONS ==========
function getProjects($manager, $user_id) {
    $filter = ['user_id' => $user_id];
    $options = ['sort' => ['created_at' => -1]];
    $query = new \MongoDB\Driver\Query($filter, $options);
    $result = $manager->executeQuery('guvi_profiles.projects', $query);
    
    $projects = [];
    foreach ($result as $doc) {
        $projects[] = formatProjectDoc($doc);
    }
    
    echo json_encode(['success' => true, 'data' => $projects]);
}

function addProject($manager, $user_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required = ['project_name', 'role', 'project_link', 'summary'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing field: $field"]);
            return;
        }
    }
    
    $doc = [
        'user_id' => $user_id,
        'project_name' => $data['project_name'],
        'role' => $data['role'],
        'project_link' => $data['project_link'],
        'summary' => $data['summary'],
        'created_at' => new \MongoDB\BSON\UTCDateTime(),
        'updated_at' => new \MongoDB\BSON\UTCDateTime()
    ];
    
    $bulk = new \MongoDB\Driver\BulkWrite();
    $insertedId = $bulk->insert($doc);
    $manager->executeBulkWrite('guvi_profiles.projects', $bulk);
    
    echo json_encode([
        'success' => true,
        'message' => 'Project added successfully',
        'data' => ['_id' => (string)$insertedId]
    ]);
}

function deleteProject($manager, $user_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing project ID']);
        return;
    }
    
    $bulk = new \MongoDB\Driver\BulkWrite();
    $bulk->delete(
        ['_id' => new \MongoDB\BSON\ObjectId($data['id']), 'user_id' => $user_id],
        ['limit' => 1]
    );
    $manager->executeBulkWrite('guvi_profiles.projects', $bulk);
    
    echo json_encode(['success' => true, 'message' => 'Project deleted successfully']);
}

// ========== SKILLS FUNCTIONS ==========
function getSkills($manager, $user_id) {
    $filter = ['user_id' => $user_id];
    $query = new \MongoDB\Driver\Query($filter);
    $result = $manager->executeQuery('guvi_profiles.skills', $query);
    $docs = iterator_to_array($result);
    
    if (!empty($docs)) {
        echo json_encode(['success' => true, 'data' => formatSkillsDoc($docs[0])]);
    } else {
        echo json_encode(['success' => true, 'data' => ['hard_skills' => [], 'soft_skills' => [], 'interests' => []]]);
    }
}

function saveSkills($manager, $user_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $bulk = new \MongoDB\Driver\BulkWrite();
    $bulk->update(
        ['user_id' => $user_id],
        ['$set' => [
            'user_id' => $user_id,
            'hard_skills' => $data['hard_skills'] ?? [],
            'soft_skills' => $data['soft_skills'] ?? [],
            'interests' => $data['interests'] ?? [],
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ]],
        ['upsert' => true]
    );
    
    $manager->executeBulkWrite('guvi_profiles.skills', $bulk);
    echo json_encode(['success' => true, 'message' => 'Skills saved successfully']);
}

// ========== CERTIFICATIONS FUNCTIONS ==========
function getCertifications($manager, $user_id) {
    $filter = ['user_id' => $user_id];
    $options = ['sort' => ['created_at' => -1]];
    $query = new \MongoDB\Driver\Query($filter, $options);
    $result = $manager->executeQuery('guvi_profiles.certifications', $query);
    
    $certifications = [];
    foreach ($result as $doc) {
        $certifications[] = formatCertificationDoc($doc);
    }
    
    echo json_encode(['success' => true, 'data' => $certifications]);
}

function addCertification($manager, $user_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required = ['name', 'issuing_org', 'credential_id', 'credential_link', 'issue_year'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Missing field: $field"]);
            return;
        }
    }
    
    $doc = [
        'user_id' => $user_id,
        'name' => $data['name'],
        'issuing_org' => $data['issuing_org'],
        'credential_id' => $data['credential_id'],
        'credential_link' => $data['credential_link'],
        'issue_year' => (int)$data['issue_year'],
        'expiry_year' => isset($data['expiry_year']) ? (int)$data['expiry_year'] : null,
        'no_expiry' => $data['no_expiry'] ?? false,
        'created_at' => new \MongoDB\BSON\UTCDateTime(),
        'updated_at' => new \MongoDB\BSON\UTCDateTime()
    ];
    
    $bulk = new \MongoDB\Driver\BulkWrite();
    $insertedId = $bulk->insert($doc);
    $manager->executeBulkWrite('guvi_profiles.certifications', $bulk);
    
    echo json_encode([
        'success' => true,
        'message' => 'Certification added successfully',
        'data' => ['_id' => (string)$insertedId]
    ]);
}

function deleteCertification($manager, $user_id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing certification ID']);
        return;
    }
    
    $bulk = new \MongoDB\Driver\BulkWrite();
    $bulk->delete(
        ['_id' => new \MongoDB\BSON\ObjectId($data['id']), 'user_id' => $user_id],
        ['limit' => 1]
    );
    $manager->executeBulkWrite('guvi_profiles.certifications', $bulk);
    
    echo json_encode(['success' => true, 'message' => 'Certification deleted successfully']);
}

function getAllSectionsData($manager, $user_id) {
    $sections = [];

    $aboutQuery = new \MongoDB\Driver\Query(['user_id' => $user_id]);
    $aboutDocs = iterator_to_array($manager->executeQuery('guvi_profiles.about_me', $aboutQuery));
    $sections['about'] = !empty($aboutDocs)
        ? ['content' => $aboutDocs[0]->content ?? '', 'updated_at' => $aboutDocs[0]->updated_at ?? '']
        : ['content' => '', 'updated_at' => ''];

    $educationQuery = new \MongoDB\Driver\Query(['user_id' => $user_id], ['sort' => ['created_at' => -1]]);
    $educationDocs = $manager->executeQuery('guvi_profiles.education', $educationQuery);
    $education = [];
    foreach ($educationDocs as $doc) {
        $education[] = formatEducationDoc($doc);
    }
    $sections['education'] = $education;

    $experienceQuery = new \MongoDB\Driver\Query(['user_id' => $user_id], ['sort' => ['created_at' => -1]]);
    $experienceDocs = $manager->executeQuery('guvi_profiles.experience', $experienceQuery);
    $experience = [];
    foreach ($experienceDocs as $doc) {
        $experience[] = formatExperienceDoc($doc);
    }
    $sections['experience'] = $experience;

    $portfolioQuery = new \MongoDB\Driver\Query(['user_id' => $user_id]);
    $portfolioDocs = iterator_to_array($manager->executeQuery('guvi_profiles.portfolio', $portfolioQuery));
    $sections['portfolio'] = !empty($portfolioDocs)
        ? formatPortfolioDoc($portfolioDocs[0])
        : ['website_url' => '', 'linkedin_url' => '', 'github_url' => '', 'twitter_url' => ''];

    $projectsQuery = new \MongoDB\Driver\Query(['user_id' => $user_id], ['sort' => ['created_at' => -1]]);
    $projectsDocs = $manager->executeQuery('guvi_profiles.projects', $projectsQuery);
    $projects = [];
    foreach ($projectsDocs as $doc) {
        $projects[] = formatProjectDoc($doc);
    }
    $sections['projects'] = $projects;

    $skillsQuery = new \MongoDB\Driver\Query(['user_id' => $user_id]);
    $skillsDocs = iterator_to_array($manager->executeQuery('guvi_profiles.skills', $skillsQuery));
    $sections['skills'] = !empty($skillsDocs)
        ? formatSkillsDoc($skillsDocs[0])
        : ['hard_skills' => [], 'soft_skills' => [], 'interests' => []];

    $certificationsQuery = new \MongoDB\Driver\Query(['user_id' => $user_id], ['sort' => ['created_at' => -1]]);
    $certificationsDocs = $manager->executeQuery('guvi_profiles.certifications', $certificationsQuery);
    $certifications = [];
    foreach ($certificationsDocs as $doc) {
        $certifications[] = formatCertificationDoc($doc);
    }
    $sections['certifications'] = $certifications;

    return $sections;
}

// ========== HELPER FORMATTING FUNCTIONS ==========
function formatEducationDoc($doc) {
    return [
        '_id' => isset($doc->_id) ? (string)$doc->_id : '',
        'level' => $doc->level ?? '',
        'school_name' => $doc->school_name ?? '',
        'board' => $doc->board ?? '',
        'grade' => $doc->grade ?? '',
        'start_date' => ($doc->start_month ?? '') . '/' . ($doc->start_year ?? ''),
        'end_date' => ($doc->end_month ?? '') . '/' . ($doc->end_year ?? ''),
        'summary' => $doc->summary ?? ''
    ];
}

function formatExperienceDoc($doc) {
    return [
        '_id' => isset($doc->_id) ? (string)$doc->_id : '',
        'job_title' => $doc->job_title ?? '',
        'company' => $doc->company ?? '',
        'employment_type' => $doc->employment_type ?? '',
        'location' => $doc->location ?? '',
        'start_date' => ($doc->start_month ?? '') . '/' . ($doc->start_year ?? ''),
        'end_date' => ($doc->currently_working ?? false) ? 'Present' : (($doc->end_month ?? '') . '/' . ($doc->end_year ?? '')),
        'currently_working' => $doc->currently_working ?? false,
        'summary' => $doc->summary ?? ''
    ];
}

function formatPortfolioDoc($doc) {
    return [
        'website_url' => $doc->website_url ?? '',
        'linkedin_url' => $doc->linkedin_url ?? '',
        'github_url' => $doc->github_url ?? '',
        'twitter_url' => $doc->twitter_url ?? ''
    ];
}

function formatProjectDoc($doc) {
    return [
        '_id' => isset($doc->_id) ? (string)$doc->_id : '',
        'project_name' => $doc->project_name ?? '',
        'role' => $doc->role ?? '',
        'project_link' => $doc->project_link ?? '',
        'summary' => $doc->summary ?? ''
    ];
}

function formatSkillsDoc($doc) {
    return [
        'hard_skills' => $doc->hard_skills ?? [],
        'soft_skills' => $doc->soft_skills ?? [],
        'interests' => $doc->interests ?? []
    ];
}

function formatCertificationDoc($doc) {
    return [
        '_id' => isset($doc->_id) ? (string)$doc->_id : '',
        'name' => $doc->name ?? '',
        'issuing_org' => $doc->issuing_org ?? '',
        'credential_id' => $doc->credential_id ?? '',
        'credential_link' => $doc->credential_link ?? '',
        'issue_year' => $doc->issue_year ?? null,
        'expiry_year' => $doc->expiry_year ?? null,
        'no_expiry' => $doc->no_expiry ?? false
    ];
}
?>
