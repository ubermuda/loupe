<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\SaveDecisionCommand;
use App\Module\Review\Command\SaveDecisionHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\DecisionBlockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\Auditor;

final class SaveDecisionHandlerTest extends KernelTestCase
{
    private Document $document;
    private SaveDecisionHandler $save;
    private EntityManagerInterface $em;
    private DecisionSelectionRepository $selections;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('Decision reviewer', 'save-decision@example.test', 'hashed');
        $project = new Project($owner, 'decisions');
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();
        $this->document = (self::getContainer()->get(CreateDocumentHandler::class))(new CreateDocumentCommand(
            $project,
            'Decisions',
            "<!-- decision: features -->\n\n- [ ] Import\n- [ ] Export\n- [ ] Search\n\n<!-- /decision -->\n\n<!-- decision: target -->\n\n- ( ) Staging\n- ( ) Production\n\n<!-- /decision -->",
        ));
        $this->selections = self::getContainer()->get(DecisionSelectionRepository::class);
        $this->save = new SaveDecisionHandler(
            self::getContainer()->get(DocumentVersionRepository::class),
            $this->selections,
            self::getContainer()->get(DecisionBlockService::class),
            $this->em,
            self::getContainer()->get(Auditor::class),
        );
    }

    public function test_saves_replaces_and_clears_the_complete_selection(): void
    {
        ($this->save)(new SaveDecisionCommand($this->document, 'features', 1, [0, 2], []));
        self::assertSame([0, 2], $this->indexes());
        ($this->save)(new SaveDecisionCommand($this->document, 'features', 1, [1], [2, 0]));
        self::assertSame([1], $this->indexes());
        ($this->save)(new SaveDecisionCommand($this->document, 'features', 1, [], [1]));
        self::assertSame([], $this->indexes());
    }

    public function test_a_repeat_keeps_the_saved_rows(): void
    {
        $command = new SaveDecisionCommand($this->document, 'features', 1, [0, 2], []);
        ($this->save)($command);
        $before = $this->selections->findByDocumentAndDecisionId($this->document, 'features');
        ($this->save)($command);
        self::assertSame($before, $this->selections->findByDocumentAndDecisionId($this->document, 'features'));
    }

    public function test_stale_answers_do_not_overwrite_a_saved_selection(): void
    {
        ($this->save)(new SaveDecisionCommand($this->document, 'features', 1, [0], []));
        try {
            ($this->save)(new SaveDecisionCommand($this->document, 'features', 1, [2], []));
            self::fail('A changed answer must be refused.');
        } catch (DomainErrors $errors) {
            self::assertSame(['optionIndexes' => 'review.decision.error.changed_answer'], $errors->errors);
        }
        self::assertTrue($this->em->isOpen());
        self::assertSame([0], $this->indexes());
    }

    public function test_invalid_options_and_versions_leave_the_selection_unchanged(): void
    {
        ($this->save)(new SaveDecisionCommand($this->document, 'features', 1, [0], []));
        foreach ([
            new SaveDecisionCommand($this->document, 'features', 1, [1, 9], [0]),
            new SaveDecisionCommand($this->document, 'features', 2, [1], [0]),
            new SaveDecisionCommand($this->document, 'missing', 1, [1], []),
            new SaveDecisionCommand($this->document, 'target', 1, [0, 1], []),
        ] as $command) {
            try {
                ($this->save)($command);
                self::fail('Invalid decisions must be refused.');
            } catch (DomainErrors) {
                self::assertTrue($this->em->isOpen());
            }
            self::assertSame([0], $this->indexes());
        }
        ($this->save)(new SaveDecisionCommand($this->document, 'target', 1, [1], []));
        self::assertSame('Production', $this->selections->findByDocumentAndDecisionId($this->document, 'target')[0]->optionLabel);
    }

    /** @return list<int> */
    private function indexes(): array
    {
        $indexes = array_map(static fn ($selection): int => $selection->optionIndex, $this->selections->findByDocumentAndDecisionId($this->document, 'features'));
        sort($indexes);

        return $indexes;
    }
}
