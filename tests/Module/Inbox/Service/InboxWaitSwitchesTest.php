<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxProjectSettings;
use App\Module\Inbox\Repository\InboxProjectSettingsRepository;
use App\Module\Inbox\Service\InboxWaitSwitches;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxWaitSwitchesTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private InboxWaitSwitches $switches;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $repository = self::getContainer()->get(InboxProjectSettingsRepository::class);
        self::assertInstanceOf(InboxProjectSettingsRepository::class, $repository);
        $this->switches = new InboxWaitSwitches($repository);
    }

    public function test_a_project_without_a_row_has_every_switch_on_and_nothing_is_stored(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'wait-switches'), 'wait-switches');
        $this->em->flush();

        $settings = $this->switches->for($project);

        self::assertSame($project, $settings->project);
        foreach (InboxCardWaitTrigger::cases() as $trigger) {
            self::assertTrue($settings->isOn($trigger), $trigger->value);
        }
        self::assertFalse($this->em->contains($settings));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_project_settings WHERE project_id = :id', ['id' => (string) $project->id]));
    }

    public function test_the_stored_row_is_returned(): void
    {
        $owner = $this->owner($this->em, 'wait-switches');
        $project = $this->project($this->em, $owner, 'wait-switches');
        $other = $this->project($this->em, $owner, 'wait-switches-other');
        $stored = new InboxProjectSettings($project);
        $stored->runGaveUp = false;
        $this->em->persist($stored);
        $this->em->persist(new InboxProjectSettings($other));
        $this->em->flush();
        $this->em->clear();
        $project = $this->em->find($project::class, $project->id);
        self::assertNotNull($project);

        $settings = $this->switches->for($project);

        self::assertEquals($stored->id, $settings->id);
        self::assertFalse($settings->isOn(InboxCardWaitTrigger::RunGaveUp));
        self::assertTrue($settings->isOn(InboxCardWaitTrigger::DocumentInReview));
    }

    public function test_the_database_defaults_every_switch_on(): void
    {
        $project = $this->project($this->em, $this->owner($this->em, 'wait-switches'), 'wait-switches');
        $this->em->flush();
        $connection = $this->em->getConnection();
        $connection->executeStatement('INSERT INTO inbox_project_settings (id, project_id) VALUES (gen_random_uuid(), :id)', ['id' => (string) $project->id]);

        self::assertSame(
            ['document_in_review' => true, 'run_blocked' => true, 'run_gave_up' => true, 'run_waiting_for_person' => true],
            $connection->fetchAssociative('SELECT document_in_review, run_blocked, run_gave_up, run_waiting_for_person FROM inbox_project_settings WHERE project_id = :id', ['id' => (string) $project->id]),
        );
    }
}
