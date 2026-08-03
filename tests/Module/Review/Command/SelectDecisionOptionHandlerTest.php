<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Command\SelectDecisionOptionCommand;
use App\Module\Review\Command\SelectDecisionOptionHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DecisionSelectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SelectDecisionOptionHandlerTest extends KernelTestCase
{
    private const string MARKDOWN = <<<'MD'
        Pick a target.

        <!-- decision: deploy-target -->

        - [ ] Ship to staging first
        - [ ] Ship straight to production

        <!-- /decision -->
        MD;

    private EntityManagerInterface $em;
    private SelectDecisionOptionHandler $selectDecisionOption;
    private DecisionSelectionRepository $selections;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = self::getContainer()->get(SelectDecisionOptionHandler::class);
        self::assertInstanceOf(SelectDecisionOptionHandler::class, $handler);
        $this->selectDecisionOption = $handler;

        $selections = self::getContainer()->get(DecisionSelectionRepository::class);
        self::assertInstanceOf(DecisionSelectionRepository::class, $selections);
        $this->selections = $selections;

        $owner = new User(
            username: 'decider-'.uniqid(),
            fullName: 'Decider',
            email: 'decider-'.uniqid().'@example.com',
            password: 'hashed',
        );
        $this->em->persist($owner);
        $this->project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($this->project);
        $this->em->flush();
    }

    public function test_records_the_chosen_option_against_the_decision_identifier(): void
    {
        $document = $this->createDocument(self::MARKDOWN);

        $selection = ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1));

        self::assertSame('deploy-target', $selection->decisionId);
        self::assertSame(1, $selection->optionIndex);
        self::assertSame('Ship straight to production', $selection->optionLabel);
        self::assertSame(1, $selection->versionNumber);
    }

    /**
     * The unique (document, decision) index makes a second insert a 500 rather
     * than an update, and answering is a click — a reviewer changing their mind
     * is the normal case, not an edge one.
     *
     * The concurrent form of this is not expressible here: dama wraps the test
     * in one connection's transaction, so two overlapping DB transactions cannot
     * exist. The row lock in the handler is what covers that; this is the
     * sequential regression guard.
     */
    public function test_answering_again_updates_the_one_row_rather_than_inserting_a_second(): void
    {
        $document = $this->createDocument(self::MARKDOWN);

        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 0));
        $second = ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1));

        self::assertCount(1, $this->selections->findBy(['document' => $document]));
        self::assertSame(1, $second->optionIndex);
        self::assertSame('Ship straight to production', $second->optionLabel);
    }

    /**
     * The whole reason the identifier keys the answer rather than the quoted
     * text: a revision responding to feedback about a decision is exactly the
     * revision that rewords it.
     */
    public function test_an_answer_survives_a_revision_that_rewords_its_block(): void
    {
        $document = $this->createDocument(self::MARKDOWN);
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 1));

        $revise = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand(
            $document,
            str_replace(
                ['Pick a target.', 'Ship straight to production'],
                ['Choose where this lands.', 'Deploy directly to production'],
                self::MARKDOWN,
            ),
            'Reworded the deploy decision.',
        ));

        $selection = $this->selections->findOneByDocumentAndDecisionId($document, 'deploy-target');
        self::assertNotNull($selection);
        self::assertSame(1, $selection->optionIndex);
        // The label is the one that was agreed to, not the rewritten one.
        self::assertSame('Ship straight to production', $selection->optionLabel);
    }

    public function test_a_decision_the_current_version_does_not_offer_is_rejected(): void
    {
        $document = $this->createDocument(self::MARKDOWN);

        $this->expectException(DomainErrors::class);
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'no-such-decision', 0));
    }

    public function test_an_option_index_outside_the_block_is_rejected(): void
    {
        $document = $this->createDocument(self::MARKDOWN);

        $this->expectException(DomainErrors::class);
        ($this->selectDecisionOption)(new SelectDecisionOptionCommand($document, 'deploy-target', 7));
    }

    private function createDocument(string $markdown): Document
    {
        $create = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);

        return $create(new CreateDocumentCommand($this->project, 'Deploy plan '.uniqid(), $markdown));
    }
}
