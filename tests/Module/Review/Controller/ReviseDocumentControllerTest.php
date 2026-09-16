<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\DocumentVersion;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ReviseDocumentControllerTest extends WebTestCase
{
    public function test_revision_creates_an_immutable_version(): void
    {
        $client = static::createClient();
        $document = $this->createDocument();
        $documentId = (string) $document->id;
        $url = '/projects/'.$document->project->id.'/documents/'.$documentId.'/review';
        $client->loginUser($document->owner);
        $crawler = $client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Save new version')->form([
            'revise_document_form[title]' => 'Revised title',
            'revise_document_form[markdown]' => '# Revised text',
            'revise_document_form[description]' => 'Clarify the scope.',
        ]));
        self::assertResponseRedirects($url);
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Revised title');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertSame(DocumentStatus::InReview, $fresh->status);
        self::assertSame(2, $fresh->currentVersion()->versionNumber);
        self::assertSame('# Revised text', $fresh->currentVersion()->markdownSource);
        self::assertSame('Clarify the scope.', $fresh->currentVersion()->description);
        $original = $fresh->versions->first();
        self::assertInstanceOf(DocumentVersion::class, $original);
        self::assertSame('# Original', $original->markdownSource);
    }

    public function test_stale_revision_keeps_the_draft_without_creating_a_version(): void
    {
        $client = static::createClient();
        $document = $this->createDocument();
        $documentId = (string) $document->id;
        $client->loginUser($document->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$document->project->id.'/documents/'.$documentId.'/review');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Save new version')->form([
            'revise_document_form[markdown]' => '# My draft',
            'revise_document_form[description]' => 'My changes.',
        ]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $current = $em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $current);
        $current->addVersion('# Concurrent revision', '<h1>Concurrent revision</h1>');
        $em->flush();
        $em->clear();
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.lp-field-errors', 'The document has a newer version.');
        self::assertSelectorTextContains('textarea[name="revise_document_form[markdown]"]', '# My draft');
        self::assertSelectorExists('[data-modal-reopen-value="true"]');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertSame(2, $fresh->currentVersion()->versionNumber);
        self::assertSame('# Concurrent revision', $fresh->currentVersion()->markdownSource);
    }

    public function test_missing_revision_fields_do_not_create_a_version(): void
    {
        $client = static::createClient();
        $document = $this->createDocument();
        $documentId = (string) $document->id;
        $client->loginUser($document->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$document->project->id.'/documents/'.$documentId.'/review');
        $client->submit($crawler->selectButton('Save new version')->form([
            'revise_document_form[title]' => '',
            'revise_document_form[markdown]' => '',
            'revise_document_form[description]' => '',
            'revise_document_form[versionNumber]' => '',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.lp-dialog--document .lp-field-errors li');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(Document::class, $documentId);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertSame(1, $fresh->currentVersion()->versionNumber);
    }

    public function test_another_owner_cannot_revise_the_document(): void
    {
        $client = static::createClient();
        $document = $this->createDocument();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $other = new User(fullName: 'Other Owner', email: 'other-revision@example.test', password: 'unused');
        $other->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($other, static::getContainer());
        $em->persist($other);
        $em->flush();
        $client->loginUser($other);
        $client->request(Request::METHOD_POST, '/projects/'.$document->project->id.'/documents/'.$document->id.'/revise');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_revision_route_rejects_another_project(): void
    {
        $client = static::createClient();
        $document = $this->createDocument();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $otherProject = new Project($document->owner, 'other-project');
        $em->persist($otherProject);
        $em->flush();
        $client->loginUser($document->owner);
        $client->request(Request::METHOD_POST, '/projects/'.$otherProject->id.'/documents/'.$document->id.'/revise');
        self::assertResponseStatusCodeSame(404);
    }

    private function createDocument(): Document
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = new User(fullName: 'Revision Owner', email: 'revision@example.test', password: 'unused');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $project = new Project($owner, 'revision-project');
        $document = new Document(owner: $owner, project: $project, title: 'Original title');
        $document->addVersion('# Original', '<h1>Original</h1>');
        $em->persist($owner);
        $em->persist($project);
        $em->persist($document);
        $em->flush();

        return $document;
    }
}
