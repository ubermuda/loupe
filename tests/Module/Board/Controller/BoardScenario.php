<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Mercure\LiveUpdates;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Project\Entity\Project;
use App\Outbox\AgentPush;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;

/** Fixtures the board's WebTestCase classes share. */
trait BoardScenario
{
    use BoardColumnFixtures;

    /**
     * The next card number to hand out, per project.
     *
     * A card number counts from 1 and is unique inside its project only, so two
     * projects both start at 1 rather than sharing one run of numbers.
     *
     * @var array<string, int>
     */
    private array $nextCardNumber = [];

    /** Both flags ship on through a migration, so each row exists to flip. */
    private function setHubFlags(EntityManagerInterface $em, bool $liveUpdates, bool $agentPush): void
    {
        foreach ([LiveUpdates::FLAG => $liveUpdates, AgentPush::FLAG => $agentPush] as $name => $value) {
            self::assertSame(1, $em->getConnection()->executeStatement('UPDATE feature_flag SET value = ? WHERE name = ?', [$value ? 'true' : 'false', $name]));
        }
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'Riley Chen', email: $email, password: 'hashed');
        // Both gates divert an authenticated HTML request before the board sees
        // it: an unverified address goes to /register/check-email, and an
        // unstamped user to /terms/accept.
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function project(EntityManagerInterface $em, User $owner, string $name = 'board-app'): Project
    {
        $project = new Project($owner, $name);
        $em->persist($project);
        $this->seedColumns($project);
        $em->flush();

        return $project;
    }

    /** @param string $column the slug of one of the four seeded columns */
    private function card(
        EntityManagerInterface $em,
        Project $project,
        string $title,
        string $column = 'backlog',
        int $position = 0,
        string $body = '',
    ): Card {
        $projectKey = (string) $project->id;
        $number = $this->nextCardNumber[$projectKey] ?? 1;
        $this->nextCardNumber[$projectKey] = $number + 1;

        $boardColumn = $this->column($project, $column);
        $card = new Card(
            project: $project,
            column: $boardColumn,
            title: $title,
            body: $body,
            number: $number,
            type: CardType::Feature,
            position: $position,
        );

        if ($boardColumn->terminal) {
            $card->completedAt = new \DateTimeImmutable();
        }

        $em->persist($card);
        $em->flush();

        return $card;
    }

    /** Links a pull request to the card and stores the state a read found, or no read at all. */
    private function linkReadPullRequest(EntityManagerInterface $em, Card $card, PullRequestSnapshot $snapshot, bool $read = true): void
    {
        $card->replacePullRequests(new CardPullRequest($card, 'https://github.com/acme/app/pull/'.$card->number, Forge::GitHub, 'acme/app', $card->number));
        $row = new ForgePullRequest($card->project, 'github', 'acme/app', $card->number);
        $row->apply($snapshot);
        $row->refreshedAt = $read ? new \DateTimeImmutable() : null;
        $em->persist($row);
        $em->flush();
    }

    private function typed(EntityManagerInterface $em, Card $card, CardType $type): Card
    {
        $card->type = $type;
        $em->flush();

        return $card;
    }

    private function childOf(EntityManagerInterface $em, Card $epic, Card $child): Card
    {
        $child->parent = $epic;
        $em->flush();

        return $child;
    }
}
