<?php
/**
 * Merge selected KEY=value lines into an existing .env without touching other keys.
 * Usage: php merge_env_keys.php /path/to/.env /path/to/overlay.env
 */
if ($argc < 3) {
    fwrite(STDERR, "usage: merge_env_keys.php DEST.env OVERLAY.env\n");
    exit(1);
}
$dest = $argv[1];
$overlay = $argv[2];
if (!is_file($dest) || !is_readable($dest)) {
    fwrite(STDERR, "missing dest env\n");
    exit(1);
}
if (!is_file($overlay) || !is_readable($overlay)) {
    fwrite(STDERR, "missing overlay\n");
    exit(1);
}

$pairs = [];
foreach (file($overlay, FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    if ($k === '') {
        continue;
    }
    $pairs[$k] = $v;
}
if ($pairs === []) {
    fwrite(STDERR, "empty overlay\n");
    exit(1);
}

$lines = file($dest, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "cannot read dest\n");
    exit(1);
}
$seen = [];
foreach ($lines as $i => $line) {
    if ($line === '' || ($line[0] ?? '') === '#' || !str_contains($line, '=')) {
        continue;
    }
    $k = trim(explode('=', $line, 2)[0]);
    if (isset($pairs[$k])) {
        $lines[$i] = $k . '=' . $pairs[$k];
        $seen[$k] = true;
    }
}
foreach ($pairs as $k => $v) {
    if (empty($seen[$k])) {
        $lines[] = $k . '=' . $v;
    }
}

$ok = file_put_contents($dest, implode("\n", $lines) . "\n");
if ($ok === false) {
    fwrite(STDERR, "write failed\n");
    exit(1);
}
echo 'merged ' . count($pairs) . " keys into {$dest}\n";
