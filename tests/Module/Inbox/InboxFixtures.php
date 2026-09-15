<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Ubermuda\FeatureFlagsBundle\Reader\FeatureFlagReaderInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/** Persists and does not flush, so a test decides when the rows commit. */
trait InboxFixtures
{
    use BoardColumnFixtures;

    /** Stores the flag and drops the reader's copy, which lasts for the whole test otherwise. */
    private function switchFlag(EntityManagerInterface $em, string $name, bool $enabled): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[$name]->value = $enabled;
        $em->flush();

        $reader = self::getContainer()->get(FeatureFlagReaderInterface::class);
        self::assertInstanceOf(ResetInterface::class, $reader);
        $reader->reset();
    }

    private function owner(EntityManagerInterface $em, string $slug): User
    {
        $owner = new User(fullName: 'Riley', email: $slug.'-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($owner);

        return $owner;
    }

    private function project(EntityManagerInterface $em, User $owner, string $slug): Project
    {
        $project = new Project($owner, $slug.'-'.uniqid());
        $em->persist($project);
        $this->seedColumns($project);

        return $project;
    }

    private function card(EntityManagerInterface $em, Project $project, int $number = 1): Card
    {
        $card = new Card(project: $project, column: $this->column($project, 'backlog'), title: 'Ship it', body: 'Body', number: $number);
        $em->persist($card);

        return $card;
    }

    private function document(EntityManagerInterface $em, Project $project): Document
    {
        $document = new Document($project->owner, $project, 'The design');
        $em->persist($document);

        return $document;
    }

    private function item(EntityManagerInterface $em, Project $project, int $number = 1, string $title = 'Which column?'): InboxItem
    {
        $item = new InboxItem(project: $project, number: $number, kind: InboxItemKind::Question, title: $title, blocking: true, options: ['next', 'done']);
        $em->persist($item);

        return $item;
    }

    private function ask(EntityManagerInterface $em, Project $project): InboxAsk
    {
        $ask = new InboxAsk(project: $project, sessionId: Uuid::v4(), bridgeId: Uuid::v4(), context: 'Two decisions first');
        $em->persist($ask);

        return $ask;
    }
}
