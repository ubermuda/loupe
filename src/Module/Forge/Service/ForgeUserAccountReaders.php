<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ForgeUserAccountReaders
{
    /** @param iterable<ForgeUserAccountReader> $readers */
    public function __construct(
        #[AutowireIterator('app.forge_user_account_reader')]
        private iterable $readers,
    ) {
    }

    public function for(string $forge): ?ForgeUserAccountReader
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($forge)) {
                return $reader;
            }
        }

        return null;
    }
}
