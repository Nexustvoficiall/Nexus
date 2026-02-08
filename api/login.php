<?php
ob_start(); // Ensure output buffering
header('Content-Type: application/json');

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', 'login_debug.log');

function log_debug($msg, $data = null) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($data !== null) {
        $line .= ' | ' . print_r($data, true);
    }
    error_log($line);
}

/* ───── constants ───────────────────────────────────── */

define('DB_PATH',  __DIR__ . '/.db.db');
define('MASTER_KEY', hex2bin(
    'cd6a83f8d5f85a71dd68898ba867642cf0d5c3b92e2e1bae9fb23a26d60d70bc'
));

const SALT_LEN = 16;
const IV_LEN   = 12;

/* ───── crypto ─────────────────────────────────────── */

function hkdf(string $ikm, string $salt, int $len = 32): string {
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    return substr(hash_hmac('sha256', "\x01", $prk, true), 0, $len);
}

function decrypt_payload(string $b64) {
    $bin = base64_decode($b64, true);
    if ($bin === false || strlen($bin) < SALT_LEN + IV_LEN + 16) {
        log_debug("Decrypt failed: invalid base64 or too short", ['input' => $b64]);
        return false;
    }

    $salt = substr($bin, 0, SALT_LEN);
    $iv   = substr($bin, SALT_LEN, IV_LEN);
    $ct   = substr($bin, SALT_LEN + IV_LEN);
    $tag  = substr($ct, -16);
    $cipher = substr($ct, 0, -16);

    $decrypted = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        hkdf(MASTER_KEY, $salt),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    log_debug("Payload decrypted", ['plain' => $decrypted]);
    return $decrypted;
}

function encrypt_payload(string $plain): string {
    $salt = random_bytes(SALT_LEN);
    $iv   = random_bytes(IV_LEN);
    $key  = hkdf(MASTER_KEY, $salt);

    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    $encoded = base64_encode($salt . $iv . $cipher . $tag);
    log_debug("Payload encrypted", ['cipher' => $encoded]);
    return $encoded;
}

/* ───── PDO helper ─────────────────────────────────── */

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $pdo = new PDO(
        'sqlite:' . DB_PATH,
        null,
        null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_dns_url ON dns(url)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_playlist_mac ON playlist(mac_address)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_playlist_mac_dns ON playlist(mac_address, dns_id)');

    log_debug("Database connection established");
    return $pdo;
}

/* ───── MAC normaliser ─────────────────────────────── */

function normalise_mac(string $raw): string {
    $original = $raw;
    $dec = base64_decode($raw, true);
    if ($dec !== false) $raw = $dec;

    $raw = strtolower(trim($raw));
    if (strpos($raw, '00:') === 0) $raw = substr($raw, 3);

    $hex = preg_replace('/[^0-9a-f]/', '', $raw);
    $pairs = array_filter(
        str_split($hex, 2),
        fn ($p) => strlen($p) === 2
    );

    $mac = implode(':', $pairs);
    log_debug("MAC normalized", ['original' => $original, 'normalized' => $mac]);
    return $mac;
}

/* ───── request data  ──────────────────────────────── */

$action  = $_POST['action'] ?? '';
$payload = $_POST['data']   ?? '';

log_debug("Incoming request", ['action' => $action, 'raw_payload' => $payload]);

if (!$action || !$payload) {
    http_response_code(400);
    $resp = ['error' => 'Missing parameters'];
    log_debug("Final response", $resp);
    echo json_encode($resp);
    ob_end_flush(); flush();
    exit;
}

$plain = decrypt_payload($payload);
if ($plain === false) {
    http_response_code(400);
    $resp = ['error' => 'Decryption failed'];
    log_debug("Final response", $resp);
    echo json_encode($resp);
    ob_end_flush(); flush();
    exit;
}

$pdo = db();

/* ───── routing  ───────────────────────────────────── */

switch ($action) {

case 'fetch_dns': {
    $urls = $pdo->query('SELECT url FROM dns ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    log_debug("fetch_dns: urls fetched", $urls);

    $json = json_encode($urls, JSON_UNESCAPED_SLASHES);
    $encrypted = encrypt_payload($json);

    log_debug("fetch_dns response encrypted", ['json' => $json, 'encrypted' => $encrypted]);

    header('Content-Length: ' . strlen($encrypted));
    echo $encrypted;

    if (ob_get_level()) ob_end_flush();
    flush();
    break;
}


case 'submit_url': {
    $data = json_decode($plain, true) ?: [];
    $mac  = normalise_mac($data['mac'] ?? '');
    $url  = trim($data['url'] ?? '');

    if (!$mac || !$url) {
        http_response_code(400);
        $resp = ['error' => 'Missing mac or url'];
        log_debug("Final response", $resp);
        echo json_encode($resp);
        ob_end_flush(); flush();
        break;
    }

    $parsed = parse_url($url);
    parse_str($parsed['query'] ?? '', $params);

    $username = $params['username'] ?? '';
    $password = $params['password'] ?? '';

    log_debug("Parsed URL", compact('parsed', 'params'));

    if (empty($parsed['scheme']) || empty($parsed['host']) || $username === '' || $password === '') {
        http_response_code(400);
        $resp = ['error' => 'Invalid or incomplete URL'];
        log_debug("Final response", $resp);
        echo json_encode($resp);
        ob_end_flush(); flush();
        break;
    }

    $base_url = $parsed['scheme'] . '://' . $parsed['host']
              . (isset($parsed['port']) ? ':' . $parsed['port'] : '');

    $stmt = $pdo->prepare('SELECT id FROM dns WHERE url = ?');
    $stmt->execute([$base_url]);
    $dns_id = $stmt->fetchColumn();

    if (!$dns_id) {
        http_response_code(404);
        $resp = ['error' => 'DNS not found'];
        log_debug("Final response", $resp);
        echo json_encode($resp);
        ob_end_flush(); flush();
        break;
    }

    $now = time();
    // Check if entry exists
$stmt = $pdo->prepare('SELECT COUNT(*) FROM playlist WHERE mac_address = :mac AND dns_id = :dns');
$stmt->execute([':mac' => $mac, ':dns' => $dns_id]);
$exists = $stmt->fetchColumn() > 0;

if ($exists) {
    // Update existing row
    $stmt = $pdo->prepare(
        'UPDATE playlist
         SET username = :user, password = :pass, last_used = :now
         WHERE mac_address = :mac AND dns_id = :dns'
    );
} else {
    // Insert new row
    $stmt = $pdo->prepare(
        'INSERT INTO playlist (dns_id, mac_address, username, password, pin, last_used)
         VALUES (:dns, :mac, :user, :pass, "", :now)'
    );
}

$stmt->execute([
    ':dns'  => $dns_id,
    ':mac'  => $mac,
    ':user' => $username,
    ':pass' => $password,
    ':now'  => $now
]);


    $resp = ['status' => 'ok'];
    log_debug("submit_url success", $resp);
    echo json_encode($resp);
    ob_end_flush(); flush();
    break;
}

case 'check_mac': {
    $data = json_decode($plain, true) ?: [];
    $mac  = normalise_mac($data['mac'] ?? '');

    log_debug("Executing MAC check", ['mac' => $mac]);

    $all_macs = $pdo->query("SELECT mac_address FROM playlist")->fetchAll(PDO::FETCH_COLUMN);
    log_debug("MACs in DB", $all_macs);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM playlist WHERE mac_address = ?');
    $executed = $stmt->execute([$mac]);

    if (!$executed) {
        log_debug("MAC check query failed to execute", ['mac' => $mac]);
    }

    $rawCount = $stmt->fetchColumn();
    log_debug("Raw fetchColumn result", ['value' => $rawCount, 'type' => gettype($rawCount)]);

    $count = is_numeric($rawCount) ? (int)$rawCount : 0;
    $exists = $count > 0;

    $resp = ['exists' => $exists];
    $json = json_encode($resp);
    log_debug("check_mac response json", ['json' => $json, 'exists_boolean' => $exists]);

    header('Content-Length: ' . strlen($json));
    echo $json;

    if (ob_get_level()) ob_end_flush();
    flush();
    break;
}


default:
    http_response_code(400);
    $resp = ['error' => 'Invalid action'];
    log_debug("Final response", $resp);
    echo json_encode($resp);
    ob_end_flush(); flush();
}
