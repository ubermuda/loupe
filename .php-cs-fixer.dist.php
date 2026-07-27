<?php

use Gamache\PhpCsFixer\Fixers;

require_once __DIR__.'/vendor/autoload.php';

// Excludes are explicit rather than ignoreVCSIgnored(true): under a worktree in
// the gitignored .claude/worktrees/, that flag matched zero files, so `just cs`
// fixed nothing and `just ci`'s cs leg passed vacuously. Keep `.claude` excluded
// — worktrees live inside the main checkout, so the main run would otherwise
// scan every worktree's copy of the tree.
$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['vendor', 'var', 'node_modules', '.claude'])
;

// A style gate that inspects nothing is indistinguishable from a passing one.
if (0 === iterator_count($finder->getIterator())) {
    throw new RuntimeException(sprintf('php-cs-fixer matched 0 files under %s; refusing to report success.', __DIR__));
}

return (new PhpCsFixer\Config())
    ->registerCustomFixers(new Fixers())
    ->setRules([
        '@Symfony' => true,
        ...Fixers::rules(),
    ])
    ->setFinder($finder)
;
