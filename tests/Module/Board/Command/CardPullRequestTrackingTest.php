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
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\PullRequestTracking;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestStateReader;
use App\Module\Forge\Service\PullRequestStateReaders;
use App\Module\Forge\Service\PullRequestTracker;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

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

        // A reader for every forge, so only the board decides which links are tracked.
        $reader = $this->createStub(PullRequestStateReader::class);
        $reader->method('supports')->willReturn(true);
        $container->set(PullRequestTracking::class, new PullRequestTracking(
            $links,
            new PullRequestTracker($forgePullRequests, new PullRequestStateReaders([$reader]), $bus, $clock),
        ));

        $owner = new User(fullName: 'Riley', email: 'board-tracking-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
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
