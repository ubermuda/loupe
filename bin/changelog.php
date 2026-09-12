#!/usr/bin/env php
<?php

declare(strict_types=1);

// Folds changelog.d/<pull-request>.md fragments into the [Unreleased] section
// of docs/CHANGELOG.md, newest pull request first, then deletes the fragments
// it consumed. Usage: php bin/changelog.php [--check] [--root=PATH]

const UNRELEASED_HEADING = "## [Unreleased]\n";

$check = false;
$root = \dirname(__DIR__);

foreach (\array_slice($argv, 1) as $argument) {
    if ('--check' === $argument) {
        $check = true;
        continue;
    }

    if (str_starts_with($argument, '--root=')) {
        $root = rtrim(substr($argument, 7), '/');
        continue;
    }

    fwrite(\STDERR, sprintf("Unknown argument: %s\n", $argument));
    exit(2);
}

$fragmentDir = $root.'/changelog.d';
$changelogPath = $root.'/docs/CHANGELOG.md';

$names = is_dir($fragmentDir) ? scandir($fragmentDir) : false;
if (false === $names) {
    fwrite(\STDERR, sprintf("Cannot read the fragment directory %s\n", $fragmentDir));
    exit(1);
}

$changelog = file_get_contents($changelogPath);
if (false === $changelog) {
    fwrite(\STDERR, sprintf("Cannot read %s\n", $changelogPath));
    exit(1);
}

$headingAt = strpos($changelog, UNRELEASED_HEADING);
if (false === $headingAt) {
    fwrite(\STDERR, sprintf("%s carries no \"%s\" heading\n", $changelogPath, trim(UNRELEASED_HEADING)));
    exit(1);
}

$unreleased = substr($changelog, $headingAt);

/** @var array<int, string> $fragments */
$fragments = [];
/** @var list<string> $errors */
$errors = [];

sort($names);

foreach ($names as $name) {
    if (1 !== preg_match('/^(\d+)\.md$/', $name, $matches)) {
        continue;
    }

    $number = (int) $matches[1];
    $text = file_get_contents($fragmentDir.'/'.$name);

    if (false === $text) {
        $errors[] = sprintf('%s: cannot be read.', $name);
        continue;
    }

    $text = rtrim($text);

    if (!str_starts_with($text, '- (#')) {
        $errors[] = sprintf('%s: the first line must start "- (#%d) ".', $name, $number);
        continue;
    }

    foreach (explode("\n", $text) as $line) {
        if (!str_starts_with($line, '- (#')) {
            continue;
        }

        if (1 !== preg_match('/^- \(#(\d+)\) /', $line, $anchor) || $number !== (int) $anchor[1]) {
            $errors[] = sprintf('%s: an entry line anchors elsewhere than (#%d): %s', $name, $number, $line);
        }
    }

    if (1 === preg_match('/^- \(#'.$number.'\) /m', $unreleased)) {
        $errors[] = sprintf('%s: (#%d) already appears under [Unreleased]. Delete the fragment.', $name, $number);
        continue;
    }

    $fragments[$number] = $text;
}

if ([] !== $errors) {
    foreach ($errors as $error) {
        fwrite(\STDERR, $error."\n");
    }

    exit(1);
}

if ($check) {
    printf("%d changelog fragment(s) read, all well formed.\n", \count($fragments));
    exit(0);
}

if ([] === $fragments) {
    echo "No changelog fragments to fold in.\n";
    exit(0);
}

krsort($fragments);

$insertAt = $headingAt + \strlen(UNRELEASED_HEADING);
$prefix = substr($changelog, 0, $insertAt);
$rest = substr($changelog, $insertAt);

if (str_starts_with($rest, "\n")) {
    $rest = substr($rest, 1);
}

$prefix .= "\n";

// The changelog is written before a fragment is deleted. A run that dies
// between the two leaves every fragment in place, and the repeat run stops on
// the anchor it now finds under [Unreleased].
if (false === file_put_contents($changelogPath, $prefix.implode("\n\n", $fragments)."\n\n".$rest)) {
    fwrite(\STDERR, sprintf("Cannot write %s\n", $changelogPath));
    exit(1);
}

foreach (array_keys($fragments) as $number) {
    unlink(sprintf('%s/%d.md', $fragmentDir, $number));
}

printf(
    "Folded %d changelog fragment(s) into docs/CHANGELOG.md: %s\n",
    \count($fragments),
    implode(', ', array_map(static fn (int $number): string => '#'.$number, array_keys($fragments))),
);
