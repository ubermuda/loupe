<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Workflow\Contract\Actor;
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

    public static function fromReporter(Actor $reporter): self
    {
        return new self(match ($reporter) {
            Actor::Human => CardSourceKind::Person,
            Actor::Agent => CardSourceKind::Agent,
            Actor::Reviewer => CardSourceKind::Widget,
            Actor::System => CardSourceKind::Loupe,
        });
    }

    public static function run(Uuid $runId, ?Uuid $runCardId): self
    {
        return new self(CardSourceKind::Run, $runId, $runCardId);
    }
}
