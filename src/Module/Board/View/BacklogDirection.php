<?php

declare(strict_types=1);

namespace App\Module\Board\View;

enum BacklogDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    public function reversed(): self
    {
        return self::Asc === $this ? self::Desc : self::Asc;
    }
}
