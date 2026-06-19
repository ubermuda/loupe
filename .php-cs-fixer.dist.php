<?php

use Gamache\PhpCsFixer\Fixers;

require_once __DIR__.'/vendor/autoload.php';

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('vendor')
    ->exclude('node_modules')
;

return (new PhpCsFixer\Config())
    ->registerCustomFixers(new Fixers())
    ->setRules([
        '@Symfony' => true,
        ...Fixers::rules(),
    ])
    ->setFinder($finder)
;
