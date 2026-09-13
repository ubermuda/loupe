#!/usr/bin/env php
<?php

declare(strict_types=1);

// Summarises a PHPUnit JUnit XML log: how many tests ran, how long they took,
// the mean cost of a test, and which classes and tests are the slowest.
// Usage: php bin/phpunit-timing.php <junit.xml> [--top=N]

function clock(float $seconds): string
{
    if ($seconds < 60) {
        return sprintf('%.3fs', $seconds);
    }

    return sprintf('%dm%05.2fs', (int) ($seconds / 60), fmod($seconds, 60));
}

$path = null;
$top = 15;

foreach (\array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--top=')) {
        $top = max(1, (int) substr($argument, 6));
        continue;
    }

    if (null === $path && !str_starts_with($argument, '--')) {
        $path = $argument;
        continue;
    }

    fwrite(\STDERR, sprintf("Unknown argument: %s\n", $argument));
    exit(2);
}

if (null === $path) {
    fwrite(\STDERR, "Usage: php bin/phpunit-timing.php <junit.xml> [--top=N]\n");
    exit(2);
}

$xml = file_get_contents($path);

if (false === $xml) {
    fwrite(\STDERR, sprintf("Cannot read %s\n", $path));
    exit(1);
}

// PHPUnit opens the log file when the run starts and writes it when the run
// ends, so a process killed in between leaves an empty file behind.
if ('' === trim($xml)) {
    fwrite(\STDERR, sprintf("%s is empty. PHPUnit died before it wrote the log.\n", $path));
    exit(1);
}

$document = new DOMDocument();

// A run killed mid-write leaves truncated XML, and libxml would otherwise print
// its own warnings and carry on with a partial tree.
if (!$document->loadXML($xml, \LIBXML_NOERROR | \LIBXML_NOWARNING)) {
    fwrite(\STDERR, sprintf("%s is not well-formed XML.\n", $path));
    exit(1);
}

$cases = (new DOMXPath($document))->query('//testcase');

if (false === $cases || 0 === $cases->length) {
    fwrite(\STDERR, sprintf("%s holds no <testcase> element.\n", $path));
    exit(1);
}

/** @var array<string, array{tests: int, time: float}> $classes */
$classes = [];
/** @var list<array{name: string, time: float}> $tests */
$tests = [];
$total = 0.0;

foreach ($cases as $case) {
    if (!$case instanceof DOMElement) {
        continue;
    }

    // A test outside a class, such as a bootstrap error, carries neither
    // attribute. It still costs time, so it is counted rather than dropped.
    $class = $case->getAttribute('class') ?: ($case->getAttribute('classname') ?: '(no class)');
    $time = (float) $case->getAttribute('time');

    $classes[$class] ??= ['tests' => 0, 'time' => 0.0];
    ++$classes[$class]['tests'];
    $classes[$class]['time'] += $time;

    $tests[] = ['name' => $class.'::'.$case->getAttribute('name'), 'time' => $time];
    $total += $time;
}

$count = \count($tests);

uasort($classes, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);
usort($tests, static fn (array $a, array $b): int => $b['time'] <=> $a['time']);

printf(
    "%d tests in %d classes, total %s, mean %.4fs per test\n\n",
    $count,
    \count($classes),
    clock($total),
    $total / $count,
);

printf("slowest %d classes\n", min($top, \count($classes)));
printf("%9s %6s %9s  %s\n", 'time', 'tests', 'mean', 'class');

foreach (\array_slice($classes, 0, $top, true) as $name => $class) {
    printf(
        "%9s %6d %8.4fs  %s\n",
        clock($class['time']),
        $class['tests'],
        $class['time'] / $class['tests'],
        $name,
    );
}

printf("\nslowest %d tests\n", min($top, $count));
printf("%9s  %s\n", 'time', 'test');

foreach (\array_slice($tests, 0, $top) as $test) {
    printf("%9s  %s\n", clock($test['time']), $test['name']);
}

echo "\nThe total sums the reported test times. Bootstrap, schema creation and\n";
echo "process start-up sit outside it, so the wall clock of the job is longer.\n";
