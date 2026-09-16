<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Repository\DocumentRepository;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class CreateDocumentControllerTest extends WebTestCase
{
    public function test_creation_opens_a_persisted_draft(): void
    {
        $client = static::createClient();
        $project = $this->createProject();
        $projectId = (string) $project->id;
        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Create document')->form([
            'create_document_form[title]' => 'A new draft',
            'create_document_form[markdown]' => '# Scope\n\nDraft content.',
        ]));
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'A new draft');
        self::assertSelectorTextContains('.lp-review-doc__byline', 'Draft');
        self::assertSelectorExists('button[data-action="click->modal#open"]');
        self::assertSelectorNotExists('.lp-verdict-bar');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $document = static::getContainer()->get(DocumentRepository::class)->findOneBy(['title' => 'A new draft']);
        self::assertInstanceOf(Document::class, $document);
        self::assertSame($projectId, (string) $document->project->id);
        self::assertSame(DocumentStatus::Draft, $document->status);
        self::assertSame(1, $document->currentVersion()->versionNumber);
        self::assertSame('# Scope\n\nDraft content.', $document->currentVersion()->markdownSource);
    }

    public function test_invalid_creation_reopens_the_form_and_keeps_its_markdown(): void
    {
        $client = static::createClient();
        $project = $this->createProject();
        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');
        $client->submit($crawler->selectButton('Create document')->form([
            'create_document_form[title]' => '',
            'create_document_form[markdown]' => '# Keep this draft',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-modal-reopen-value="true"]');
        self::assertSelectorTextContains('textarea[name="create_document_form[markdown]"]', '# Keep this draft');
        self::assertSelectorExists('.lp-field-errors li');
        self::assertSame(0, static::getContainer()->get(DocumentRepository::class)->count(['project' => $project->id]));
    }

    public function test_creation_rejects_another_owners_project(): void
    {
        $client = static::createClient();
        $project = $this->createProject();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $other = new User(fullName: 'Other Owner', email: 'other-create@example.test', password: 'unused');
        $other->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($other, static::getContainer());
        $em->persist($other);
        $em->flush();
        $client->loginUser($other);
        $client->request(Request::METHOD_POST, '/projects/'.$project->id.'/documents/create');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, static::getContainer()->get(DocumentRepository::class)->count(['project' => $project->id]));
    }

    private function createProject(): Project
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = new User(fullName: 'Document Owner', email: 'create-document@example.test', password: 'unused');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $project = new Project($owner, 'create-documents');
        $em->persist($owner);
        $em->persist($project);
        $em->flush();

        return $project;
    }
}
