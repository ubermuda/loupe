<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

enum RuleOrigin: string
{
    case Template = 'template';
    case App = 'app';
}
