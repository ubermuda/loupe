<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Command\ReleaseCardPauseCommand;
use App\Module\Board\Command\ReleaseCardPauseHandler;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\PauseView;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\Uid\Uuid;

/** Lazy, because the pause handlers reach the auditor and a Twig extension builds this port at boot. */
#[AsAlias(CardPauses::class)]
#[Lazy(CardPauses::class)]
final readonly class BoardCardPauses implements CardPauses
{
    public function __construct(
        private CardRepository $cards,
        private CardPauseRepository $cardPauses,
        private CardEventRepository $cardEvents,
        private PauseCardHandler $pauseCard,
        private ReleaseCardPauseHandler $releaseCardPause,
        private EntityManagerInterface $em,
    ) {
    }

    public static function view(CardPause $pause): PauseView
    {
        return new PauseView(
            $pause->id ?? throw new \LogicException('A stored pause has an id.'),
            $pause->project->id ?? throw new \LogicException('A stored project has an id.'),
            $pause->card->id ?? throw new \LogicException('A stored card has an id.'),
            $pause->reason,
            $pause->ruleId,
            $pause->kind,
            $pause->createdAt,
            $pause->releasedAt,
            $pause->releaseReason,
        );
    }

    #[\Override]
    public function findActive(Uuid $cardId): ?PauseView
    {
        $card = $this->cards->find($cardId);

        return null === $card ? null : $this->viewOrNull($this->cardPauses->findActiveForCard($card));
    }

    #[\Override]
    public function findLatest(Uuid $cardId): ?PauseView
    {
        $card = $this->cards->find($cardId);

        return null === $card ? null : $this->viewOrNull($this->cardPauses->findLatestForCard($card));
    }

    #[\Override]
    public function pause(CardSnapshot $card, string $reason, string $ruleId, PauseKind $kind): ?PauseView
    {
        $entity = $this->cards->find($card->id) ?? throw new \LogicException('A paused card is stored.');

        return $this->viewOrNull(($this->pauseCard)(new PauseCardCommand($entity, $reason, $ruleId, $kind)));
    }

    #[\Override]
    public function release(PauseView $pause, string $reason): ?PauseView
    {
        $entity = $this->em->find(CardPause::class, $pause->id) ?? throw new \LogicException('A released pause is stored.');
        if (!($this->releaseCardPause)(new ReleaseCardPauseCommand($entity, $reason))) {
            return null;
        }

        return self::view($entity);
    }

    #[\Override]
    public function recordReleased(PauseView $pause, Actor $actor, ?Uuid $actorUserId): void
    {
        $card = $this->cards->find($pause->cardId) ?? throw new \LogicException('A paused card is stored.');
        $this->cardEvents->record(
            $card,
            CardEventKind::PauseReleased,
            $actor,
            null === $actorUserId ? null : $this->em->getReference(User::class, $actorUserId),
            ['kind' => $pause->kind->value, 'reason' => $pause->reason, 'ruleId' => $pause->ruleId],
            $pause->releasedAt,
        );
    }

    private function viewOrNull(?CardPause $pause): ?PauseView
    {
        return null === $pause ? null : self::view($pause);
    }
}
