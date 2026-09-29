<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\WorkerRun;
use Symfony\Component\Validator\Constraints as Assert;

/** One worker pool of a bridge, and how many workers it runs and holds back. */
final class BridgeWorkerPoolInput
{
    public const int MAX_COUNT = 1000;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: WorkerRun::WORKER_POOL_PATTERN)]
        public ?string $name = null,

        #[Assert\NotNull]
        #[Assert\Range(min: 0, max: self::MAX_COUNT)]
        #[Assert\Type('int')]
        public ?int $size = null,

        #[Assert\NotNull]
        #[Assert\Range(min: 0, max: self::MAX_COUNT)]
        #[Assert\Type('int')]
        public ?int $inUse = null,

        #[Assert\NotNull]
        #[Assert\Range(min: 0, max: self::MAX_COUNT)]
        #[Assert\Type('int')]
        public ?int $queued = null,
    ) {
    }
}
