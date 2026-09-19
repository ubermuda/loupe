<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class UpdateProjectAllowedOriginsControllerTest extends WebTestCase
{
    public function test_owner_saves_the_allowed_sites_from_the_connect_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'origins-ctl-a@example.com');
        $project = new Project($owner, 'origins-page');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/connect');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="widget-oauth-snippet"]');
        self::assertSelectorTextContains('[data-testid="widget-oauth-snippet"]', 'data-project="'.$projectId.'"');

        $client->submitForm('Save sites', [
            'update_project_allowed_origins_form[origins]' => "https://Shop.Example.com/\nhttp://localhost:3000",
        ]);

        self::assertResponseRedirects('/projects/'.$projectId.'/connect#site-review-widget');
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertNotNull($fresh);
        self::assertSame(['https://shop.example.com', 'http://localhost:3000'], $fresh->allowedOrigins);

        $client->followRedirect();
        self::assertSelectorTextContains('textarea[name="update_project_allowed_origins_form[origins]"]', 'https://shop.example.com');
    }

    public function test_an_invalid_line_re_renders_the_page_with_the_error(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'origins-ctl-b@example.com');
        $project = new Project($owner, 'origins-invalid');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/connect');
        $client->submitForm('Save sites', [
            'update_project_allowed_origins_form[origins]' => 'https://shop.example.com/checkout',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#site-review-widget', 'https://shop.example.com/checkout');
        self::assertSelectorTextContains('#site-review-widget .lp-field-errors', 'origin');
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertNotNull($fresh);
        self::assertSame([], $fresh->allowedOrigins);
    }

    public function test_non_owner_cannot_change_the_allowed_sites(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'origins-ctl-c@example.com');
        $other = $this->user($em, 'origins-ctl-d@example.com');
        $project = new Project($owner, 'origins-not-yours');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;

        $client->loginUser($other);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/allowed-origins', [
            'update_project_allowed_origins_form' => ['origins' => 'https://evil.example'],
        ]);

        self::assertResponseStatusCodeSame(403);
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertNotNull($fresh);
        self::assertSame([], $fresh->allowedOrigins);
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }
}
