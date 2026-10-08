<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use Symfony\Component\Uid\Uuid;

/** Where a card came from. A run source also names the worker run and the card that run worked on. */
final readonly class CardSource
{
    public function __construct(
        public CardSourceKind $kind,
        public ?Uuid $runId = null,
        public ?Uuid $runCardId = null,
    ) {
    }

    public static function fromReporter(CardReporter $reporter): self
    {
        return new self(match ($reporter) {
            CardReporter::Human => CardSourceKind::Person,
            CardReporter::Agent => CardSourceKind::Agent,
            CardReporter::Reviewer => CardSourceKind::Widget,
            CardReporter::System => CardSourceKind::Loupe,
        });
    }

    public static function run(Uuid $runId, ?Uuid $runCardId): self
    {
        return new self(CardSourceKind::Run, $runId, $runCardId);
    }
}
