<?php
/**
 * GET /api/bagisto/customers
 * Authenticate with the X-Bagisto-Key header using BAGISTO_API_KEY.
 * Example: curl -H "X-Bagisto-Key: YOUR_API_KEY" "https://example.com/api/bagisto/customers"
 */

require_once dirname(__DIR__, 2) . '/bootstrap_env.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$allowedOrigin = rtrim(trim((string) (getenv('BAGISTO_ORIGIN') ?: '')), '/');
$requestOrigin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($allowedOrigin !== '' && $requestOrigin !== '' && strcasecmp($requestOrigin, $allowedOrigin) === 0) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: X-Bagisto-Key, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, OPTIONS');
    echo json_encode(['error' => 'Method not allowed. Use GET.']);
    exit;
}

$expectedKey = trim((string) (getenv('BAGISTO_API_KEY') ?: ''));
$providedKey = trim((string) ($_SERVER['HTTP_X_BAGISTO_KEY'] ?? ''));
if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key.']);
    exit;
}

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$perPage = filter_var($_GET['per_page'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($page === false || $perPage === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Page and per_page must be positive integers.']);
    exit;
}
$perPage = min((int) $perPage, 100);
$page = (int) $page;
if (($page - 1) > intdiv(PHP_INT_MAX, $perPage)) {
    http_response_code(400);
    echo json_encode(['error' => 'Page is too large.']);
    exit;
}
$offset = ($page - 1) * $perPage;

try {
    $config = require dirname(__DIR__, 2) . '/config.php';
    $db = $config['db'];
    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
    $pdo->exec("SET time_zone = '-07:00';");

    $where = "email IS NOT NULL
        AND email <> ''
        AND LOWER(email) NOT LIKE '%ghostlaser.test%'
        AND LOWER(email) NOT LIKE '%example.com%'
        AND LOWER(email) <> 'test@test.com'";

    $total = (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE {$where}")->fetchColumn();
    $stmt = $pdo->prepare("
        SELECT id AS booking_customer_id, first_name, last_name, email, phone,
               company, address, city, state, zip, country, machine_brand, machine_model, machine_watts
        FROM customers
        WHERE {$where}
        ORDER BY id
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $customers = $stmt->fetchAll();

    foreach ($customers as &$customer) {
        $customer['booking_customer_id'] = (int) $customer['booking_customer_id'];
    }
    unset($customer);

    echo json_encode([
        'data' => $customers,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Bagisto customers API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load customers.']);
}
