<?php

declare(strict_types=1);

namespace App\Tests\Outbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ActivityPageTest extends WebTestCase
{
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
        self::assertStringNotContainsString('other.secret', $crawler->text());
    }
}
