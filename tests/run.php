<?php
/**
 * Ejecuta todos los tests: php tests/run.php
 */
require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/test-*.php') as $file) {
    echo "\n=== " . basename($file) . " ===\n";
    require $file;
}

echo "\n{$GLOBALS['t_count']} comprobaciones, {$GLOBALS['t_fails']} fallos\n";
exit($GLOBALS['t_fails'] ? 1 : 0);
