<?php

use Gamache\PhpCsFixer\Fixers;

require_once __DIR__.'/vendor/autoload.php';

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->ignoreVCSIgnored(true)
;

return (new PhpCsFixer\Config())
    ->registerCustomFixers(new Fixers())
    ->setRules([
        '@Symfony' => true,
        ...Fixers::rules(),
    ])
    ->setFinder($finder)
;
