#!/usr/bin/env php
<?php

/**
 * Find all string literals in PHP files under a given directory.
 * Usage: php bin/find-string-literals.php [directory].
 */
$dir = $argv[1] ?? '.';

if (!is_dir($dir)) {
    fprintf(STDERR, "Error: '%s' is not a directory\n", $dir);
    exit(1);
}

$dir = realpath($dir);
if (false === $dir) {
    fprintf(STDERR, "Error: could not resolve directory path\n");
    exit(1);
}
$files = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)),
    '/\.php$/i'
);

foreach ($files as $file) {
    $path = $file->getPathname();
    $source = file_get_contents($path);
    if (false === $source) {
        fprintf(STDERR, "Warning: could not read '%s'\n", $path);
        continue;
    }

    $tokens = token_get_all($source);
    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }
        [$type, $value, $line] = $token;
        // Note: T_START_HEREDOC / T_END_HEREDOC and T_ENCAPSED_AND_WHITESPACE inside
        // heredoc/nowdoc blocks are intentionally excluded. Heredoc content uses the same
        // T_ENCAPSED_AND_WHITESPACE token as interpolated strings, but there is no reliable
        // way to distinguish the two without tracking heredoc open/close tokens, so heredoc
        // and nowdoc literals are silently skipped.
        if (T_CONSTANT_ENCAPSED_STRING !== $type && T_ENCAPSED_AND_WHITESPACE !== $type) {
            continue;
        }
        printf("%s:%d\t%s\n", $path, $line, $value);
    }
}
