<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardType;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Outbox\Entity\OutboxEvent;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ShowWorkshopControllerTest extends WebTestCase
{
    use BoardColumnFixtures;

    public function test_recent_activity_reads_only_the_latest_project_events(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'workshop-events@example.com');
        $project = new Project($owner, 'Event workshop');
        $foreign = new Project($owner, 'Other events');
        $em->persist($project);
        $em->persist($foreign);
        $events = [];
        for ($index = 1; $index <= 6; ++$index) {
            $event = new OutboxEvent($project, 'inbox.event_'.$index, 'urn:workshop', '{}', new \DateTimeImmutable('2026-01-0'.$index));
            $em->persist($event);
            $events[] = $event;
        }
        $em->persist(new OutboxEvent($foreign, 'inbox.foreign', 'urn:workshop', '{}'));
        $em->flush();
        $em->clear();
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id);
        self::assertResponseIsSuccessful();
        self::assertSame(array_map(static fn (OutboxEvent $event): string => (string) $event->id, array_reverse(array_slice($events, 2))), $crawler->filter('[data-workshop-event]')->extract(['data-workshop-event']));
        self::assertSelectorTextContains('[data-workshop-event]', 'inbox.event_6');
    }

    public function test_in_motion_shows_the_cards_with_an_open_run_and_targets_the_shared_drawer(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'workshop-cards@example.com');
        [$project, $foreign] = $this->projects($em, $owner, 'Card workshop', 'Other cards');
        $create = self::getContainer()->get(CreateCardHandler::class);
        $idle = $create(new CreateCardCommand($project, 'Idle work', '', CardType::Feature));
        $queued = $create(new CreateCardCommand($project, 'Queued work', '', CardType::Feature));
        $session = $create(new CreateCardCommand($project, 'Session work', '', CardType::Feature));
        $blocked = $create(new CreateCardCommand($project, 'Blocked work', '', CardType::Feature));
        $command = $create(new CreateCardCommand($project, 'Command work', '', CardType::Feature));
        $done = $create(new CreateCardCommand($project, 'Done work', '', CardType::Feature, column: $this->column($project, 'done')));
        $foreignCard = $create(new CreateCardCommand($foreign, 'Foreign work', '', CardType::Feature));
        $this->openRun($em, $project, $queued, WorkerRunState::Queued, 'tech-design', '-3 days');
        $this->openRun($em, $project, $session, WorkerRunState::Queued, 'implement', '-2 hours');
        $this->openRun($em, $project, $session, WorkerRunState::Running, 'Work on it', '-30 minutes', WorkerRunKind::Interactive);
        $this->openRun($em, $project, $blocked, WorkerRunState::Blocked, 'implement', '-1 hour');
        $this->openRun($em, $project, $command, WorkerRunState::Running, 'sync', '-10 minutes', WorkerRunKind::Command);
        $this->openRun($em, $project, $done, WorkerRunState::Running, 'review', '-5 minutes');
        $this->openRun($em, $foreign, $foreignCard, WorkerRunState::Queued, 'implement', '-1 hour');
        $em->clear();
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [(string) $session->number, (string) $command->number, (string) $done->number, (string) $queued->number],
            $crawler->filter('[data-workshop-card]')->extract(['data-workshop-card']),
        );
        self::assertSelectorNotExists('[data-workshop-card="'.$idle->number.'"]');
        self::assertSelectorNotExists('[data-workshop-card="'.$blocked->number.'"]');
        self::assertSelectorTextSame('[data-workshop-stat="open-cards"] .lp-workshop-stat__value', '5');
        self::assertSelectorTextSame('[data-workshop-stat="completed-cards"] .lp-workshop-stat__value', '1');
        $queuedTile = $crawler->filter('[data-workshop-card="'.$queued->number.'"]');
        self::assertStringContainsString('Queued work', $queuedTile->text());
        self::assertStringContainsString('Backlog', $queuedTile->text());
        self::assertSame('Queued', trim($queuedTile->filter('.lp-status-chip')->text()));
        self::assertSame('tech-design', trim($queuedTile->filter('.lp-workshop-work-card__kind')->text()));
        self::assertSame('3 d', trim($queuedTile->filter('time')->text()));
        self::assertSame('elapsed', $queuedTile->filter('time')->attr('data-controller'));
        self::assertSame('%count% min', $queuedTile->filter('time')->attr('data-elapsed-minutes-value'));
        $sessionTile = $crawler->filter('[data-workshop-card="'.$session->number.'"]');
        self::assertSame('Running', trim($sessionTile->filter('.lp-status-chip')->text()));
        self::assertStringContainsString('lp-status-chip--pending', (string) $sessionTile->filter('.lp-status-chip')->attr('class'));
        self::assertSame('Interactive', trim($sessionTile->filter('.lp-workshop-work-card__kind')->text()));
        self::assertSame('30 min', trim($sessionTile->filter('time')->text()));
        $commandTile = $crawler->filter('[data-workshop-card="'.$command->number.'"]');
        self::assertSame('Command', trim($commandTile->filter('.lp-workshop-work-card__kind')->text()));
        self::assertSelectorNotExists('[data-workshop-more]');
        self::assertSame('card-drawer-frame', $queuedTile->attr('data-turbo-frame'));
        self::assertCount(1, $crawler->filter('#card-drawer-frame'));
        $client->click($queuedTile->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Queued work');
    }

    public function test_in_motion_shows_six_tiles_and_links_the_rest_to_the_open_runs(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'workshop-more@example.com');
        [$project] = $this->projects($em, $owner, 'Busy workshop');
        $create = self::getContainer()->get(CreateCardHandler::class);
        $numbers = [];
        for ($index = 1; $index <= 8; ++$index) {
            $card = $create(new CreateCardCommand($project, 'Work '.$index, '', CardType::Feature));
            $this->openRun($em, $project, $card, WorkerRunState::Queued, 'implement', '-'.(20 - $index).' minutes');
            $numbers[] = (string) $card->number;
        }
        $this->openRun($em, $project, null, WorkerRunState::Queued, 'implement', '-1 day');
        $em->clear();
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id);

        self::assertResponseIsSuccessful();
        self::assertSame(\array_slice($numbers, 0, 6), $crawler->filter('[data-workshop-card]')->extract(['data-workshop-card']));
        self::assertSame('+2 more', trim($crawler->filter('[data-workshop-more]')->text()));
        self::assertSame('/projects/'.$project->id.'/worker-runs?outcome=open', $crawler->filter('[data-workshop-more]')->attr('href'));
    }

    public function test_nothing_in_motion_says_what_puts_a_card_there(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'workshop-idle@example.com');
        [$project] = $this->projects($em, $owner, 'Idle workshop');
        self::getContainer()->get(CreateCardHandler::class)(new CreateCardCommand($project, 'Idle work', '', CardType::Feature));
        $em->clear();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-workshop-card]');
        self::assertSelectorTextContains('#workshop-in-motion', 'Nothing in motion. A card shows here when a bridge queues it or a session works on it.');
        self::assertSelectorExists('.lp-section-heading a[href="/projects/'.$project->id.'/board"]');
    }

    public function test_the_in_motion_frame_route_renders_the_frame_the_workshop_holds(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'workshop-frame@example.com');
        [$project] = $this->projects($em, $owner, 'Framed workshop');
        $card = self::getContainer()->get(CreateCardHandler::class)(new CreateCardCommand($project, 'Framed work', '', CardType::Feature));
        $this->openRun($em, $project, $card, WorkerRunState::Queued, 'implement', '-2 days');
        $em->clear();
        $client->loginUser($owner);

        $page = $client->request(Request::METHOD_GET, '/projects/'.$project->id);
        self::assertResponseIsSuccessful();
        $live = $page->filter('[data-controller="worker-run-refresh"]');
        self::assertSame('/projects/'.$project->id.'/in-motion', $live->attr('data-worker-run-refresh-url-value'));
        self::assertSame(['worker_run.changed', 'board.card_changed', 'board.columns_changed'], json_decode((string) $live->attr('data-worker-run-refresh-events-value'), true));
        self::assertSame('frame', $live->filter('turbo-frame#workshop-in-motion')->attr('data-worker-run-refresh-target'));
        self::assertSame('_top', $live->filter('turbo-frame#workshop-in-motion')->attr('target'));
        $pageFrame = $page->filter('turbo-frame#workshop-in-motion')->outerHtml();

        $frame = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/in-motion', server: ['HTTP_TURBO_FRAME' => 'workshop-in-motion']);

        self::assertResponseIsSuccessful();
        self::assertSame($pageFrame, $frame->filter('turbo-frame#workshop-in-motion')->outerHtml());
        self::assertCount(1, $frame->filter('[data-workshop-card="'.$card->number.'"]'));
    }

    public function test_a_non_member_cannot_load_the_in_motion_frame(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'workshop-frame-owner@example.com');
        $other = $this->user($em, 'workshop-frame-other@example.com');
        [$project] = $this->projects($em, $owner, 'Private frame');
        $client->loginUser($other);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/in-motion');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_owner_sees_project_workshop_with_real_rollup_counts(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'workshop-owner@example.com');
        $project = new Project($owner, 'Workshop project');
        $em->persist($project);
        $em->persist(new Document($owner, $project, 'First document'));
        $em->persist(new Document($owner, $project, 'Second document'));
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id);

        self::assertResponseIsSuccessful();
        self::assertSame('Your workshop', trim($crawler->filter('[data-workshop] h1')->text()));
        self::assertSelectorNotExists('[data-workshop-stat="requests"] .lp-workshop-stat__value');
        self::assertSelectorTextContains('[data-workshop-stat="requests"]', 'The inbox is disabled');
        self::assertSelectorTextSame('[data-workshop-stat="open-cards"] .lp-workshop-stat__value', '0');
        self::assertSelectorTextSame('[data-workshop-stat="completed-cards"] .lp-workshop-stat__value', '0');
        self::assertSelectorExists('a[href="/projects/'.$project->id.'/documents"]');
    }

    public function test_non_owner_cannot_open_workshop(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'workshop-first@example.com');
        $other = $this->user($em, 'workshop-other@example.com');
        $project = new Project($owner, 'Private workshop');
        $em->persist($project);
        $em->flush();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_review_row_shows_the_document_or_pull_request_it_reviews(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $flags = self::getContainer()->get(FeatureFlagRepository::class)->findAllIndexed();
        $flags['inbox.enabled']->value = true;
        $owner = $this->user($em, 'workshop-attention-subject@example.com');
        $project = new Project($owner, 'Subject project');
        $em->persist($project);
        $this->seedColumns($project);
        $document = new Document($owner, $project, 'Spec');
        $em->persist($document);
        $em->persist(new DocumentVersion(document: $document, versionNumber: 1, markdownSource: '# Spec', renderedHtml: '<h1>Spec</h1>'));
        $em->flush();
        $card = self::getContainer()->get(CreateCardHandler::class)(new CreateCardCommand($project, 'Checkout', '', CardType::Feature));
        $pullRequest = new CardPullRequest($card, 'https://github.com/example/app/pull/7', repository: 'example/app', number: 7);
        $em->persist($pullRequest);
        $question = new InboxItem($project, 1, InboxItemKind::Question, 'Question', false);
        $documentReview = new InboxItem($project, 2, InboxItemKind::Review, 'Read the spec', false);
        $pullRequestReview = new InboxItem($project, 3, InboxItemKind::Review, 'Review the change', false);
        foreach ([$question, $documentReview, $pullRequestReview] as $item) {
            $em->persist($item);
        }
        $em->persist(new InboxReview($documentReview, $document));
        $em->persist(new InboxReview($pullRequestReview, $pullRequest));
        $em->flush();
        $em->clear();
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id);

        self::assertResponseIsSuccessful();
        // Oldest first.
        self::assertSame(['agent', 'document', 'pull-request'], $crawler->filter('[data-workshop-attention] .lp-workshop-attention__icon')->extract(['data-subject']));

        $inbox = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');
        self::assertResponseIsSuccessful();
        // The inbox lists items outside an ask by number.
        self::assertSame(['agent', 'document', 'pull-request'], $inbox->filter('.lp-inbox-request [data-subject]')->extract(['data-subject']));
    }

    public function test_attention_links_the_oldest_open_items_in_this_project(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        $flags->findAllIndexed()['inbox.enabled']->value = true;
        $owner = $this->user($em, 'workshop-attention@example.com');
        $project = new Project($owner, 'Attention project');
        $foreign = new Project($owner, 'Other project');
        $em->persist($project);
        $em->persist($foreign);
        for ($number = 1; $number <= 8; ++$number) {
            $em->persist(new InboxItem($project, $number, InboxItemKind::Question, 'Request '.$number, 1 === $number));
        }
        $closed = new InboxItem($project, 9, InboxItemKind::Todo, 'Closed request', false);
        $closed->state = InboxItemState::Done;
        $em->persist($closed);
        $em->persist(new InboxItem($foreign, 10, InboxItemKind::Question, 'Foreign request', true));
        $em->flush();
        $em->clear();
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id);

        self::assertResponseIsSuccessful();
        self::assertCount(6, $crawler->filter('[data-workshop-attention]'));
        self::assertSame('8', trim($crawler->filter('.lp-workshop-stat__value')->first()->text()));
        self::assertSelectorTextContains('[data-workshop-attention]', 'Request 1');
        self::assertSelectorTextContains('[data-workshop-attention] .lp-workshop-attention__badge', 'Blocking');
        // A question or a to-do shows the agent that asked it.
        self::assertSame(array_fill(0, 6, 'agent'), $crawler->filter('[data-workshop-attention] .lp-workshop-attention__icon')->extract(['data-subject']));
        $links = $crawler->filter('[data-workshop-attention]')->extract(['href']);
        self::assertSame(array_map(static fn (int $number): string => '/projects/'.$project->id.'/inbox#inbox-item-'.$number, [1, 2, 3, 4, 5, 6]), $links);
        $client->click($crawler->filter('[data-workshop-attention]')->first()->link());
        self::assertResponseIsSuccessful();
        // A one-item ask shows its title once, as the ask's heading.
        self::assertSelectorTextContains('.lp-inbox-ask:has(#inbox-item-1) .lp-inbox-ask__title', 'Request 1');

        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        $flags->findAllIndexed()['inbox.enabled']->value = false;
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->request(Request::METHOD_GET, '/projects/'.$project->id);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-workshop-attention]');
        self::assertSelectorTextContains('[data-workshop]', 'The inbox is disabled on this instance.');
    }

    /** @return non-empty-list<Project> */
    private function projects(EntityManagerInterface $em, User $owner, string $name, string ...$names): array
    {
        $projects = [];
        foreach ([$name, ...$names] as $each) {
            $project = new Project($owner, $each);
            $em->persist($project);
            $this->seedColumns($project);
            $projects[] = $project;
        }
        $em->flush();

        return $projects;
    }

    /** A null card stands for a card that no longer exists. */
    private function openRun(EntityManagerInterface $em, Project $project, ?Card $card, WorkerRunState $state, string $workKind, string $ago, WorkerRunKind $kind = WorkerRunKind::Worker): void
    {
        $at = new \DateTimeImmutable($ago);
        $em->persist(new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            cardId: $card->id ?? Uuid::v7(),
            cardNumber: $card->number ?? 999,
            workKind: $workKind,
            state: $state,
            startedAt: WorkerRunState::Running === $state ? $at : null,
            receivedAt: $at,
            kind: $kind,
        ));
        $em->flush();
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'Workshop user', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }
}
