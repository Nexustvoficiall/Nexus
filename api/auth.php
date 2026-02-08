<?php
/* ────────── crypto helpers ────────── */
function mope_decrypt($input){
    $c = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
    if (strlen($input) < 2) return false;
    $p1 = strpos($c, $input[-2]) - 15;
    $p2 = strpos($c, $input[-1]);
    if ($p1 === false || $p2 === false || $p1 < 0 || $p2 < 0) return false;
    $core = substr($input, 0, -2);
    $clean = substr($core, 0, $p1) . substr($core, $p1 + $p2);
    $d = base64_decode($clean, true);
    return $d !== false ? trim($d) : false;
}

function mope_encrypt($json){
    $c = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
    $b = base64_encode($json);
    $at = rand(0, min(40, strlen($b) - 1));
    $n = rand(10, 30);
    $junk = '';
    for ($i = 0; $i < $n; $i++) $junk .= $c[random_int(0, 61)];
    $o = substr($b, 0, $at + 2) . $junk . substr($b, $at + 2);
    return $o . $c[$at + 17] . $c[$n];
}

function log_dbg($stage, $data){
    $maxLength = 3000;
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $timestamp = '[' . date('Y-m-d H:i:s') . "] $stage ";

    if (strlen($json) <= $maxLength) {
        file_put_contents(__DIR__ . '/debug_log.json', $timestamp . $json . PHP_EOL, FILE_APPEND);
    } else {
        $chunks = str_split($json, $maxLength);
        foreach ($chunks as $i => $chunk) {
            file_put_contents(
                __DIR__ . '/debug_log.json',
                $timestamp . "(part " . ($i + 1) . ") " . $chunk . PHP_EOL,
                FILE_APPEND
            );
        }
    }
}

require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

try {
    $raw = file_get_contents('php://input');
    log_dbg('RAW_INPUT', $raw);

    $in = json_decode($raw, true);
    log_dbg('PARSED_INPUT', $in);

    if (!isset($in['data'])) {
        log_dbg('ERROR', 'Missing `data` field');
        http_response_code(400);
        echo '{"error":"missing data"}';
        exit;
    }

    $plain = mope_decrypt($in['data']);
    log_dbg('DECRYPTED_STRING', $plain);

    $req = json_decode($plain, true);
    log_dbg('DECRYPTED_JSON', $req);

    if (!is_array($req)) {
        log_dbg('ERROR', 'Decrypted payload is not valid JSON');
        http_response_code(400);
        echo '{"error":"bad payload"}';
        exit;
    }

    function normalise_mac(string $raw): string {
        $dec = base64_decode($raw, true);
        if ($dec !== false) $raw = $dec;
        $raw = strtolower(trim($raw));
        if (strpos($raw, '00:') === 0) $raw = substr($raw, 3);
        $hex = preg_replace('/[^0-9a-f]/', '', $raw);
        $pairs = array_filter(str_split($hex, 2), static fn($p) => strlen($p) === 2);
        return strtoupper(implode(':', $pairs));
    }

    $macDisplay = isset($req['app_device_id'])
        ? normalise_mac($req['app_device_id'])
        : normalise_mac($req['mac_address'] ?? '');
    $macKey = strtolower($macDisplay);

    log_dbg('MAC_DISPLAY', $macDisplay);
    log_dbg('MAC_DB_KEY', $macKey);

    $playlistRows = $db->select(
        'playlist',
        '*',
        'LOWER(mac_address) = :m AND username <> "" AND password <> ""',
        'last_used DESC',
        [':m' => $macKey]
    );
    log_dbg('PLAYLIST_ROWS_COUNT', count($playlistRows));
    log_dbg('PLAYLIST_ROWS_RAW', $playlistRows);

    $portal = [];
    $dnsCache = [];
    $deviceKey = '';

    function generateDeviceKey($length = 8) {
        $chars = '1234567890';
        $key = '';
        for ($i = 0; $i < $length; $i++) {
            $key .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $key;
    }

    $existingKeyRow = $db->select(
        'playlist',
        'device_key',
        'LOWER(mac_address) = :mac AND device_key IS NOT NULL AND device_key <> ""',
        'id ASC LIMIT 1',
        [':mac' => $macKey]
    );

    if (!empty($existingKeyRow)) {
        $deviceKey = $existingKeyRow[0]['device_key'];
    } else {
        $deviceKey = generateDeviceKey();
        $db->update('playlist', ['device_key' => $deviceKey], 'LOWER(mac_address) = :mac', [':mac' => $macKey]);
    }
    log_dbg('DEVICE_KEY', $deviceKey);

    foreach ($playlistRows as &$pl) {
        $pl['device_key'] = $deviceKey;
    }

    foreach ($playlistRows as &$pl) {
        $dnsId = $pl['dns_id'];
        if (!isset($dnsCache[$dnsId])) {
            $dnsRow = $db->select('dns', '*', 'id = :id', [], [':id' => $dnsId]);
            if (!$dnsRow) {
                log_dbg('DNS_MISSING', $dnsId);
                continue;
            }
            $dnsCache[$dnsId] = $dnsRow[0];
        }
        $dns = $dnsCache[$dnsId];

        $portal[] = [
            'id'           => (string) $dns['id'],
            'name'         => $dns['title'],
            'url'          => rtrim($dns['url'], '/') . "/get.php?username={$pl['username']}&password={$pl['password']}&type=m3u_plus&output=ts",
            'type'         => 'm3u',
            'is_protected' => '0',
            'created_at'   => $pl['created_at'] ?? '',
            'updated_at'   => $pl['updated_at'] ?? ''
        ];
    }
    log_dbg('PORTALS', $portal);

    $apkData = $db->select('apk_update', '*', '', 'id DESC LIMIT 1');
    $appVersion = $apkData[0]['version'] ?? '1.20';
    $apkUrl = $apkData[0]['apk_url'] ?? 'https://iboiptv.com/upload/android_1.1.apk';

    $settings = $db->select('settings', 'tmdb_key', '', 'id DESC LIMIT 1');
    $tmdbKey = $settings[0]['tmdb_key'] ?? 'missing_tmdb_key';

    $pinRow = $db->select(
        'playlist',
        'pin',
        'LOWER(mac_address) = :mac AND pin IS NOT NULL AND pin <> ""',
        'id DESC LIMIT 1',
        [':mac' => $macKey]
    );
    $parentalControl = $pinRow[0]['pin'] ?? '0000';

    $response = [
        'android_version_code' => $appVersion,
        'apk_url'              => $apkUrl,
        'device_key'           => $deviceKey,
        'expire_date'          => '2029-05-11',
        'is_google_paid'       => false,
        'is_trial'             => 0,
        'mac_registered'       => true,
        'mac_address'          => $macDisplay,
        'urls'                 => $portal,
        'trial_days'           => 99,
        'tmdbKey'              => $tmdbKey,
        'price'                => '9.99',
        'app_version'          => $appVersion,
        'serverTime'           => time(),
        'windows'              => [
            'version' => '1.0.1.0',
            'link'    => 'https://store.4kapps.com/windows/IPTVSmart4K.exe'
        ],
        'has_own_playlist'     => true,
        'apk_link'             => $apkUrl,
        'parent_control'       => $parentalControl,
        'parent_synced'        => 1,
        'lock'                 => 0
    ];

    $responseJson = json_encode($response, JSON_UNESCAPED_SLASHES);
    log_dbg('RESPONSE_JSON', $responseJson);

    $encrypted = mope_encrypt($responseJson);
    log_dbg('RESPONSE_ENCRYPTED', $encrypted);

    echo json_encode([
        'plain' => $response,
        'data'  => $encrypted
    ]);
} catch (Throwable $e) {
    log_dbg('FATAL_EXCEPTION', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'trace'   => $e->getTraceAsString()
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'internal server error']);
}
