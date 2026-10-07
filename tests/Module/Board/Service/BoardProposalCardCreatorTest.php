<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardRepository;
use App\Module\Insights\Proposal\ProposalCard;
use App\Module\Insights\Proposal\ProposalCardCommittedException;
use App\Module\Insights\Proposal\ProposalCardCreatorInterface;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class BoardProposalCardCreatorTest extends KernelTestCase
{
    use BoardColumnFixtures;
    use BridgeScenario;

    public function test_it_creates_a_feature_card_in_the_backlog_that_links_the_report(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'proposal-card-'.uniqid().'@example.com'), 'Proposal card');
        $this->seedColumns($project);
        $document = new Document($project->owner, $project, 'Cost report');
        $em->persist($document);
        $em->flush();

        $creator = self::getContainer()->get(ProposalCardCreatorInterface::class);
        self::assertInstanceOf(ProposalCardCreatorInterface::class, $creator);
        $cardId = $creator->createBacklogCard($project, new ProposalCard('Cache the dependencies', 'Each run installs them again.', $document->id));

        $em->clear();
        $card = self::getContainer()->get(CardRepository::class)->find($cardId);
        self::assertInstanceOf(Card::class, $card);
        self::assertSame('Cache the dependencies', $card->title);
        self::assertSame('Each run installs them again.', $card->body);
        self::assertSame(CardType::Feature, $card->type);
        self::assertSame(CardReporter::Agent, $card->origin);
        self::assertSame('backlog', $card->column->slug);
        self::assertSame([(string) $document->id], array_map(static fn (CardDocument $link): string => (string) $link->document->id, $card->documents->toArray()));
    }

    public function test_a_failure_after_the_commit_still_answers_the_card_id(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'proposal-card-'.uniqid().'@example.com'), 'Proposal card');
        $this->seedColumns($project);
        $em->flush();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CardChanged::class, static function (): void {
            throw new \RuntimeException('The hub is down.');
        });

        $creator = self::getContainer()->get(ProposalCardCreatorInterface::class);
        self::assertInstanceOf(ProposalCardCreatorInterface::class, $creator);
        try {
            $creator->createBacklogCard($project, new ProposalCard('Cache the dependencies', 'Each run installs them again.', null));
            self::fail('Expected the failure after the commit.');
        } catch (ProposalCardCommittedException $e) {
            $em->clear();
            self::assertInstanceOf(Card::class, self::getContainer()->get(CardRepository::class)->find($e->cardId));
        }
    }
}
