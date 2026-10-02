<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PullRequestBaseChangers
{
    /** @param iterable<PullRequestBaseChanger> $changers */
    public function __construct(
        #[AutowireIterator('app.pull_request_base_changer')]
        private iterable $changers,
    ) {
    }

    public function for(string $forge): ?PullRequestBaseChanger
    {
        foreach ($this->changers as $changer) {
            if ($changer->supports($forge)) {
                return $changer;
            }
        }

        return null;
    }
}
