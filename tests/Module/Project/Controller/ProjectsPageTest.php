<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ProjectsPageTest extends WebTestCase
{
    public function test_lists_only_own_projects(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'projects-a@example.com');
        $other = $this->user($em, 'projects-b@example.com');
        $em->persist(new Project($owner, 'mine'));
        $em->persist(new Project($other, 'theirs'));
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/site-review/sites');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-project-id]'));
        self::assertSame('mine', trim($crawler->filter('[data-project-id] .bp-doc-row__title')->text()));
    }

    public function test_create_project_persists_and_redirects(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'projects-c@example.com');
        $em->flush();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/site-review/sites');
        $client->submitForm('Add project', [
            'create_project_form[name]' => 'my-app',
            'create_project_form[domain]' => 'my-app.example.com',
        ]);

        self::assertResponseRedirects('/site-review/sites');
        $project = static::getContainer()->get(ProjectRepository::class)->findOneByOwnerAndName($owner, 'my-app');
        self::assertNotNull($project);
        self::assertSame('my-app.example.com', $project->domain);
        self::assertNull($project->widgetToken);
        self::assertNull($project->mcpToken);
    }

    public function test_create_project_without_domain_is_allowed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'projects-g@example.com');
        $em->flush();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/site-review/sites');
        $client->submitForm('Add project', ['create_project_form[name]' => 'domainless']);

        self::assertResponseRedirects('/site-review/sites');
        $project = static::getContainer()->get(ProjectRepository::class)->findOneByOwnerAndName($owner, 'domainless');
        self::assertNotNull($project);
        self::assertNull($project->domain);
    }

    public function test_duplicate_name_for_same_owner_is_rejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'projects-d@example.com');
        $em->persist(new Project($owner, 'dup'));
        $em->flush();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/site-review/sites');
        $client->submitForm('Add project', ['create_project_form[name]' => 'dup']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, static::getContainer()->get(ProjectRepository::class)->findBy(['name' => 'dup']));
    }

    public function test_same_name_for_different_owner_is_allowed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $a = $this->user($em, 'projects-e@example.com');
        $b = $this->user($em, 'projects-f@example.com');
        $em->persist(new Project($a, 'shared-name'));
        $em->flush();

        $client->loginUser($b);
        $client->request(Request::METHOD_GET, '/site-review/sites');
        $client->submitForm('Add project', ['create_project_form[name]' => 'shared-name']);

        self::assertResponseRedirects('/site-review/sites');
        self::assertNotNull(static::getContainer()->get(ProjectRepository::class)->findOneByOwnerAndName($b, 'shared-name'));
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }
}
