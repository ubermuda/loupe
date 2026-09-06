<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/** Fixtures the board's WebTestCase classes share. */
trait BoardScenario
{
    /**
     * The board ships off, so every test that opens a board route has to switch
     * it on. The container is read fresh, so this stays correct after a request
     * has rebooted the kernel.
     */
    private function enableBoard(): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'Riley Chen', email: $email, password: 'hashed');
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function project(EntityManagerInterface $em, User $owner, string $name = 'board-app'): Project
    {
        $project = new Project($owner, $name);
        $em->persist($project);
        $em->flush();

        return $project;
    }

    private function card(
        EntityManagerInterface $em,
        Project $project,
        string $title,
        CardStatus $status = CardStatus::Backlog,
        CardPriority $priority = CardPriority::Medium,
        int $position = 0,
        string $body = '',
    ): Card {
        $card = new Card(
            project: $project,
            title: $title,
            body: $body,
            type: CardType::Feature,
            priority: $priority,
            status: $status,
            position: $position,
        );

        if (CardStatus::Done === $status) {
            $card->completedAt = new \DateTimeImmutable();
        }

        $em->persist($card);
        $em->flush();

        return $card;
    }
}
