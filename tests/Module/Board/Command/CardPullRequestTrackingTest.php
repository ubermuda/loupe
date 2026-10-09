<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\SiteReviewCheckState;
use App\Module\Board\Messenger\NeutralizeSiteReviewCheck;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\SiteReviewCheckStateRepository;
use App\Module\Board\Service\PullRequestTracking;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestStateReader;
use App\Module\Forge\Service\PullRequestStateReaders;
use App\Module\Forge\Service\PullRequestTracker;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CardPullRequestTrackingTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private const string PULL_REQUEST = 'https://github.com/Acme/Widgets/pull/42';

    private EntityManagerInterface $em;
    private ForgePullRequestRepository $forgePullRequests;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $forgePullRequests = $container->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $forgePullRequests);
        $this->forgePullRequests = $forgePullRequests;

        $links = $container->get(CardPullRequestRepository::class);
        self::assertInstanceOf(CardPullRequestRepository::class, $links);
        $bus = $container->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $checkStates = $container->get(SiteReviewCheckStateRepository::class);
        self::assertInstanceOf(SiteReviewCheckStateRepository::class, $checkStates);

        // A reader for every forge, so only the board decides which links are tracked.
        $reader = $this->createStub(PullRequestStateReader::class);
        $reader->method('supports')->willReturn(true);
        $container->set(PullRequestTracking::class, new PullRequestTracking(
            $links,
            new PullRequestTracker($forgePullRequests, new PullRequestStateReaders([$reader]), $bus, $clock),
            $checkStates,
            $bus,
        ));

        $owner = new User(fullName: 'Riley', email: 'board-tracking-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
        $this->transport()->reset();
    }

    public function test_a_new_card_tracks_its_github_pull_request(): void
    {
        $this->create([self::PULL_REQUEST, 'https://github.com/acme/widgets/pull/42/files']);

        self::assertSame([['acme/widgets', 42]], $this->tracked());
    }

    public function test_a_link_on_another_forge_is_not_tracked(): void
    {
        $this->create(['https://gitlab.com/acme/widgets/-/merge_requests/7', 'https://example.com/pull/1']);

        self::assertSame([], $this->tracked());
    }

    public function test_an_update_tracks_an_added_link_and_untracks_a_removed_one(): void
    {
        $card = $this->create([self::PULL_REQUEST]);

        $this->update($card, ['https://github.com/acme/widgets/pull/43']);

        self::assertSame([['acme/widgets', 43]], $this->tracked());
    }

    public function test_an_update_without_links_leaves_the_tracking_alone(): void
    {
        $card = $this->create([self::PULL_REQUEST]);

        ($this->handler(UpdateCardHandler::class))(new UpdateCardCommand(card: $card, actor: Actor::Agent, title: 'Renamed'));

        self::assertSame([['acme/widgets', 42]], $this->tracked());
    }

    public function test_a_pull_request_another_card_still_links_stays_tracked(): void
    {
        $first = $this->create([self::PULL_REQUEST]);
        $second = $this->create(['https://github.com/acme/widgets/pull/42']);

        $this->update($first, []);
        self::assertSame([['acme/widgets', 42]], $this->tracked());

        $this->update($second, []);
        self::assertSame([], $this->tracked());
    }

    public function test_deleting_a_card_untracks_its_pull_request(): void
    {
        $card = $this->create([self::PULL_REQUEST]);

        ($this->handler(DeleteCardHandler::class))(new DeleteCardCommand($card, Actor::Human));

        self::assertSame([], $this->tracked());
    }

    public function test_deleting_one_of_two_linking_cards_keeps_the_pull_request_tracked(): void
    {
        $card = $this->create([self::PULL_REQUEST]);
        $this->create([self::PULL_REQUEST]);

        ($this->handler(DeleteCardHandler::class))(new DeleteCardCommand($card, Actor::Human));

        self::assertSame([['acme/widgets', 42]], $this->tracked());
    }

    public function test_dropping_a_shared_link_evaluates_the_other_card_and_the_card_itself(): void
    {
        $first = $this->create([self::PULL_REQUEST]);
        $second = $this->create([self::PULL_REQUEST]);
        $this->transport()->reset();

        $this->update($first, []);

        self::assertEqualsCanonicalizing($this->ids($first, $second), $this->evaluated());
    }

    public function test_adding_a_shared_link_evaluates_the_other_card_and_the_card_itself(): void
    {
        $first = $this->create([self::PULL_REQUEST]);
        $second = $this->create([]);
        $this->transport()->reset();

        $this->update($second, [self::PULL_REQUEST]);

        self::assertEqualsCanonicalizing($this->ids($first, $second), $this->evaluated());
    }

    public function test_resubmitting_the_same_links_evaluates_nothing(): void
    {
        $first = $this->create([self::PULL_REQUEST]);
        $this->create([self::PULL_REQUEST]);
        $this->transport()->reset();

        $this->update($first, [self::PULL_REQUEST]);

        self::assertSame([], $this->evaluated());
    }

    public function test_deleting_a_card_evaluates_the_other_cards_on_its_pull_request(): void
    {
        $first = $this->create([self::PULL_REQUEST]);
        $second = $this->create([self::PULL_REQUEST]);
        $this->transport()->reset();

        ($this->handler(DeleteCardHandler::class))(new DeleteCardCommand($first, Actor::Human));

        self::assertContains($this->ids($second)[0], $this->evaluated());
    }

    public function test_dropping_the_last_link_queues_a_neutral_write_for_a_failed_check(): void
    {
        $card = $this->create([self::PULL_REQUEST]);
        $row = $this->forgePullRequests->findBy(['project' => $this->project])[0];
        $this->em->persist(new SiteReviewCheckState($row, 'sha-1', 'failure', 1, 77));
        $this->em->flush();
        $this->transport()->reset();

        ($this->handler(DeleteCardHandler::class))(new DeleteCardCommand($card, Actor::Human));

        $neutralized = array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $this->transport()->getSent()),
            static fn (object $message): bool => $message instanceof NeutralizeSiteReviewCheck,
        ));
        self::assertCount(1, $neutralized);
        self::assertSame(['github', 'acme/widgets', 42, 'sha-1', 77], [$neutralized[0]->forge, $neutralized[0]->repository, $neutralized[0]->number, $neutralized[0]->headSha, $neutralized[0]->runId]);
    }

    public function test_dropping_the_last_link_of_a_passing_check_queues_no_neutral_write(): void
    {
        $card = $this->create([self::PULL_REQUEST]);
        $row = $this->forgePullRequests->findBy(['project' => $this->project])[0];
        $this->em->persist(new SiteReviewCheckState($row, 'sha-1', 'success', 0, 77));
        $this->em->flush();
        $this->transport()->reset();

        $this->update($card, []);

        self::assertSame([], array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $this->transport()->getSent()),
            static fn (object $message): bool => $message instanceof NeutralizeSiteReviewCheck,
        ));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /** @return list<string> the card ids of the queued evaluations */
    private function evaluated(): array
    {
        $ids = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof EvaluateCard) {
                $ids[] = $message->cardId;
            }
        }

        return $ids;
    }

    /** @return list<string> */
    private function ids(Card ...$cards): array
    {
        return array_values(array_map(static fn (Card $card): string => ($card->id ?? throw new \LogicException('A flushed card has an id.'))->toRfc4122(), $cards));
    }

    /** @return list<array{string, int}> */
    private function tracked(): array
    {
        $rows = [];
        foreach ($this->forgePullRequests->findBy(['project' => $this->project], ['number' => 'ASC']) as $row) {
            self::assertSame('github', $row->forge);
            $rows[] = [$row->repository, $row->number];
        }

        return $rows;
    }

    /** @param list<string> $pullRequestUrls */
    private function create(array $pullRequestUrls): Card
    {
        return ($this->handler(CreateCardHandler::class))(new CreateCardCommand(
            project: $this->project,
            title: 'A card',
            body: 'Body',
            type: 'feature',
            pullRequestUrls: $pullRequestUrls,
        ));
    }

    /** @param list<string> $pullRequestUrls */
    private function update(Card $card, array $pullRequestUrls): void
    {
        ($this->handler(UpdateCardHandler::class))(new UpdateCardCommand(card: $card, actor: Actor::Agent, pullRequestUrls: $pullRequestUrls));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function handler(string $class): object
    {
        $handler = self::getContainer()->get($class);
        self::assertInstanceOf($class, $handler);

        return $handler;
    }
}
