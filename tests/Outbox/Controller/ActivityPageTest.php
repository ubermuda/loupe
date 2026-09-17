<?php

declare(strict_types=1);

namespace App\Tests\Outbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ActivityPageTest extends WebTestCase
{
    public function test_card_links_resolve_only_existing_cards_in_the_event_project(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        static::getContainer()->get(FeatureFlagRepository::class)->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;
        $owner = new User(fullName: 'Owner', email: 'activity-links@example.com', password: 'x');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $project = new Project($owner, 'Activity links');
        $other = new Project($owner, 'Other project');
        $column = new BoardColumn($project, 'Work', 'work', 0);
        $otherColumn = new BoardColumn($other, 'Work', 'work', 0);
        $card = new Card($project, $column, 'Related work', '', 7);
        $foreign = new Card($other, $otherColumn, 'Foreign secret title', '', 8);
        foreach ([$owner, $project, $other, $column, $otherColumn, $card, $foreign] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        foreach ([(string) $card->id, (string) $foreign->id, (string) Uuid::v7(), 'not-a-uuid'] as $id) {
            $em->persist(new OutboxEvent($project, 'board.card_moved', 'topic', json_encode([
                'subject' => ['type' => 'card', 'id' => $id],
            ], \JSON_THROW_ON_ERROR)));
        }
        foreach (['{broken', 'null', '{"subject":"card"}', '{"subject":{"type":"document","id":"'.$card->id.'"}}'] as $payload) {
            $em->persist(new OutboxEvent($project, 'other.event', 'topic', $payload));
        }
        $em->flush();
        $em->clear();
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity');

        self::assertResponseIsSuccessful();
        self::assertCount(8, $crawler->filter('[data-activity-event-id]'));
        self::assertCount(1, $crawler->filter('[data-activity-work-link]'));
        $link = $crawler->filter('[data-activity-work-link]');
        self::assertSame('#7 Related work', $link->text());
        self::assertSame('/projects/'.$project->id.'/board/cards/'.$card->id, $link->attr('href'));
        self::assertStringNotContainsString('Foreign secret title', $crawler->text());
        self::assertStringNotContainsString((string) $foreign->id, $crawler->html());
        $client->click($link->link());
        self::assertResponseIsSuccessful();
        static::getContainer()->get(FeatureFlagRepository::class)->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = false;
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity');
        self::assertResponseIsSuccessful();
        self::assertCount(8, $crawler->filter('[data-activity-event-id]'));
        self::assertCount(0, $crawler->filter('[data-activity-work-link]'));
    }

    public function test_it_shows_delivered_and_pending_project_events_only(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Owner', email: 'activity-owner@example.com', password: 'x');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $project = new Project($owner, 'Activity');
        $other = new Project($owner, 'Other activity');
        $delivered = new OutboxEvent($project, 'board.card_moved', 'topic', '{}');
        $delivered->markPublished();
        $pending = new OutboxEvent($project, 'review.document_revised', 'topic', '{}');
        $foreign = new OutboxEvent($other, 'other.secret', 'topic', '{}');
        foreach ([$owner, $project, $other, $delivered, $pending, $foreign] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-activity-event-id]'));
        self::assertStringContainsString('board.card_moved', $crawler->text());
        self::assertStringContainsString('review.document_revised', $crawler->text());
        self::assertCount(1, $crawler->filter('[data-event-family="document"]'));
        self::assertCount(1, $crawler->filter('[data-activity-filter-target="toggle"]'));
        self::assertSame((string) $project->id, $crawler->filter('[data-activity-filter-project-value]')->attr('data-activity-filter-project-value'));
        self::assertSame('100', $crawler->filter('[data-activity-filter-page-size-value]')->attr('data-activity-filter-page-size-value'));
        self::assertStringNotContainsString('other.secret', $crawler->text());
    }
}
