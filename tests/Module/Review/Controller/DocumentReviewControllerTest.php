<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class DocumentReviewControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            username: $username,
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    private function project(EntityManagerInterface $em, User $owner): Project
    {
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);

        return $project;
    }

    public function test_owner_sees_review_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner1', 'owner1@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'My Review Doc');
        $doc->addVersion('# Hello', '<h1>Hello</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'My Review Doc');
        self::assertSelectorExists('.bp-review-sidebar');
    }

    public function test_non_owner_gets_403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner2', 'owner2@example.com');
        $other = $this->createUser($em, 'other2', 'other2@example.com');

        $project = $this->project($em, $owner);
        $doc = new Document(owner: $owner, project: $project, title: 'Owner Only Doc');
        $doc->addVersion('# Private', '<h1>Private</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_document_under_the_wrong_project_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner3', 'owner3@example.com');
        $projectA = $this->project($em, $owner);
        $projectB = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $projectA, title: 'Belongs To A');
        $doc->addVersion('# A', '<h1>A</h1>');
        $em->persist($doc);
        $em->flush();

        $projectBId = (string) $projectB->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectBId.'/documents/'.$id.'/review');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $client = static::createClient();
        $client->request(
            Request::METHOD_GET,
            '/projects/00000000-0000-0000-0000-000000000000/documents/00000000-0000-0000-0000-000000000000/review',
        );

        self::assertResponseRedirects('/login');
    }
}
