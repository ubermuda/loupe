<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command\Dev;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When('dev')]
#[When('test')]
final readonly class MintAccessTokenCommand
{
    /** @param non-empty-list<string> $scopes base scopes, such as 'agent' or 'mcp' */
    public function __construct(
        public User $owner,
        public array $scopes,
        public ?Project $project = null,
    ) {
    }
}
