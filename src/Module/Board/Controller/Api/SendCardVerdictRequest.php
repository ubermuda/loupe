<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Module\Board\Entity\CardVerdictKind;
use Symfony\Component\Validator\Constraints as Assert;

/** A verdict sent from the site-review widget. */
final class SendCardVerdictRequest
{
    public const int MAX_MESSAGE_LENGTH = 10000;

    public const int MAX_PULL_REQUESTS = 20;

    /**
     * @param list<string> $pullRequestIds
     */
    public function __construct(
        #[Assert\NotNull]
        public ?CardVerdictKind $kind = null,

        #[Assert\All([new Assert\Uuid()])]
        #[Assert\Count(max: self::MAX_PULL_REQUESTS)]
        public array $pullRequestIds = [],

        #[Assert\Length(max: self::MAX_MESSAGE_LENGTH)]
        public string $message = '',
    ) {
    }
}
