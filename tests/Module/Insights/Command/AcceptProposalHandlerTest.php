<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Messenger\RecomputeBucketTimes;
use App\Module\Insights\Command\AcceptProposalCommand;
use App\Module\Insights\Command\AcceptProposalHandler;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Entity\ProposalState;
use App\Module\Insights\Proposal\ProposalCardCreatorInterface;
use App\Module\Insights\Repository\InsightsBucketRuleRepository;
use App\Module\Insights\Repository\ProposalRepository;
use App\Module\Insights\Service\BucketRuleWriter;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Insights\InsightsScenario;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Ubermuda\AuditBundle\Auditor;

final class AcceptProposalHandlerTest extends KernelTestCase
{
    use BoardColumnFixtures;
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_an_accepted_proposal_becomes_a_backlog_card_that_links_the_report(): void
    {
        $em = $this->em();
        [$proposal, $documentId] = $this->reportedProposal($em, 'accept-proposal');

        $accepted = $this->handler()(new AcceptProposalCommand($proposal));

        $em->clear();
        $stored = $this->service(ProposalRepository::class)->find($proposal->id);
        self::assertInstanceOf(Proposal::class, $stored);
        self::assertSame(ProposalState::Created, $stored->state);
        self::assertNotNull($stored->cardId);
        self::assertSame((string) $accepted->cardId, (string) $stored->cardId);
        $card = $this->service(CardRepository::class)->find($stored->cardId);
        self::assertInstanceOf(Card::class, $card);
        self::assertSame('Cache the dependencies', $card->title);
        self::assertSame('Each run installs them again.', $card->body);
        self::assertSame(CardType::Feature, $card->type);
        self::assertSame('backlog', $card->column->slug);
        self::assertSame([$documentId], array_map(static fn (CardDocument $link): string => (string) $link->document->id, $card->documents->toArray()));
    }

    public function test_a_proposal_accepted_once_refuses_a_second_accept(): void
    {
        $em = $this->em();
        [$proposal] = $this->reportedProposal($em, 'accept-proposal-twice');
        $this->handler()(new AcceptProposalCommand($proposal));

        $this->assertRefused(['proposal' => AcceptProposalHandler::NOT_PROPOSED], $proposal);
        self::assertSame(1, $this->countCards($em, $proposal));
    }

    public function test_a_dismissed_proposal_refuses_an_accept(): void
    {
        $em = $this->em();
        [$proposal] = $this->reportedProposal($em, 'accept-proposal-dismissed');
        $proposal->state = ProposalState::Dismissed;
        $em->flush();

        $this->assertRefused(['proposal' => AcceptProposalHandler::NOT_PROPOSED], $proposal);
    }

    public function test_an_accepted_bucket_rule_becomes_the_last_rule_of_the_project_and_asks_for_a_recompute(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('accept-proposal-rule');
        $em->persist(new InsightsBucketRule($project, 'Bash:just *', 'just', 4));
        $proposal = $this->seedRuleProposal($em, $project, ['pattern' => ' Bash:git * ', 'bucket' => 'git']);
        $transport = $this->transport();
        $transport->reset();

        $accepted = $this->handler()(new AcceptProposalCommand($proposal));

        self::assertSame(ProposalState::Created, $accepted->state);
        self::assertNull($accepted->cardId);
        $em->clear();
        $rules = $this->service(InsightsBucketRuleRepository::class)->findOrdered($em->find($project::class, $project->id) ?? throw new \LogicException());
        self::assertSame([['Bash:just *', 'just', 4], ['Bash:git *', 'git', 5]], array_map(static fn (InsightsBucketRule $rule): array => [$rule->pattern, $rule->bucket, $rule->position], $rules));
        self::assertSame(0, $this->countCards($em, $proposal));
        self::assertEquals([new RecomputeBucketTimes((string) $project->id)], array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()));
    }

    /** @return iterable<string, array{array<mixed>|null}> */
    public static function invalidPayloads(): iterable
    {
        yield 'no payload' => [null];
        yield 'no bucket' => [['pattern' => 'Bash:git *']];
        yield 'a blank pattern' => [['pattern' => '  ', 'bucket' => 'git']];
        yield 'a pattern that is too long' => [['pattern' => str_repeat('a', 121), 'bucket' => 'git']];
        yield 'a bucket with capitals' => [['pattern' => 'Bash:git *', 'bucket' => 'Git']];
        yield 'a bucket that is not text' => [['pattern' => 'Bash:git *', 'bucket' => ['git']]];
    }

    /** @param array<mixed>|null $payload */
    #[DataProvider('invalidPayloads')]
    public function test_a_bucket_rule_with_an_invalid_payload_is_refused_and_stays_open(?array $payload): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('accept-proposal-rule-invalid');
        $proposal = $this->seedRuleProposal($em, $project, $payload);
        $transport = $this->transport();
        $transport->reset();

        $this->assertRefused(['proposal' => AcceptProposalHandler::BUCKET_RULE_INVALID], $proposal);

        self::assertTrue($em->isOpen());
        self::assertSame(ProposalState::Proposed, $proposal->state);
        self::assertSame(0, $this->ruleCount($em, $project));
        self::assertSame([], $transport->getSent());
    }

    public function test_a_bucket_rule_is_refused_when_the_project_is_at_the_limit(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('accept-proposal-rule-limit');
        for ($position = 0; $position < InsightsBucketRule::MAX_PER_PROJECT; ++$position) {
            $em->persist(new InsightsBucketRule($project, 'Bash:tool'.$position.' *', 'tool', $position));
        }
        $proposal = $this->seedRuleProposal($em, $project, ['pattern' => 'Bash:git *', 'bucket' => 'git']);
        $transport = $this->transport();
        $transport->reset();

        $this->assertRefused(['proposal' => AcceptProposalHandler::BUCKET_RULE_LIMIT], $proposal);

        self::assertSame(ProposalState::Proposed, $proposal->state);
        self::assertSame(InsightsBucketRule::MAX_PER_PROJECT, $this->ruleCount($em, $project));
        self::assertSame([], $transport->getSent());
    }

    public function test_a_bucket_rule_accepted_once_refuses_a_second_accept(): void
    {
        $em = $this->em();
        $project = $this->scenarioProject('accept-proposal-rule-twice');
        $proposal = $this->seedRuleProposal($em, $project, ['pattern' => 'Bash:git *', 'bucket' => 'git']);
        $this->handler()(new AcceptProposalCommand($proposal));

        $this->assertRefused(['proposal' => AcceptProposalHandler::NOT_PROPOSED], $proposal);

        self::assertSame(1, $this->ruleCount($em, $project));
    }

    public function test_a_card_the_board_refuses_puts_the_proposal_back(): void
    {
        $em = $this->em();
        [$proposal] = $this->reportedProposal($em, 'accept-proposal-board-refuses');
        $creator = $this->createStub(ProposalCardCreatorInterface::class);
        $creator->method('createBacklogCard')->willThrowException(new DomainErrors(['title' => 'board.card.error.title_blank']));
        $handler = new AcceptProposalHandler($this->service(ProposalRepository::class), $creator, $em, $this->service(BucketRuleWriter::class), $this->service(MessageBusInterface::class), $this->service(Auditor::class));

        try {
            $handler(new AcceptProposalCommand($proposal));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['title' => 'board.card.error.title_blank'], $e->errors);
        }

        self::assertTrue($em->isOpen());
        $em->clear();
        $stored = $this->service(ProposalRepository::class)->find($proposal->id);
        self::assertInstanceOf(Proposal::class, $stored);
        self::assertSame(ProposalState::Proposed, $stored->state);
        self::assertNull($stored->cardId);
    }

    public function test_a_failure_after_the_card_commits_rolls_back_the_card_and_the_claim(): void
    {
        $em = $this->em();
        [$proposal] = $this->reportedProposal($em, 'accept-proposal-after-commit');
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $hubDown = static function (): void {
            throw new \RuntimeException('The hub is down.');
        };
        $dispatcher->addListener(CardChanged::class, $hubDown);

        try {
            $this->handler()(new AcceptProposalCommand($proposal));
            self::fail('Expected the failure after the card commit.');
        } catch (\RuntimeException $e) {
            self::assertSame('The hub is down.', $e->getMessage());
        }

        self::assertSame(0, $this->countCards($em, $proposal));
        self::assertSame(
            [ProposalState::Proposed->value, null],
            array_values($em->getConnection()->fetchNumeric('SELECT state, card_id FROM insights_proposals WHERE id = ?', [(string) $proposal->id]) ?: []),
        );

        $dispatcher->removeListener(CardChanged::class, $hubDown);
        $this->service(ManagerRegistry::class)->resetManager();
        $retry = $this->service(ProposalRepository::class)->find($proposal->id);
        self::assertInstanceOf(Proposal::class, $retry);

        $accepted = $this->handler()(new AcceptProposalCommand($retry));

        self::assertSame(ProposalState::Created, $accepted->state);
        self::assertNotNull($accepted->cardId);
        self::assertInstanceOf(Card::class, $this->service(CardRepository::class)->find($accepted->cardId));
        self::assertSame(1, $this->countCards($em, $proposal));

        $this->assertRefused(['proposal' => AcceptProposalHandler::NOT_PROPOSED], $accepted);
        self::assertSame(1, $this->countCards($em, $proposal));
    }

    /** @param array<mixed>|null $payload */
    private function seedRuleProposal(EntityManagerInterface $em, Project $project, ?array $payload): Proposal
    {
        $em->flush();

        return $this->seedProposal($em, $this->seedAnalysis($em, $project, AnalysisState::Done), ProposalKind::BucketRule, 0, $payload);
    }

    private function ruleCount(EntityManagerInterface $em, Project $project): int
    {
        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM insights_bucket_rules WHERE project_id = ?', [(string) $project->id]);
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return array{Proposal, string} */
    private function reportedProposal(EntityManagerInterface $em, string $name): array
    {
        $project = $this->scenarioProject($name);
        $this->seedColumns($project);
        $em->flush();
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Done);
        $document = $this->seedDocument($em, $project);
        $analysis->documentId = $document->id;
        $em->flush();

        return [$this->seedProposal($em, $analysis), (string) $document->id];
    }

    private function countCards(EntityManagerInterface $em, Proposal $proposal): int
    {
        return (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM board_cards WHERE project_id = ?', [(string) $proposal->analysis->project->id]);
    }

    /** @param array<string, string> $errors */
    private function assertRefused(array $errors, Proposal $proposal): void
    {
        try {
            $this->handler()(new AcceptProposalCommand($proposal));
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame($errors, $e->errors);
        }
    }

    private function handler(): AcceptProposalHandler
    {
        return $this->service(AcceptProposalHandler::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
