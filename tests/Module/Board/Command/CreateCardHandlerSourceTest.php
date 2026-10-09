<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSource;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CreateCardHandlerSourceTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CreateCardHandler $handler;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);
        $this->handler = $handler;

        $owner = new User(fullName: 'Riley', email: 'card-source-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'card-source-'.uniqid());
        $this->em->persist($this->project);
        $this->seedColumns($this->project);
        $this->em->flush();
    }

    /** @return iterable<string, array{Actor, CardSourceKind}> */
    public static function reporters(): iterable
    {
        yield 'human' => [Actor::Human, CardSourceKind::Person];
        yield 'agent' => [Actor::Agent, CardSourceKind::Agent];
        yield 'reviewer' => [Actor::Reviewer, CardSourceKind::Widget];
        yield 'system' => [Actor::System, CardSourceKind::Loupe];
    }

    #[DataProvider('reporters')]
    public function test_a_command_with_no_source_takes_it_from_the_reporter(Actor $reporter, CardSourceKind $kind): void
    {
        $card = ($this->handler)(new CreateCardCommand($this->project, 'Ship it', '', 'feature', reporter: $reporter));

        self::assertSame($kind, $this->reload($card)->source->kind);
    }

    public function test_a_given_source_wins_over_the_reporter_and_survives_a_reload(): void
    {
        $run = Uuid::v4();
        $runCard = Uuid::v4();

        $card = ($this->handler)(new CreateCardCommand($this->project, 'Ship it', '', 'feature', reporter: Actor::Agent, source: CardSource::run($run, $runCard)));

        $source = $this->reload($card)->source;
        self::assertSame(CardSourceKind::Run, $source->kind);
        self::assertEquals($run, $source->runId);
        self::assertEquals($runCard, $source->runCardId);
    }

    public function test_the_stored_row_carries_the_source(): void
    {
        $card = ($this->handler)(new CreateCardCommand($this->project, 'Ship it', '', 'feature', source: new CardSource(CardSourceKind::Widget)));

        $row = $this->em->getConnection()->fetchAssociative('SELECT source, source_run_id, source_run_card_id FROM board_cards WHERE id = :id', ['id' => (string) $card->id]);
        self::assertSame(['source' => 'widget', 'source_run_id' => null, 'source_run_card_id' => null], $row);
    }

    public function test_the_created_row_records_the_type_of_the_card(): void
    {
        $card = ($this->handler)(new CreateCardCommand($this->project, 'Ship it', '', 'bug'));

        $detail = $this->em->getConnection()->fetchOne("SELECT detail FROM board_card_events WHERE card_id = :id AND kind = 'created'", ['id' => (string) $card->id]);
        self::assertIsString($detail);
        self::assertSame('bug', json_decode($detail, true, flags: \JSON_THROW_ON_ERROR)['type']);
    }

    public function test_a_row_with_no_stored_source_reads_the_reporter(): void
    {
        $card = ($this->handler)(new CreateCardCommand($this->project, 'Ship it', '', 'feature', reporter: Actor::Human));
        $this->em->getConnection()->executeStatement('UPDATE board_cards SET source = NULL WHERE id = :id', ['id' => (string) $card->id]);

        self::assertSame(CardSourceKind::Person, $this->reload($card)->source->kind);
    }

    private function reload(Card $card): Card
    {
        $id = $card->id;
        $this->em->clear();

        return $this->em->find(Card::class, $id) ?? throw new \LogicException('The card is gone.');
    }
}
