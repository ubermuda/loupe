<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Command;

use App\Module\DesignSystem\Catalog;
use App\Module\DesignSystem\Token\TokenReader;

final readonly class ShowStyleguideHandler
{
    public function __construct(
        private TokenReader $tokens,
        private Catalog $catalog,
    ) {
    }

    public function __invoke(ShowStyleguideCommand $command): StyleguideDetailView
    {
        return new StyleguideDetailView($this->tokens->groups(), $this->catalog->entries());
    }
}
