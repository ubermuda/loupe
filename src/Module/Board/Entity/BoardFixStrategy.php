<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

enum BoardFixStrategy: string
{
    case Fresh = 'fresh';
    case Resume = 'resume';
}
