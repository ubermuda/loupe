<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\RecordForgeDeliveryCommand;
use App\Module\Board\Command\RecordForgeDeliveryHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\ForgeDelivery;
use App\Module\Forge\ForgeEventType;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Two projects can link one pull request, and a delivery belongs to the project that owns the repository. */
final class RecordForgeDeliveryHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_a_delivery_reaches_only_the_cards_of_the_owning_project(): void
    {
        $owner = $this->project('owner');
        $stranger = $this->project('stranger');
        $ownCard = $this->linkedCard($owner, 'acme/widgets', 5);
        $this->linkedCard($stranger, 'acme/widgets', 5);

        $this->handle($owner, new ForgeDelivery(ForgeEventType::MERGED, 'github', 'ACME/widgets', 5));

        self::assertSame([(string) $ownCard->id], $this->outboxSubjects($owner));
        self::assertSame([], $this->outboxSubjects($stranger));
    }

    public function test_a_move_repoints_only_the_links_of_the_owning_project(): void
    {
        $owner = $this->project('owner');
        $stranger = $this->project('stranger');
        $ownCard = $this->linkedCard($owner, 'acme/old', 1);
        $strangerCard = $this->linkedCard($stranger, 'acme/old', 1);

        $this->handle($owner, new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, 'github', 'acme/old', movedTo: 'acme/new'));

        $this->em->clear();
        self::assertSame(['acme/new'], $this->pathsOf($ownCard));
        self::assertSame(['acme/old'], $this->pathsOf($strangerCard));
    }

    private function handle(Project $project, ForgeDelivery $delivery): void
    {
        $handler = self::getContainer()->get(RecordForgeDeliveryHandler::class);
        self::assertInstanceOf(RecordForgeDeliveryHandler::class, $handler);

        $handler(new RecordForgeDeliveryCommand($project->id ?? throw new \LogicException('Flushed.'), [$delivery]));
    }

    /** @return list<string> */
    private function outboxSubjects(Project $project): array
    {
        $rows = $this->em->getConnection()->fetchFirstColumn(
            'SELECT payload FROM outbox_events WHERE project_id = :project',
            ['project' => $project->id],
            ['project' => 'uuid'],
        );

        return array_map(static fn (mixed $payload): string => json_decode((string) $payload, true, flags: \JSON_THROW_ON_ERROR)['subject']['id'], $rows);
    }

    /** @return list<string> */
    private function pathsOf(Card $card): array
    {
        return array_values(array_map(
            strval(...),
            $this->em->getConnection()->fetchFirstColumn(
                'SELECT repository FROM board_card_pull_requests WHERE card_id = :card',
                ['card' => $card->id],
                ['card' => 'uuid'],
            ),
        ));
    }

    private function linkedCard(Project $project, string $repository, int $number): Card
    {
        $column = new BoardColumn($project, 'Work', 'work-'.uniqid(), 0);
        $card = new Card($project, $column, 'Ship it', '', random_int(1, 1_000_000));
        $link = new CardPullRequest($card, 'https://github.com/'.$repository.'/pull/'.$number, Forge::GitHub, $repository, $number);
        foreach ([$column, $card, $link] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return $card;
    }

    private function project(string $label): Project
    {
        $user = new User(fullName: 'Riley', email: 'record-'.$label.'-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, $label.'-'.uniqid());
        $this->em->persist($user);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
