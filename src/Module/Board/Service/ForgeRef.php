<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;

/** What a parser could read out of a pull request URL. */
final readonly class ForgeRef
{
    public function __construct(
        public Forge $forge,
        public ?string $repository = null,
        public ?int $number = null,
    ) {
    }

    /** The answer for a URL no parser recognises, which is a legitimate answer. */
    public static function unknown(): self
    {
        return new self(Forge::Other);
    }

    /**
     * Whether the parts a parser read fit the columns that hold them.
     *
     * A URL short enough to store can still carry an owner and repository pair
     * longer than its column, or a number past what a 32-bit integer holds.
     */
    public function isStorable(): bool
    {
        if (null !== $this->repository && mb_strlen($this->repository) > CardPullRequest::MAX_REPOSITORY_LENGTH) {
            return false;
        }

        return null === $this->number || $this->number <= CardPullRequest::MAX_NUMBER;
    }
}
