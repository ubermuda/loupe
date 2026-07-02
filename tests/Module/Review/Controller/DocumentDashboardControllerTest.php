<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class DocumentDashboardControllerTest extends WebTestCase
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

    public function test_owner_sees_their_projects_documents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice', 'alice@example.com');
        $bob = $this->createUser($em, 'bob', 'bob@example.com');

        $aliceProject = $this->project($em, $alice);
        $aliceDoc = new Document(owner: $alice, project: $aliceProject, title: 'Alice Draft');
        $bobDoc = new Document(owner: $bob, project: $this->project($em, $bob), title: 'Bob Secret');

        $em->persist($aliceDoc);
        $em->persist($bobDoc);
        $em->flush();
        $aliceProjectId = (string) $aliceProject->id;
        $em->clear();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, '/projects/'.$aliceProjectId.'/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Alice Draft');
        self::assertStringNotContainsString('Bob Secret', (string) $client->getResponse()->getContent());
    }

    public function test_a_project_shows_only_its_own_documents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alice = $this->createUser($em, 'alice2', 'alice2@example.com');
        $bob = $this->createUser($em, 'bob2', 'bob2@example.com');

        $aliceProject = $this->project($em, $alice);
        $bobDoc = new Document(owner: $bob, project: $this->project($em, $bob), title: 'Bob Private');
        $em->persist($bobDoc);
        $em->flush();
        $aliceProjectId = (string) $aliceProject->id;
        $em->clear();

        $client->loginUser($alice);
        $client->request(Request::METHOD_GET, '/projects/'.$aliceProjectId.'/documents');

        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertStringNotContainsString('Bob Private', (string) $content);
    }

    public function test_non_owner_cannot_view_a_projects_dashboard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-dash', 'owner-dash@example.com');
        $other = $this->createUser($em, 'other-dash', 'other-dash@example.com');
        $project = $this->project($em, $owner);
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/projects/00000000-0000-0000-0000-000000000000/documents');

        self::assertResponseRedirects('/login');
    }
}
