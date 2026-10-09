<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardEventRepositoryCreatedTypeTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CardEventRepository $events;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $this->events = $events;

        $owner = new User(fullName: 'Riley', email: 'created-type-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'created-type-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    public function test_it_reads_the_type_the_created_row_records(): void
    {
        $card = $this->card(1);
        $this->events->record($card, CardEventKind::Created, Actor::Human, null, ['type' => 'bug']);
        $this->em->flush();

        self::assertSame('bug', $this->events->createdType($card));
    }

    public function test_it_ignores_the_type_of_other_rows_and_other_cards(): void
    {
        $card = $this->card(1);
        $other = $this->card(2);
        $this->events->record($card, CardEventKind::Moved, Actor::Human, null, ['type' => 'epic']);
        $this->events->record($other, CardEventKind::Created, Actor::Human, null, ['type' => 'bug']);
        $this->em->flush();

        self::assertNull($this->events->createdType($card));
    }

    public function test_it_gives_null_for_a_created_row_with_no_type(): void
    {
        $card = $this->card(1);
        $this->events->record($card, CardEventKind::Created, Actor::Human, null, ['column' => []]);
        $this->em->flush();

        self::assertNull($this->events->createdType($card));
    }

    public function test_it_gives_null_for_a_card_with_no_created_row(): void
    {
        self::assertNull($this->events->createdType($this->card(1)));
    }

    private function card(int $number): Card
    {
        $card = new Card($this->project, $this->column($this->project, 'backlog'), 'A card', '', $number, 'feature');
        $this->em->persist($card);
        $this->em->flush();

        return $card;
    }
}
