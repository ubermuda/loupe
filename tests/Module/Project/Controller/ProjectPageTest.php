<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ProjectPageTest extends WebTestCase
{
    public function test_owner_sees_project_page_with_widget_token_creation_button(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'project-page-a@example.com');
        $project = new Project($owner, 'my-app');
        $em->persist($project);
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/connect');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('my-app', (string) $client->getResponse()->getContent());
        // Both token steps offer the same button label, so the button is identified
        // by the form it submits rather than by its text.
        $button = $crawler->filter('form[action$="/widget-token"] button');
        self::assertCount(1, $button);
        self::assertSame('Create a token', trim($button->text()));
    }

    public function test_non_owner_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'project-page-b@example.com');
        $other = $this->user($em, 'project-page-c@example.com');
        $project = new Project($owner, 'not-yours');
        $em->persist($project);
        $em->flush();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_mint_binds_a_widget_token(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'project-page-d@example.com');
        $project = new Project($owner, 'mint-me');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/connect');
        $client->submit($crawler->filter('form[action$="/widget-token"]')->form());

        self::assertResponseRedirects('/projects/'.$projectId.'/connect');
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $fresh);
        self::assertNotNull($fresh->widgetToken);
        self::assertSame(ApiTokenScope::SiteReview, $fresh->widgetToken->scope);

        // The embed snippet must render as escaped text, never as a live <script> tag.
        $client->followRedirect();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('&lt;script', $content);
        self::assertStringNotContainsString('data-token="YOUR_TOKEN"></script>', $content);
    }

    public function test_second_mint_is_rejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'project-page-e@example.com');
        $project = new Project($owner, 'once-only');
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/connect');
        $client->submit($crawler->filter('form[action$="/widget-token"]')->form());
        $em->clear();
        $freshAfterFirstMint = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $freshAfterFirstMint);
        $tokenId = $freshAfterFirstMint->widgetToken?->id;
        self::assertNotNull($tokenId);

        // The page now shows Revoke, not Mint — POST the mint route directly to simulate a race.
        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel: the preceding GET establishes
        // BrowserKit history so the Referer header passes the same-origin check automatically.
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/widget-token', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/projects/'.$projectId.'/connect');
        $em->clear();
        $freshAfterSecondMint = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $freshAfterSecondMint);
        self::assertSame((string) $tokenId, (string) $freshAfterSecondMint->widgetToken?->id, 'token must be unchanged');
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
