<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SetDocumentReferencesCommand;
use App\Module\Review\Command\SetDocumentReferencesHandler;
use App\Module\Review\Entity\Document;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class SetDocumentReferencesHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SetDocumentReferencesHandler $handler;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $handler = self::getContainer()->get(SetDocumentReferencesHandler::class);
        self::assertInstanceOf(SetDocumentReferencesHandler::class, $handler);
        $this->handler = $handler;
    }

    private function project(string $slug): Project
    {
        $user = new User(fullName: 'U', email: $slug.'@example.com', password: 'hashed');
        $this->em->persist($user);
        $project = new Project($user, 'p-'.$slug);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    private function documentIn(Project $project, string $title): Document
    {
        $document = new Document($project->owner, $project, $title);
        $document->addVersion('# hi', '<h1>hi</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    /** @return list<string> the titles of the documents $document points at */
    private function targetsOf(Document $document): array
    {
        $titles = [];
        foreach ($document->references as $reference) {
            $titles[] = $reference->title;
        }

        return $titles;
    }

    public function test_the_set_is_replaced_wholesale_and_an_empty_list_clears_it(): void
    {
        $project = $this->project('refs-replace');
        $source = $this->documentIn($project, 'source');
        $first = $this->documentIn($project, 'first');
        $second = $this->documentIn($project, 'second');

        $applied = ($this->handler)(new SetDocumentReferencesCommand($source, [$first, $second]));
        self::assertSame(['first', 'second'], array_map(static fn (Document $d): string => $d->title, $applied));
        self::assertSame(['first', 'second'], $this->targetsOf($source));

        ($this->handler)(new SetDocumentReferencesCommand($source, [$second]));
        self::assertSame(['second'], $this->targetsOf($source));

        ($this->handler)(new SetDocumentReferencesCommand($source, []));
        self::assertSame([], $this->targetsOf($source));
    }

    public function test_the_written_links_survive_a_reload_and_are_navigable_from_the_target(): void
    {
        $project = $this->project('refs-reload');
        $source = $this->documentIn($project, 'source');
        $target = $this->documentIn($project, 'target');

        ($this->handler)(new SetDocumentReferencesCommand($source, [$target]));
        $targetId = $target->id;
        $this->em->clear();

        // Reloaded rather than read in place: $referencedBy is derived from the
        // owning side and is only populated when the document is loaded, so the
        // incoming link is invisible to the request that wrote it.
        $reloadedTarget = $this->em->find(Document::class, $targetId);
        self::assertInstanceOf(Document::class, $reloadedTarget);
        self::assertSame(['source'], array_map(
            static fn (Document $d): string => $d->title,
            $reloadedTarget->referencedBy->toArray(),
        ));
    }

    public function test_the_same_target_twice_is_collapsed_into_one_link(): void
    {
        $project = $this->project('refs-duplicate');
        $source = $this->documentIn($project, 'source');
        $target = $this->documentIn($project, 'target');

        $applied = ($this->handler)(new SetDocumentReferencesCommand($source, [$target, $target]));

        self::assertCount(1, $applied);
        self::assertCount(1, $this->em->getConnection()->fetchFirstColumn(
            'SELECT target_document_id FROM document_references WHERE source_document_id = :id',
            ['id' => (string) $source->id],
        ));
    }

    public function test_a_document_cannot_reference_itself_and_the_current_set_is_untouched(): void
    {
        $project = $this->project('refs-self');
        $source = $this->documentIn($project, 'source');
        $target = $this->documentIn($project, 'target');
        ($this->handler)(new SetDocumentReferencesCommand($source, [$target]));

        try {
            ($this->handler)(new SetDocumentReferencesCommand($source, [$target, $source]));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertSame(['references' => 'review.references.error.self_reference'], $e->errors);
        }

        self::assertSame(['target'], $this->targetsOf($source));
    }

    public function test_a_document_in_another_project_is_rejected_and_the_current_set_is_untouched(): void
    {
        $project = $this->project('refs-scope-a');
        $source = $this->documentIn($project, 'source');
        $target = $this->documentIn($project, 'target');
        ($this->handler)(new SetDocumentReferencesCommand($source, [$target]));

        $foreign = $this->documentIn($this->project('refs-scope-b'), 'foreign');

        try {
            ($this->handler)(new SetDocumentReferencesCommand($source, [$foreign]));
            self::fail('expected DomainErrors');
        } catch (DomainErrors $e) {
            self::assertSame(['references' => 'review.references.error.other_project'], $e->errors);
        }

        self::assertSame(['target'], $this->targetsOf($source));
    }

    public function test_a_reference_set_is_recorded_on_the_domain_channel(): void
    {
        $project = $this->project('refs-audit');
        $source = $this->documentIn($project, 'Source');
        $target = $this->documentIn($project, 'Target');

        ($this->handler)(new SetDocumentReferencesCommand($source, [$target]));

        $record = $this->audit->record('review.document_references_updated');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('document', $record->subject->type);
        self::assertSame((string) $source->id, $record->subject->id);
        self::assertSame([
            'documentId' => (string) $source->id,
            'projectId' => (string) $project->id,
            'referenceCount' => 1,
        ], $record->context);

        self::assertSame(['review.document_references_updated'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_clearing_the_set_is_recorded_as_a_count_of_zero(): void
    {
        $project = $this->project('refs-audit-clear');
        $source = $this->documentIn($project, 'Source');
        $target = $this->documentIn($project, 'Target');
        ($this->handler)(new SetDocumentReferencesCommand($source, [$target]));
        $this->audit->forget();

        ($this->handler)(new SetDocumentReferencesCommand($source, []));

        self::assertSame(0, $this->audit->record('review.document_references_updated')->context['referenceCount']);
    }
}
