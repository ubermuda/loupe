<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RenameDocumentControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }

    private function document(EntityManagerInterface $em, User $owner, string $title): Document
    {
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: $title);
        $document->addVersion('# '.$title, '<h1>'.$title.'</h1>');
        $em->persist($document);

        return $document;
    }

    public function test_owner_renames_a_document_and_lands_back_on_the_page_they_came_from(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-rename', 'alice-rename@example.com');
        $document = $this->document($em, $alice, 'Post 5 — draft');
        $em->flush();
        $projectId = (string) $document->project->id;
        $documentId = (string) $document->id;
        $em->clear();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/rename?page=2&archived=1');

        self::assertResponseIsSuccessful();

        $client->submitForm('Save title', ['rename_document_form[title]' => 'Post 5 — Rate limiting']);

        self::assertResponseRedirects('/projects/'.$projectId.'/documents?page=2&archived=1');

        $em->clear();
        $fresh = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertSame('Post 5 — Rate limiting', $fresh->title);
        self::assertCount(1, $fresh->versions);
    }

    public function test_a_blank_title_re_renders_the_form_with_an_error(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-rename-blank', 'alice-rename-blank@example.com');
        $document = $this->document($em, $alice, 'Keep me');
        $em->flush();
        $projectId = (string) $document->project->id;
        $documentId = (string) $document->id;
        $em->clear();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/rename');
        $client->submitForm('Save title', ['rename_document_form[title]' => '']);

        self::assertResponseStatusCodeSame(422);

        $em->clear();
        $fresh = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $fresh);
        self::assertSame('Keep me', $fresh->title);
    }

    public function test_a_non_owner_cannot_reach_the_rename_form(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-rename', 'owner-rename@example.com');
        $other = $this->createUser($em, 'other-rename', 'other-rename@example.com');
        $document = $this->document($em, $owner, 'Private');
        $em->flush();
        $projectId = (string) $document->project->id;
        $documentId = (string) $document->id;
        $em->clear();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/rename');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_document_id_from_another_project_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice-rename-cross', 'alice-rename-cross@example.com');
        $document = $this->document($em, $alice, 'In project A');
        $otherProject = new Project($alice, 'p-'.uniqid());
        $em->persist($otherProject);
        $em->flush();
        $otherProjectId = (string) $otherProject->id;
        $documentId = (string) $document->id;
        $em->clear();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, '/projects/'.$otherProjectId.'/documents/'.$documentId.'/rename');

        self::assertResponseStatusCodeSame(404);
    }
}
