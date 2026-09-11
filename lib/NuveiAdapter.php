<?php
/**
 * Compatibility shim — canonical implementation lives in Adapters/NuveiAdapter.php.
 * Keeps old require paths (lib/NuveiAdapter.php) working without a second class.
 */
if (!class_exists('NuveiAdapter', false)) {
    require_once __DIR__ . '/Adapters/NuveiAdapter.php';
}
