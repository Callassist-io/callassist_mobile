<?php

// Invoked by the FreeSWITCH CHANNEL_OUTGOING Lua hook, never through HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/resources/require.php';

$domain_uuid = $argv[1] ?? '';
$domain_name = $argv[2] ?? '';
$destination = $argv[3] ?? '';
$caller_id = $argv[4] ?? '';
$caller_name = ($argv[5] ?? '') === '--verbose' ? '' : ($argv[5] ?? '');
$current_caller_name = ($argv[6] ?? '') === '--verbose' ? '' : ($argv[6] ?? '');
$verbose = in_array('--verbose', array_slice($argv, 5), true);

function push_debug(string $message): void {
    global $verbose;
    if ($verbose) {
        fwrite(STDOUT, $message . PHP_EOL);
    }
}

if (!preg_match('/^\+?[0-9]+$/', $destination)) {
    push_debug('Invalid destination number');
    exit;
}

$database = new database;

// Prefer the UUID from the call leg; resolve the domain name when it is absent.
if (!preg_match('/^[0-9a-f-]{36}$/i', $domain_uuid)) {
    if ($domain_name === '') {
        push_debug('No domain UUID or name');
        exit;
    }
    $domain = $database->select(
        'SELECT domain_uuid FROM v_domains WHERE domain_name = :domain_name LIMIT 1',
        ['domain_name' => $domain_name],
        'row'
    );
    $domain_uuid = $domain['domain_uuid'] ?? '';
}
if ($domain_uuid === '') {
    push_debug('Domain not found');
    exit;
}
push_debug('Domain UUID: ' . $domain_uuid);
push_debug('Dialed number: ' . $destination);

// One physical outbound leg can represent any route: direct call, IVR or ring group.
// Match the dialed number against active extension forwards in the same domain.
$sql = "SELECT DISTINCT us.user_setting_value
        FROM v_extensions e
        JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid
        JOIN v_user_settings us ON us.user_uuid = eu.user_uuid
            AND us.domain_uuid = e.domain_uuid
        WHERE e.domain_uuid = :domain_uuid
          AND e.forward_all_enabled = 'true'
          AND e.forward_all_destination = :destination
          AND us.user_setting_category = 'callassist'
          AND us.user_setting_subcategory LIKE 'device_%'
          AND us.user_setting_name = 'text'
          AND us.user_setting_enabled = 'true'
          AND us.user_setting_value <> ''";

$tokens = $database->select($sql, [
    'domain_uuid' => $domain_uuid,
    'destination' => $destination,
], 'all');
if (!$tokens) {
    push_debug('No active forward with a registered CallAssist device found');
    if ($verbose) {
        $checks = [
            'Extensions forwarding to this number' =>
                "SELECT count(*) AS total FROM v_extensions e
                 WHERE e.domain_uuid = :domain_uuid
                   AND e.forward_all_destination = :destination",
            'Active forwards to this number' =>
                "SELECT count(*) AS total FROM v_extensions e
                 WHERE e.domain_uuid = :domain_uuid
                   AND e.forward_all_destination = :destination
                   AND e.forward_all_enabled = 'true'",
            'Users linked to those extensions' =>
                "SELECT count(*) AS total FROM v_extensions e
                 JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid
                 WHERE e.domain_uuid = :domain_uuid
                   AND e.forward_all_destination = :destination
                   AND e.forward_all_enabled = 'true'",
            'Registered CallAssist device settings' =>
                "SELECT count(*) AS total FROM v_extensions e
                 JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid
                 JOIN v_user_settings us ON us.user_uuid = eu.user_uuid
                     AND us.domain_uuid = e.domain_uuid
                 WHERE e.domain_uuid = :domain_uuid
                   AND e.forward_all_destination = :destination
                   AND e.forward_all_enabled = 'true'
                   AND us.user_setting_category = 'callassist'
                   AND us.user_setting_subcategory LIKE 'device_%'
                   AND us.user_setting_name = 'text'
                   AND us.user_setting_value <> ''",
        ];
        foreach ($checks as $label => $check_sql) {
            $check = $database->select($check_sql, [
                'domain_uuid' => $domain_uuid,
                'destination' => $destination,
            ], 'row');
            push_debug($label . ': ' . ($check['total'] ?? '?'));
        }
    }
    exit;
}
push_debug('Registered device tokens: ' . count($tokens));

$domain = $database->select(
    'SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid LIMIT 1',
    ['domain_uuid' => $domain_uuid],
    'row'
);
$server = $domain['domain_name'] ?? '';
if ($server === '') {
    push_debug('Domain name not found');
    exit;
}

// Outgoing legs may carry only the extension number as the original caller name.
// FusionPBX stores the display name of an internal caller on the extension.
if (trim($caller_name) === '' || trim($caller_name) === trim($caller_id)) {
    $caller_extension = $database->select(
        "SELECT effective_caller_id_name
         FROM v_extensions
         WHERE domain_uuid = :domain_uuid
           AND (extension = :caller_id OR number_alias = :caller_id)
         LIMIT 1",
        ['domain_uuid' => $domain_uuid, 'caller_id' => $caller_id],
        'row'
    );
    if (!empty($caller_extension['effective_caller_id_name'])) {
        $caller_name = $caller_extension['effective_caller_id_name'];
    } elseif (trim($current_caller_name) !== '' && trim($current_caller_name) !== trim($caller_id)) {
        // A dialplan lookup can rename an external caller after the original
        // caller profile was created. The live originating leg has that name.
        $caller_name = $current_caller_name;
    }
}

foreach ($tokens as $token) {
    $caller_label = $caller_id;
    if (trim($caller_name) !== '' && trim($caller_name) !== trim($caller_id)) {
        $caller_label = trim($caller_name);
        if (trim($caller_id) !== '') {
            $caller_label .= ' (' . trim($caller_id) . ')';
        }
    }
    $payload = [
        'token' => $token['user_setting_value'],
        'title' => 'Incoming call',
        'body' => $caller_label,
    ];
    push_debug('Notification body: ' . $payload['body']);

    $ch = curl_init('http://api.callassist.io/sendpush?server=' . rawurlencode($server));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    push_debug('Push API HTTP status: ' . $status);
    if ($response === false || $status < 200 || $status >= 300) {
        $error = curl_error($ch);
        push_debug('Push API error: ' . ($error !== '' ? $error : 'unexpected HTTP status'));
        error_log('CallAssist push failed: HTTP ' . $status . ' for ' . $server . ($error !== '' ? ': ' . $error : ''));
    }
    curl_close($ch);
}
