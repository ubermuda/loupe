<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ProjectPageTest extends WebTestCase
{
    public function test_the_connect_page_offers_an_embed_that_names_the_project(): void
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
        $snippet = $crawler->filter('[data-testid="widget-oauth-snippet"]')->text();
        self::assertStringContainsString('data-project="'.$project->id.'"', $snippet);
        self::assertStringNotContainsString('data-token', $snippet);
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
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/connect');

        self::assertResponseStatusCodeSame(403);
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
