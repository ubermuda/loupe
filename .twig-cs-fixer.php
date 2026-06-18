<?php

declare(strict_types=1);

use Gamache\TwigCsFixer\GamacheStandard;
use TwigCsFixer\Config\Config;
use TwigCsFixer\File\Finder;
use TwigCsFixer\Ruleset\Ruleset;
use TwigCsFixer\Standard\Symfony;

$finder = Finder::create()
    ->in(__DIR__.'/templates');

$ruleset = new Ruleset();
$ruleset->addStandard(new Symfony());
$ruleset->addStandard(new GamacheStandard());

return (new Config())
    ->setFinder($finder)
    ->setRuleset($ruleset);
