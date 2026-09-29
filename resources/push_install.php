<?php

// Only call these functions from an authenticated administrator action.
function callassist_atomic_copy(string $source, string $target): bool {
    if (!is_file($source) || !is_readable($source) || is_link($target)) {
        throw new RuntimeException('Source unavailable or target is a symbolic link');
    }
    if (is_file($target) && is_readable($target) && hash_file('sha256', $source) === hash_file('sha256', $target)) {
        return false;
    }
    $directory = dirname($target);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('Target directory is not writable: ' . $directory);
    }
    $temporary = tempnam($directory, '.callassist-');
    if ($temporary === false) throw new RuntimeException('Unable to create temporary file');
    try {
        if (!copy($source, $temporary)) throw new RuntimeException('Unable to copy Lua file');
        chmod($temporary, is_file($target) ? (fileperms($target) & 0777) : 0644);
        if (!rename($temporary, $target)) throw new RuntimeException('Unable to install Lua file');
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
    return true;
}

function callassist_install_hook(string $config_path): bool {
    if (!is_file($config_path) || !is_readable($config_path) || is_link($config_path)) {
        throw new RuntimeException('Lua configuration unavailable or is a symbolic link');
    }
    if (!class_exists('DOMDocument')) throw new RuntimeException('PHP XML extension is unavailable');
    $xml = new DOMDocument();
    $xml->preserveWhiteSpace = true;
    $previous = libxml_use_internal_errors(true);
    $loaded = $xml->load($config_path, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) throw new RuntimeException('Invalid Lua configuration XML');

    $settings = $xml->getElementsByTagName('settings')->item(0);
    if (!$settings) throw new RuntimeException('No settings element in Lua configuration');
    $matches = [];
    foreach ($xml->getElementsByTagName('hook') as $hook) {
        if ($hook->getAttribute('event') === 'CHANNEL_OUTGOING'
            && $hook->getAttribute('script') === 'callassist_mobile_push.lua') {
            $matches[] = $hook;
        }
    }
    if (count($matches) === 1) return false;
    foreach ($matches as $hook) $hook->parentNode->removeChild($hook);
    $settings->appendChild($xml->createTextNode("\n    "));
    $settings->appendChild($xml->createComment(' CallAssist Mobile: push notifications for forwarded calls '));
    $settings->appendChild($xml->createTextNode("\n    "));
    $hook = $xml->createElement('hook');
    $hook->setAttribute('event', 'CHANNEL_OUTGOING');
    $hook->setAttribute('script', 'callassist_mobile_push.lua');
    $settings->appendChild($hook);
    $settings->appendChild($xml->createTextNode("\n"));

    $directory = dirname($config_path);
    if (!is_writable($directory)) throw new RuntimeException('Configuration directory is not writable: ' . $directory);
    $backup = $config_path . '.callassist-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.bak';
    if (!copy($config_path, $backup)) throw new RuntimeException('Unable to back up Lua configuration');
    chmod($backup, fileperms($config_path) & 0777);
    $temporary = tempnam($directory, '.callassist-');
    if ($temporary === false) throw new RuntimeException('Unable to create temporary configuration');
    try {
        $contents = $xml->saveXML();
        if ($contents === false || file_put_contents($temporary, $contents) !== strlen($contents)) {
            throw new RuntimeException('Unable to write Lua configuration');
        }
        chmod($temporary, fileperms($config_path) & 0777);
        if (!rename($temporary, $config_path)) throw new RuntimeException('Unable to install Lua configuration');
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
    return true;
}
