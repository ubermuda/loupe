<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use Symfony\Component\Uid\Uuid;

final readonly class DeliverPullRequestNoticeCommand
{
    public function __construct(
        public Uuid $noticeId,
    ) {
    }
}
