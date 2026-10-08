<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Event\CardDocumentsChanged;
use App\Module\Board\Service\DocumentWorkLinks;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class DocumentWorkLinksTest extends KernelTestCase
{
    use \App\Tests\Module\Board\BoardColumnFixtures;

    public function test_each_link_added_or_removed_reports_the_change_and_a_kept_link_does_not(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = new User(fullName: 'Riley', email: 'links-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($owner);
        $project = new Project($owner, 'links-'.uniqid());
        $em->persist($project);
        $this->seedColumns($project);
        $document = new Document($owner, $project, 'The design');
        $em->persist($document);
        $em->flush();
        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $kept = $create(new CreateCardCommand(project: $project, title: 'Kept', body: '', type: 'feature'));
        $added = $create(new CreateCardCommand(project: $project, title: 'Added', body: '', type: 'feature'));
        $dropped = $create(new CreateCardCommand(project: $project, title: 'Dropped', body: '', type: 'feature'));
        $links = self::getContainer()->get(DocumentWorkLinks::class);
        self::assertInstanceOf(DocumentWorkLinks::class, $links);
        $links->synchronize($document, [(string) $kept->id, (string) $dropped->id]);
        $em->flush();

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $changed = [];
        $dispatcher->addListener(CardDocumentsChanged::class, static function (CardDocumentsChanged $event) use (&$changed): void {
            $changed[] = $event->cardId->toRfc4122();
        });

        $links->synchronize($document, [(string) $kept->id, (string) $added->id]);

        self::assertEqualsCanonicalizing([$added->id?->toRfc4122(), $dropped->id?->toRfc4122()], $changed);
    }
}
