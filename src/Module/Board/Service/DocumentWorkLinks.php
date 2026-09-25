<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Service\DocumentWorkLinksInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DocumentWorkLinksInterface::class)]
final readonly class DocumentWorkLinks implements DocumentWorkLinksInterface
{
    public function __construct(
        private CardRepository $cards,
        private CardDocumentRepository $cardDocuments,
        private BoardAvailability $board,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
    ) {
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return $this->board->isEnabled();
    }

    #[\Override]
    public function choices(Project $project, ?Document $document): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $selected = null === $document ? [] : $this->selectedIds($document);
        $choices = [];
        foreach ($this->cards->findBy(['project' => $project], ['number' => 'DESC']) as $card) {
            $id = (string) $card->id;
            if (!$card->column->terminal || \in_array($id, $selected, true)) {
                $choices[\sprintf('#%d %s', $card->number, $card->title)] = $id;
            }
        }

        return $choices;
    }

    #[\Override]
    public function selectedIds(Document $document): array
    {
        return array_map(static fn (CardDocument $link): string => (string) $link->card->id, $this->cardDocuments->findForDocument($document));
    }

    #[\Override]
    public function validate(Document $document, array $ids): void
    {
        $this->resolve($document, $ids);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, Card>
     */
    private function resolve(Document $document, array $ids): array
    {
        if (!$this->isEnabled()) {
            throw new DomainErrors(['workLinkIds' => 'review.work_links.error.unavailable']);
        }

        $existing = null === $document->id ? [] : $this->cardDocuments->findForDocument($document);
        $existingIds = array_map(static fn (CardDocument $link): string => (string) $link->card->id, $existing);
        $wanted = [];
        foreach (array_unique($ids) as $id) {
            $card = $this->cards->findOneByIdAndProjectId($id, (string) $document->project->id);
            if (null === $card) {
                throw new DomainErrors(['workLinkIds' => 'review.work_links.error.unknown']);
            }
            if (!$this->cards->isInOpenColumn($card) && !\in_array($id, $existingIds, true)) {
                throw new DomainErrors(['workLinkIds' => 'review.work_links.error.unknown']);
            }
            $wanted[$id] = $card;
        }

        return $wanted;
    }

    #[\Override]
    public function synchronize(Document $document, array $ids): void
    {
        $wanted = $this->resolve($document, $ids);
        $existing = null === $document->id ? [] : $this->cardDocuments->findForDocument($document);

        foreach ($existing as $link) {
            $id = (string) $link->card->id;
            if (isset($wanted[$id])) {
                unset($wanted[$id]);
            } else {
                $link->card->documents->removeElement($link);
                $link->card->updatedAt = new \DateTimeImmutable();
                $this->em->remove($link);
                $this->cardChanged($link->card);
            }
        }
        foreach ($wanted as $card) {
            $link = new CardDocument($card, $document);
            $card->documents->add($link);
            $card->updatedAt = new \DateTimeImmutable();
            $this->em->persist($link);
            $this->cardChanged($card);
        }
    }

    private function cardChanged(Card $card): void
    {
        $this->events->dispatch(new CardChanged(
            $card->project->id ?? throw new \LogicException('Project has no id.'),
            $card->id ?? throw new \LogicException('Card has no id.'),
            CardChanged::UPDATED,
            false,
        ));
    }
}
