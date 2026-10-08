<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

enum AnalysisTopic: string
{
    case Cost = 'cost';
    case Time = 'time';
    case Experiment = 'experiment';
    case Host = 'host';
    case Question = 'question';
}
