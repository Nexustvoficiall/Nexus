<?php
/* ───────── dependencies ───────── */
require_once __DIR__ . '/../includes/functions.php';   // supplies $tables + SQLiteWrapper

header('Content-Type: application/json');

/* ───────── crypto helpers ─────── */
function mope_decrypt(string $str){
    $c = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
    if (strlen($str) < 2) return false;
    $p1 = strpos($c, $str[-2]) - 15;
    $p2 = strpos($c, $str[-1]);
    if ($p1 === false || $p2 === false || $p1 < 0 || $p2 < 0) return false;
    $core  = substr($str, 0, -2);
    $clean = substr($core, 0, $p1) . substr($core, $p1 + $p2);
    return base64_decode($clean, true) ?: false;
}

function normalise_mac(string $raw): string {
    $dec = base64_decode($raw, true);
    if ($dec !== false) $raw = $dec;

    $raw = strtolower(trim($raw));
    if (strpos($raw, '00:') === 0) $raw = substr($raw, 3);

    $hex   = preg_replace('/[^0-9a-f]/', '', $raw);
    $pairs = array_filter(str_split($hex, 2), fn ($p) => strlen($p) === 2);

    return implode(':', $pairs);
}

/* ───────── read & validate ────── */
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!isset($input['data'])) {
    echo json_encode(['status' => false, 'message' => 'fail']); exit;
}

$plain   = mope_decrypt($input['data']);
$payload = json_decode($plain, true);

if (!is_array($payload) ||
    empty($payload['mac_address']) ||
    empty($payload['parent_control'])) {
    echo json_encode(['status' => false, 'message' => 'fail']); exit;
}

$mac = normalise_mac($payload['mac_address']);
$pin = substr(preg_replace('/\D/', '', $payload['parent_control']), 0, 6); // numeric, ≤6 chars

/* ───────── database update ────── */
$dbFile = __DIR__ . '/../api/.db.db';
$DB     = new SQLiteWrapper($dbFile);

$ok = $DB->update(
    'playlist',
    ['pin' => $pin],              // ONLY pin changes
    'mac_address = :mac',
    [':mac' => $mac]
);

/* ───────── response ───────────── */
echo json_encode([
    'status'  => (bool)$ok,
    'message' => $ok ? 'success' : 'fail'
]);
