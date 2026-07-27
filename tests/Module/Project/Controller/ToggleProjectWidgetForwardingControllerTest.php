<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ToggleProjectWidgetForwardingControllerTest extends WebTestCase
{
    public function test_owner_turns_forwarding_on_from_the_connect_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'fwd-toggle-a@example.com');
        $project = new Project($owner, 'forwarding-toggle');
        [$token] = ApiToken::issue($owner, 'Widget: forwarding-toggle', ApiTokenScope::SiteReview);
        $project->widgetToken = $token;
        $em->persist($token);
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $tokenId = $token->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/connect');
        $client->submitForm('Turn on');

        self::assertResponseRedirects('/projects/'.$projectId.'/connect');
        $em->clear();
        $fresh = $em->find(ApiToken::class, $tokenId);
        self::assertNotNull($fresh);
        self::assertTrue($fresh->forwardsToAgent);
    }

    public function test_non_owner_cannot_turn_forwarding_on(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'fwd-toggle-b@example.com');
        $other = $this->user($em, 'fwd-toggle-c@example.com');
        $project = new Project($owner, 'not-yours-forwarding');
        [$token] = ApiToken::issue($owner, 'Widget: not-yours-forwarding', ApiTokenScope::SiteReview);
        $project->widgetToken = $token;
        $em->persist($token);
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $tokenId = $token->id;

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects');
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/widget-token/forwarding', ['_csrf_token' => 'csrf-token']);

        self::assertResponseStatusCodeSame(403);
        $em->clear();
        $fresh = $em->find(ApiToken::class, $tokenId);
        self::assertNotNull($fresh);
        self::assertFalse($fresh->forwardsToAgent, 'a stranger must not be able to point the owner\'s agent at their own reviews');
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
