<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\CardType;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Outbox\Entity\OutboxEvent;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
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

    public function test_work_tiles_show_open_project_cards_and_target_the_shared_drawer(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(FeatureFlagRepository::class)->findAllIndexed()['board.enabled']->value = true;
        $owner = $this->user($em, 'workshop-cards@example.com');
        $project = new Project($owner, 'Card workshop');
        $foreign = new Project($owner, 'Other cards');
        foreach ([$project, $foreign] as $entry) {
            $em->persist($entry);
            $this->seedColumns($entry);
        }
        $em->flush();
        $create = self::getContainer()->get(CreateCardHandler::class);
        for ($number = 1; $number <= 8; ++$number) {
            $create(new CreateCardCommand($project, 'Work '.$number, '', CardType::Feature));
        }
        $create(new CreateCardCommand($project, 'Completed work', '', CardType::Feature, column: $this->column($project, 'done')));
        $create(new CreateCardCommand($foreign, 'Foreign work', '', CardType::Feature));
        $create(new CreateCardCommand($foreign, 'Foreign completed work', '', CardType::Feature, column: $this->column($foreign, 'done')));
        $em->clear();
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id);
        self::assertResponseIsSuccessful();
        self::assertSame(['8', '7', '6', '5', '4', '3'], $crawler->filter('[data-workshop-card]')->extract(['data-workshop-card']));
        self::assertSelectorTextContains('[data-workshop-card="8"]', 'Work 8');
        self::assertSelectorTextContains('[data-workshop-card="8"]', 'Backlog');
        self::assertSelectorTextSame('[data-workshop-stat="open-cards"] .lp-workshop-stat__value', '8');
        self::assertSelectorTextSame('[data-workshop-stat="completed-cards"] .lp-workshop-stat__value', '1');
        self::assertSelectorTextSame('[data-workshop-stat="open-cards"] .lp-workshop-stat__copy', 'Open cardsAcross this project');
        self::assertSame('card-drawer-frame', $crawler->filter('[data-workshop-card="8"]')->attr('data-turbo-frame'));
        self::assertCount(1, $crawler->filter('#card-drawer-frame'));
        $client->click($crawler->filter('[data-workshop-card="8"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Work 8');

        self::getContainer()->get(FeatureFlagRepository::class)->findAllIndexed()['board.enabled']->value = false;
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->request(Request::METHOD_GET, '/projects/'.$project->id);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-workshop-card]');
        self::assertSelectorNotExists('[data-workshop-stat="open-cards"] .lp-workshop-stat__value');
        self::assertSelectorNotExists('[data-workshop-stat="completed-cards"] .lp-workshop-stat__value');
        self::assertSelectorTextContains('[data-workshop]', 'The board is disabled on this instance.');
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
        self::assertSelectorNotExists('.lp-workshop-stat__value');
        self::assertSelectorTextContains('[data-workshop-stat="requests"]', 'The inbox is disabled');
        self::assertSelectorTextContains('[data-workshop-stat="open-cards"]', 'The board is disabled');
        self::assertSelectorTextContains('[data-workshop-stat="completed-cards"]', 'The board is disabled');
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

    public function test_attention_links_the_latest_open_items_in_this_project(): void
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
            $em->persist(new InboxItem($project, $number, InboxItemKind::Question, 'Request '.$number, 8 === $number));
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
        self::assertSelectorTextContains('[data-workshop-attention]', 'Request 8');
        self::assertSelectorTextContains('[data-workshop-attention] .lp-workshop-attention__badge', 'Blocking');
        $links = $crawler->filter('[data-workshop-attention]')->extract(['href']);
        self::assertSame(array_map(static fn (int $number): string => '/projects/'.$project->id.'/inbox#inbox-item-'.$number, [8, 7, 6, 5, 4, 3]), $links);
        $client->click($crawler->filter('[data-workshop-attention]')->first()->link());
        self::assertResponseIsSuccessful();
        // A one-item ask shows its title once, as the ask's heading.
        self::assertSelectorTextContains('.lp-inbox-ask:has(#inbox-item-8) .lp-inbox-ask__title', 'Request 8');

        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        $flags->findAllIndexed()['inbox.enabled']->value = false;
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->request(Request::METHOD_GET, '/projects/'.$project->id);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-workshop-attention]');
        self::assertSelectorTextContains('[data-workshop]', 'The inbox is disabled on this instance.');
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
