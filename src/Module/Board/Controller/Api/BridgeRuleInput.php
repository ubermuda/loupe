<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Module\Board\Entity\BridgeRuleReport;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One rule of a bridge's health report. It holds no prompt: the payload has no
 * field for one, so a prompt the bridge sends is dropped before validation.
 */
final class BridgeRuleInput
{
    public const string SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** @param list<mixed>|null $columns */
    public function __construct(
        #[Assert\Length(max: 100)]
        #[Assert\NotBlank]
        public ?string $name = null,

        #[Assert\Length(max: 100)]
        #[Assert\NotBlank]
        #[Assert\Regex('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/')]
        public ?string $on = null,

        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 200), new Assert\Regex(self::SLUG_PATTERN)])]
        #[Assert\Count(max: 50)]
        #[Assert\NotNull]
        public ?array $columns = null,

        #[Assert\Choice(choices: [BridgeRuleReport::STATE_LIVE, BridgeRuleReport::STATE_DEAD])]
        #[Assert\NotBlank]
        public ?string $state = null,

        #[Assert\Length(max: 64)]
        #[Assert\Regex('/^[a-z][a-z0-9_]*$/')]
        public ?string $reason = null,
    ) {
    }

    /** A dead rule says why, and a live rule has nothing to say. */
    #[Assert\Callback]
    public function validateReason(ExecutionContextInterface $context): void
    {
        if (BridgeRuleReport::STATE_DEAD === $this->state && null === $this->reason) {
            $context->buildViolation('A dead rule needs a reason.')->atPath('reason')->addViolation();
        }

        if (BridgeRuleReport::STATE_LIVE === $this->state && null !== $this->reason) {
            $context->buildViolation('A live rule has no reason.')->atPath('reason')->addViolation();
        }
    }

    /** @return array{name: string, on: string, columns: list<string>, state: string, reason: ?string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name ?? '',
            'on' => $this->on ?? '',
            'columns' => array_values(array_filter($this->columns ?? [], \is_string(...))),
            'state' => $this->state ?? '',
            'reason' => $this->reason,
        ];
    }
}
