<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxAsk;
use App\Module\Inbox\Entity\InboxAskItem;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/** Flushed fixtures for the inbox page and its forms, with users that pass the sign-in gates. */
trait InboxScenario
{
    private function setInboxFlag(bool $enabled): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = $enabled;
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function signedUpUser(EntityManagerInterface $em, string $slug): User
    {
        $user = new User(fullName: 'Riley Chen', email: $slug.'-'.uniqid().'@example.com', password: 'hashed');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function inboxProject(EntityManagerInterface $em, User $owner): Project
    {
        $project = new Project($owner, 'inbox-'.uniqid());
        $em->persist($project);
        $em->flush();

        return $project;
    }

    /**
     * @param list<string> $options
     */
    private function question(EntityManagerInterface $em, Project $project, int $number, array $options = ['JSON', 'CSV'], bool $multiple = false, bool $freeText = false, string $title = 'Which format?'): InboxItem
    {
        $item = new InboxItem(project: $project, number: $number, kind: InboxItemKind::Question, title: $title, blocking: true, options: $options, multiple: $multiple, freeText: $freeText);
        $em->persist($item);
        $em->flush();

        return $item;
    }

    private function todo(EntityManagerInterface $em, Project $project, int $number, string $title = 'Review pull request 482'): InboxItem
    {
        $item = new InboxItem(project: $project, number: $number, kind: InboxItemKind::Todo, title: $title, blocking: false);
        $em->persist($item);
        $em->flush();

        return $item;
    }

    /** @param list<InboxItem> $items */
    private function askHolding(EntityManagerInterface $em, Project $project, array $items, ?\DateTimeImmutable $closedAt = null, ?string $context = null, ?\DateTimeImmutable $createdAt = null): InboxAsk
    {
        $ask = new InboxAsk(project: $project, sessionId: Uuid::v4(), bridgeId: Uuid::v4(), context: $context, createdAt: $createdAt ?? new \DateTimeImmutable());
        $ask->closedAt = $closedAt;
        foreach ($items as $item) {
            $ask->items->add(new InboxAskItem($ask, $item));
        }
        $em->persist($ask);
        $em->flush();

        return $ask;
    }

    private function answered(EntityManagerInterface $em, InboxItem $item, InboxItemState $state = InboxItemState::Answered): InboxItem
    {
        $item->state = $state;
        $item->selectedOptions = InboxItemState::Answered === $state ? [0] : [];
        $item->closedAt = new \DateTimeImmutable('-1 hour');
        $em->flush();

        return $item;
    }
}
