<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

/** The state of one card on the board: the winning reason, and every other reason that applies. */
final readonly class CardState
{
    public CardStateKind $kind;

    /** @param list<CardStateReason> $others the reasons that lost, in order of precedence */
    private function __construct(
        public CardStateReason $reason,
        public array $others,
    ) {
        $this->kind = $reason->code->kind();
    }

    /** @param non-empty-list<CardStateReason> $reasons */
    public static function of(array $reasons): self
    {
        usort($reasons, static fn (CardStateReason $a, CardStateReason $b): int => $a->code->rank() <=> $b->code->rank());

        return new self($reasons[0], \array_slice($reasons, 1));
    }

    /** A short value for the tile digest: the winner with the hash of its parameters, its time and the other codes. */
    public function digest(): string
    {
        $params = $this->reason->params;
        ksort($params);

        return implode('|', [
            $this->reason->code->value.([] === $params ? '' : ':'.hash('xxh3', json_encode($params, \JSON_THROW_ON_ERROR))),
            $this->reason->since?->format('U'),
            ...array_map(static fn (CardStateReason $reason): string => $reason->code->value, $this->others),
        ]);
    }
}
