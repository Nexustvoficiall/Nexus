<?php
/*  ──────────────────────────────────────────────────────────────
    DELETE  /playlists/delete
    Payload  { "data": "<mope-encoded JSON>" }
    Decrypted JSON must contain:
        mac_address   – base-64 or plain MAC
        playlist_id   – row ID in playlist table
   ────────────────────────────────────────────────────────────── */

/* ── helpers copied from your other endpoints ───────────────── */
require_once __DIR__ . '/../includes/functions.php';   // gives $db (SQLiteWrapper)

function mope_decrypt($input){
    $c='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
    if(strlen($input)<2) return false;
    $p1=strpos($c,$input[-2])-15; $p2=strpos($c,$input[-1]);
    if($p1===false||$p2===false||$p1<0||$p2<0) return false;
    $core  = substr($input,0,-2);
    $clean = substr($core,0,$p1).substr($core,$p1+$p2);
    $d     = base64_decode($clean,true);
    return $d!==false?trim($d):false;
}
function normalise_mac(string $raw): string {
    $dec = base64_decode($raw, true);
    if ($dec !== false) $raw = $dec;
    $raw = strtolower(trim($raw));
    if (strpos($raw, '00:') === 0) $raw = substr($raw, 3);
    $hex   = preg_replace('/[^0-9a-f]/', '', $raw);
    $pairs = array_filter(str_split($hex, 2), static fn($p)=>strlen($p)===2);
    return implode(':', $pairs);
}
function log_dbg(string $stage, $data): void {
    file_put_contents(
        __DIR__.'/debug_delete.log',
        '['.date('Y-m-d H:i:s')."] $stage ".
        json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL,
        FILE_APPEND
    );
}

/* ── read request ───────────────────────────────────────────── */
header('Content-Type: application/json');
$raw = file_get_contents('php://input');      log_dbg('RAW_INPUT', $raw);
$in  = json_decode($raw, true);               log_dbg('PARSED_INPUT', $in);

if (!is_array($in) || !isset($in['data'])) {
    http_response_code(400);
    echo json_encode(['status'=>false,'message'=>'missing data']);  exit;
}

$decStr = mope_decrypt($in['data']);          log_dbg('DEC_STRING', $decStr);
$req    = json_decode($decStr, true);         log_dbg('DEC_JSON',   $req);

if (!is_array($req)) {
    http_response_code(400);
    echo json_encode(['status'=>false,'message'=>'payload decrypt failed']);  exit;
}

/* ── validate fields ────────────────────────────────────────── */
$mac = normalise_mac($req['mac_address'] ?? '');
$id  = $req['playlist_id']       ?? '';

if ($mac === '' || $id === '') {
    http_response_code(400);
    echo json_encode(['status'=>false,'message'=>'mac_address or playlist_id missing']); exit;
}

/* ── count before delete (so we can report) ────────────────── */
$countArr = $db->select(
    'playlist',
    'COUNT(*) AS c',
    'id = :id AND mac_address = :mac',
    '',
    [':id'=>$id, ':mac'=>strtolower($mac)]
);
$before = (int)($countArr[0]['c'] ?? 0);      log_dbg('ROWCOUNT_BEFORE', $before);

/* ── delete row(s) ─────────────────────────────────────────── */
$db->delete(
    'playlist',
    'id = :id AND mac_address = :mac',
    [':id'=>$id, ':mac'=>strtolower($mac)]
);

/* how many were deleted? do another quick count */
$countArr = $db->select(
    'playlist',
    'COUNT(*) AS c',
    'id = :id AND mac_address = :mac',
    '',
    [':id'=>$id, ':mac'=>strtolower($mac)]
);
$after  = (int)($countArr[0]['c'] ?? 0);
$deleted = $before - $after;                  log_dbg('DELETED', $deleted);

/* ── craft response in official style ─────────────────────── */
$nowNano = (string)(int)(microtime(true)*1e9);

$response = [
    'status'  => $deleted > 0,
    'message' => $deleted > 0 ? 'A playlist deleted!' : 'Nothing deleted',
    'details' => [
        'n'            => $deleted,
        'electionId'   => bin2hex(random_bytes(12)),
        'opTime'       => ['ts'=>$nowNano,'t'=>random_int(800,1200)],
        'ok'           => $deleted > 0 ? 1 : 0,
        '$clusterTime' => [
            'clusterTime' => $nowNano,
            'signature'   => [
                'hash'  => base64_encode(random_bytes(16)),
                'keyId' => (string)random_int(
                                7_000_000_000_000_000_000,
                                9_000_000_000_000_000_000)
            ]
        ],
        'operationTime' => $nowNano,
        'deletedCount'  => $deleted
    ]
];

echo json_encode($response);
