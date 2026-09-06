<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Service\CardExporter;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardExporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CardExporter $exporter;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $exporter = self::getContainer()->get(CardExporter::class);
        self::assertInstanceOf(CardExporter::class, $exporter);
        $this->exporter = $exporter;
    }

    public function test_it_writes_one_file(): void
    {
        self::assertSame('cards.json', $this->exporter->filename());
    }

    public function test_a_card_carries_every_field_and_its_pull_requests(): void
    {
        $owner = $this->user('card-export');
        $project = new Project($owner, 'export-'.uniqid());
        $this->em->persist($project);

        $completedAt = new \DateTimeImmutable('2026-03-04 10:11:12');
        $createdAt = new \DateTimeImmutable('2026-03-01 09:00:00');
        // Distinct from $createdAt, which the constructor copies it from, so the
        // assertion cannot pass on an export that reads the wrong field.
        $updatedAt = new \DateTimeImmutable('2026-03-05 14:00:00');
        $firstAddedAt = new \DateTimeImmutable('2026-03-02 08:00:00');
        $secondAddedAt = new \DateTimeImmutable('2026-03-03 08:00:00');

        $card = new Card(
            project: $project,
            title: 'Rotate the signing key',
            body: 'The key is a year old.',
            type: CardType::Bug,
            priority: CardPriority::High,
            status: CardStatus::Done,
            origin: CardOrigin::Human,
            position: 7,
            createdAt: $createdAt,
        );
        $card->completedAt = $completedAt;
        $card->updatedAt = $updatedAt;
        $card->pullRequests->add(new CardPullRequest($card, 'https://github.com/ubermuda/loupe/pull/42', Forge::GitHub, 'ubermuda/loupe', 42, $firstAddedAt));
        $card->pullRequests->add(new CardPullRequest($card, 'https://git.example.test/patch', Forge::Other, addedAt: $secondAddedAt));
        $this->em->persist($card);
        $this->em->flush();
        $this->em->clear();

        $rows = iterator_to_array($this->exporter->export($owner), false);

        self::assertCount(1, $rows);
        self::assertSame([
            'id' => (string) $card->id,
            'project' => $project->name,
            'title' => 'Rotate the signing key',
            'body' => 'The key is a year old.',
            'status' => 'done',
            'priority' => 'high',
            'type' => 'bug',
            'origin' => 'human',
            'position' => 7,
            'completedAt' => $completedAt->format(\DateTimeInterface::ATOM),
            'createdAt' => $createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $updatedAt->format(\DateTimeInterface::ATOM),
            'pullRequests' => [
                [
                    'url' => 'https://github.com/ubermuda/loupe/pull/42',
                    'forge' => 'github',
                    'repository' => 'ubermuda/loupe',
                    'number' => 42,
                    'addedAt' => $firstAddedAt->format(\DateTimeInterface::ATOM),
                ],
                [
                    'url' => 'https://git.example.test/patch',
                    'forge' => 'other',
                    'repository' => null,
                    'number' => null,
                    'addedAt' => $secondAddedAt->format(\DateTimeInterface::ATOM),
                ],
            ],
        ], $rows[0]);
    }

    public function test_it_exports_the_owner_cards_only(): void
    {
        $owner = $this->user('card-export-mine');
        $stranger = $this->user('card-export-theirs');

        $mine = new Project($owner, 'mine-'.uniqid());
        $theirs = new Project($stranger, 'theirs-'.uniqid());
        $this->em->persist($mine);
        $this->em->persist($theirs);
        $this->em->persist(new Card(project: $mine, title: 'Mine', body: ''));
        $this->em->persist(new Card(project: $theirs, title: 'Theirs', body: ''));
        $this->em->flush();
        $this->em->clear();

        $rows = iterator_to_array($this->exporter->export($owner), false);

        self::assertCount(1, $rows);
        self::assertIsArray($rows[0]);
        self::assertSame('Mine', $rows[0]['title']);
    }

    /** @param non-empty-string $label */
    private function user(string $label): User
    {
        $user = new User(fullName: 'Riley', email: $label.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($user);

        return $user;
    }
}
