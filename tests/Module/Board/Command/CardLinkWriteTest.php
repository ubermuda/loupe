<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardLinkWriteTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private UpdateCardHandler $updateCard;
    private CardLinkRepository $links;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        // Before the handlers are fetched: the container hands the replacement
        // only to what it builds afterwards.
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $this->createCard = $create;

        $update = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $update);
        $this->updateCard = $update;

        $links = self::getContainer()->get(CardLinkRepository::class);
        self::assertInstanceOf(CardLinkRepository::class, $links);
        $this->links = $links;
    }

    public function test_either_end_governs_the_one_row_of_a_pair(): void
    {
        $project = $this->makeProject('write-ends');
        [$a, $b] = [$this->cardIn($project), $this->cardIn($project)];

        // On B: blocked by A, stored as A blocks B.
        $this->update($b, [new CardLinkInput((string) $a->id, CardLinkKind::BlockedBy)]);
        self::assertSame(1, $this->links->count([]));
        $link = $this->links->findForCard($this->reload($a))[0];
        self::assertSame((string) $a->id, (string) $link->source->id);
        self::assertSame(CardLinkKind::Blocks, $link->kind);
        $id = $link->id;
        $this->em->clear();

        // On A: relates to B. The same row changes in place.
        $this->update($a, [new CardLinkInput((string) $b->id)]);
        self::assertSame(1, $this->links->count([]));
        $link = $this->links->findForCard($this->reload($b))[0];
        self::assertTrue($id?->equals($link->id));
        self::assertSame(CardLinkKind::RelatesTo, $link->kind);
        $this->em->clear();

        // On B: an empty set removes the link A wrote.
        $this->update($b, []);
        self::assertSame(0, $this->links->count([]));
    }

    public function test_an_omitted_set_keeps_the_links(): void
    {
        $project = $this->makeProject('write-omitted');
        [$a, $b] = [$this->cardIn($project), $this->cardIn($project)];
        $this->update($a, [new CardLinkInput((string) $b->id, CardLinkKind::Blocks)]);
        $this->em->clear();

        $this->update($a, null, 'Renamed, links untouched');

        self::assertSame(1, $this->links->count([]));
        self::assertSame(CardLinkKind::Blocks, $this->links->findForCard($this->reload($a))[0]->kindFor($this->reload($a)));
    }

    public function test_a_refused_set_leaves_the_links_as_they_were(): void
    {
        $project = $this->makeProject('write-refused');
        [$a, $b] = [$this->cardIn($project), $this->cardIn($project)];
        $theirs = $this->cardIn($this->makeProject('write-refused-theirs'));
        $this->update($a, [new CardLinkInput((string) $b->id)]);
        $this->em->clear();

        $refusals = [
            'board.card.error.linked_card_self' => [new CardLinkInput((string) $a->id)],
            'board.card.error.linked_card_unknown' => [new CardLinkInput((string) $theirs->id)],
            'board.card.error.linked_card_twice' => [new CardLinkInput((string) $b->id), new CardLinkInput((string) $b->id, CardLinkKind::Blocks)],
        ];
        foreach ($refusals as $key => $inputs) {
            try {
                $this->update($a, $inputs);
                self::fail(\sprintf('Expected the refusal %s.', $key));
            } catch (DomainErrors $e) {
                self::assertSame(['relatedCards' => $key], $e->errors);
            }

            // The refusal came before the transaction, so the manager is still open.
            self::assertTrue($this->em->isOpen());
            self::assertSame(1, $this->links->count([]));
            self::assertSame(CardLinkKind::RelatesTo, $this->links->findForCard($this->reload($a))[0]->kindFor($this->reload($a)));
        }
    }

    public function test_a_submitted_set_is_recorded_and_the_other_card_keeps_its_update_time(): void
    {
        $project = $this->makeProject('write-audit');
        [$a, $b] = [$this->cardIn($project), $this->cardIn($project)];
        $this->em->clear();
        $bUpdatedAt = $this->reload($b)->updatedAt;
        $this->audit->forget();

        $this->update($a, [new CardLinkInput((string) $b->id)]);
        $this->em->clear();

        $context = $this->audit->record('board.card_updated')->context;
        self::assertTrue($context['relatedCardsReplaced']);
        self::assertFalse($context['titleChanged']);
        self::assertEquals($bUpdatedAt, $this->reload($b)->updatedAt);
    }

    public function test_a_created_card_carries_its_links(): void
    {
        $project = $this->makeProject('write-create');
        [$a, $b] = [$this->cardIn($project), $this->cardIn($project)];
        $this->audit->forget();

        $card = ($this->createCard)(new CreateCardCommand(
            $project,
            'Linked from the start',
            'Body',
            CardType::Feature,
            relatedCards: [new CardLinkInput((string) $a->id, CardLinkKind::BlockedBy), new CardLinkInput((string) $b->id)],
        ));
        $this->em->clear();

        self::assertSame(2, $this->links->count([]));
        $reloaded = $this->reload($card);
        $kinds = [];
        foreach ($this->links->findForCard($reloaded) as $link) {
            $kinds[(string) $link->otherThan($reloaded)->id] = $link->kindFor($reloaded);
        }
        self::assertSame([(string) $a->id => CardLinkKind::BlockedBy, (string) $b->id => CardLinkKind::RelatesTo], $kinds);

        self::assertSame(2, $this->audit->record('board.card_created')->context['relatedCardCount']);
    }

    /** @param list<CardLinkInput>|null $relatedCards */
    private function update(Card $card, ?array $relatedCards, ?string $title = null): void
    {
        ($this->updateCard)(new UpdateCardCommand($this->reload($card), CardReporter::Agent, title: $title, relatedCards: $relatedCards));
    }

    private function cardIn(Project $project): Card
    {
        return ($this->createCard)(new CreateCardCommand($project, 'Ship it', 'Body', CardType::Feature));
    }

    private function reload(Card $card): Card
    {
        return $this->em->find(Card::class, $card->id) ?? throw new \LogicException('The card must exist.');
    }
}
