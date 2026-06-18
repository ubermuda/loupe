<?php

declare(strict_types=1);

use Gamache\Rector\GamacheSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/public',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php85: true)
    ->withPreparedSets(symfonyCodeQuality: true, symfonyConfigs: true)
    ->withComposerBased(symfony: true)
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0)
    ->withSets([GamacheSetList::CONVENTIONS]);
