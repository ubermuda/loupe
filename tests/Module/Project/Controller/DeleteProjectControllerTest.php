<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\SiteReview\Entity\SiteReview;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeleteProjectControllerTest extends WebTestCase
{
    public function test_owner_can_delete_with_exact_name(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'delete-owner-a@example.com');
        $project = new Project($owner, 'my-project');
        $em->persist($project);
        $em->persist(new Document($owner, $project, 'a doc'));
        $em->persist(new SiteReview($project));
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action$="/delete"]')->form([
            'delete_project_form[confirmName]' => 'my-project',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/projects');
        $em->clear();
        self::assertNull($em->find(Project::class, $projectId));
        $conn = $em->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM documents WHERE project_id = :id', ['id' => (string) $projectId]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM site_review_reviews WHERE project_id = :id', ['id' => (string) $projectId]));

        $client->followRedirect();
        self::assertSelectorTextContains('.lp-flash', 'permanently deleted');
    }

    public function test_wrong_name_re_renders_with_a_field_error(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'delete-owner-b@example.com');
        $project = new Project($owner, 'my-project');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/edit');
        $form = $crawler->filter('form[action$="/delete"]')->form([
            'delete_project_form[confirmName]' => 'not-the-name',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[action$="/delete"] .lp-field-errors', 'does not match');
        $em->clear();
        self::assertNotNull($em->find(Project::class, $projectId));
    }

    public function test_whitespace_padded_name_is_rejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'delete-owner-c@example.com');
        $project = new Project($owner, 'my-project');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/edit');
        $form = $crawler->filter('form[action$="/delete"]')->form([
            'delete_project_form[confirmName]' => ' my-project ',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        $em->clear();
        self::assertNotNull($em->find(Project::class, $projectId));
    }

    public function test_blank_name_re_renders_with_the_notblank_error(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'delete-owner-d@example.com');
        $project = new Project($owner, 'my-project');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/edit');
        $form = $crawler->filter('form[action$="/delete"]')->form([
            'delete_project_form[confirmName]' => '',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextNotContains('form[action$="/delete"] .lp-field-errors', 'does not match');
        self::assertSelectorTextContains('form[action$="/delete"] .lp-field-errors', 'should not be blank');
        $em->clear();
        self::assertNotNull($em->find(Project::class, $projectId));
    }

    public function test_non_owner_gets_403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'delete-owner-e@example.com');
        $other = $this->user($em, 'delete-other-e@example.com');
        $project = new Project($owner, 'my-project');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        $client->loginUser($other);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/delete', [
            'delete_project_form' => ['confirmName' => 'my-project'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_anonymous_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'delete-owner-f@example.com');
        $project = new Project($owner, 'my-project');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/delete', [
            'delete_project_form' => ['confirmName' => 'my-project'],
        ]);

        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertIsString($location);
        self::assertStringContainsString('/login', $location);
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
