#!/usr/bin/env php
<?php
/**
 * Writes an environment variable's content to a file.
 *
 * Usage:
 *   ./file_from_env.php ENV_VAR PATH_TO_FILE
 */

if ($argc < 3) {
    echo "This script expects two parameters:\n";
    echo "./file_from_env.php ENV_VAR PATH_TO_FILE\n";
    exit(1);
}

$content = getenv($argv[1]);
if (!$content) {
    echo "Variable was empty\n";
    exit(1);
}

file_put_contents($argv[2], $content);
echo "Done...\n";
