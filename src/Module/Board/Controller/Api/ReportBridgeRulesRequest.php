<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** One bridge's whole rule health report for one project. */
final class ReportBridgeRulesRequest
{
    /** @param list<BridgeRuleInput>|null $rules */
    public function __construct(
        #[Assert\Count(max: 200)]
        #[Assert\NotNull]
        #[Assert\Valid]
        public ?array $rules = null,
    ) {
    }
}
