<?php

// Installation check and repair for central administrators.
require_once dirname(__DIR__, 2) . '/resources/require.php';
require_once 'resources/check_auth.php';

if (!permission_exists('callassist_manage') || !if_group('superadmin')) {
    echo 'access denied';
    exit;
}

$language = new text;
$text = $language->get($_SESSION['domain']['language']['code'], 'app/callassist_mobile');
$locale = $_SESSION['domain']['language']['code'] ?? 'en-us';
// FusionPBX can cache language phrases until a Languages upgrade. Use the
// central app_languages.php file for newly added phrases missing from that cache.
if (empty($text['button-push_apply']) || empty($text['description-push_apply'])) {
    $file_text = (static function (): array {
        $text = [];
        require __DIR__ . '/app_languages.php';
        return $text;
    })();
    foreach (['button-push_apply', 'description-push_apply', 'message-push_applied', 'message-push_error'] as $key) {
        if (empty($text[$key])) {
            $text[$key] = $file_text[$key][$locale] ?? $file_text[$key]['en-us'] ?? '';
        }
    }
}
$labels = [
    'title' => $text['title-push_status'],
    'installed' => $text['label-push_installed_lua'],
    'version' => $text['label-push_lua_version'],
    'config' => $text['label-push_lua_config'],
    'hook' => $text['label-push_hook'],
    'ok' => $text['label-push_ok'],
    'missing' => $text['label-push_missing'],
    'unreadable' => $text['label-push_unreadable'],
    'outdated' => $text['label-push_outdated'],
    'invalid' => $text['label-push_invalid_xml'],
    'hook_missing' => $text['label-push_hook_missing'],
    'hook_duplicate' => $text['label-push_hook_duplicate'],
    'xml_missing' => $text['label-push_xml_missing'],
    'note' => $text['description-push_status'],
    'back' => $text['button-back'],
    'apply' => $text['button-push_apply'],
    'apply_description' => $text['description-push_apply'],
    'applied' => $text['message-push_applied'],
    'error' => $text['message-push_error'],
];

// Common package and source-install locations. Never execute or modify files.
$locations = [
    ['/usr/share/freeswitch/scripts/callassist_mobile_push.lua', '/etc/freeswitch/autoload_configs/lua.conf.xml'],
    ['/usr/local/freeswitch/scripts/callassist_mobile_push.lua', '/usr/local/freeswitch/conf/autoload_configs/lua.conf.xml'],
];
$location = $locations[0];
foreach ($locations as $candidate) {
    if (is_file($candidate[0]) || is_file($candidate[1])) {
        $location = $candidate;
        break;
    }
}
[$script_path, $config_path] = $location;
$bundled_path = __DIR__ . '/callassist_mobile_push.lua';

if (empty($_SESSION['callassist_push_csrf'])) {
    $_SESSION['callassist_push_csrf'] = bin2hex(random_bytes(32));
}
$install_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['apply'] ?? '') === '1') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['callassist_push_csrf'], $_POST['csrf'])) {
        http_response_code(403);
        exit;
    }
    try {
        require_once __DIR__ . '/resources/push_install.php';
        callassist_atomic_copy($bundled_path, $script_path);
        $changed = callassist_install_hook($config_path);
        $install_message = $labels['applied'] . ($changed ? ' ' . $labels['note'] : '');
    } catch (Throwable $exception) {
        $install_message = $labels['error'] . ' ' . $exception->getMessage();
    }
    $_SESSION['callassist_push_csrf'] = bin2hex(random_bytes(32));
}

function push_file_status(string $path, array $labels): string {
    if (!is_file($path)) return $labels['missing'];
    if (!is_readable($path)) return $labels['unreadable'];
    return $labels['ok'];
}

$script_status = push_file_status($script_path, $labels);
$version_status = $labels['missing'];
if ($script_status === $labels['ok'] && is_readable($bundled_path)) {
    $version_status = hash_file('sha256', $script_path) === hash_file('sha256', $bundled_path)
        ? $labels['ok'] : $labels['outdated'];
} elseif ($script_status === $labels['unreadable']) {
    $version_status = $labels['unreadable'];
}

$config_status = push_file_status($config_path, $labels);
$hook_status = $config_status;
if ($config_status === $labels['ok']) {
    if (!class_exists('DOMDocument')) {
        $hook_status = $labels['xml_missing'];
    } else {
        $xml = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $xml->load($config_path, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            $hook_status = $labels['invalid'];
        } else {
            $hooks = $xml->getElementsByTagName('hook');
            $matching_count = 0;
            foreach ($hooks as $hook) {
                if ($hook->getAttribute('event') !== 'CHANNEL_OUTGOING') continue;
                if ($hook->getAttribute('script') === 'callassist_mobile_push.lua') $matching_count++;
            }
            if ($matching_count === 1) {
                $hook_status = $labels['ok'];
            } elseif ($matching_count > 1) {
                $hook_status = $labels['hook_duplicate'];
            } else {
                $hook_status = $labels['hook_missing'];
            }
        }
    }
}

$document['title'] = $labels['title'];
require_once 'resources/header.php';
echo "<div class='action_bar' id='action_bar'>\n";
echo "<div class='heading'><b>" . escape($labels['title']) . "</b></div>\n";
echo "<div class='actions'>\n";
echo button::create(['type'=>'button','label'=>$labels['back'],'icon'=>$_SESSION['theme']['button_icon_back'],'link'=>'callassist.php']);
echo "</div>\n";
echo "<div style='clear: both;'></div>\n";
echo "</div>\n";
if ($install_message !== '') echo '<p>' . escape($install_message) . "</p>\n";
echo "<form name='frm' id='frm' method='post'>\n";
echo "<input type='hidden' name='csrf' value='" . escape($_SESSION['callassist_push_csrf']) . "'>\n";
echo "<div class='card'>\n";
echo "<table cellpadding='0' cellspacing='0' border='0' width='100%'>\n";
echo "<tbody>\n";
foreach ([
    [$labels['installed'], $script_path, $script_status],
    [$labels['version'], $bundled_path, $version_status],
    [$labels['config'], $config_path, $config_status],
    [$labels['hook'], '&lt;hook event=&quot;CHANNEL_OUTGOING&quot; script=&quot;callassist_mobile_push.lua&quot;/&gt;', $hook_status],
] as [$name, $detail, $status]) {
    $detail_html = $name === $labels['hook'] ? $detail : escape($detail);
    echo "<tr>\n";
    echo "<td width='30%' class='vncell' valign='top'>" . escape($name) . "</td>\n";
    echo "<td width='70%' class='vtable'><strong>" . escape($status) . "</strong><br>" . $detail_html . "</td>\n";
    echo "</tr>\n";
}
echo "<tr>\n";
echo "<td class='vncell' valign='top'></td>\n";
echo "<td class='vtable'>" . escape($labels['apply_description']) . "<br><br>";
echo button::create([
    'type' => 'submit',
    'label' => $labels['apply'],
    'icon' => $_SESSION['theme']['button_icon_save'],
    'name' => 'apply',
    'value' => '1',
    'collapse' => 'never',
]);
echo "</td>\n";
echo "</tr>\n";
echo "</tbody>\n";
echo "</table>\n";
echo "</div>\n";
echo "</form>\n";
echo '<p>' . escape($labels['note']) . "</p>\n";
require_once 'resources/footer.php';
