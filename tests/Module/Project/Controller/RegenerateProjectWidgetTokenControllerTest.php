<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RegenerateProjectWidgetTokenControllerTest extends WebTestCase
{
    public function test_owner_regenerates_widget_token_and_sees_the_new_raw_token_once(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'widget-regen-a@example.com');
        $project = new Project($owner, 'regen-widget');

        [$oldToken, $oldRaw] = ApiToken::issue($owner, 'Widget: regen-widget', ApiTokenScope::SiteReview);
        $project->widgetToken = $oldToken;
        $em->persist($oldToken);
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $oldTokenId = $oldToken->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/connect');
        $client->submitForm('Regenerate');

        self::assertResponseRedirects('/projects/'.$projectId.'/connect');
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $fresh);
        self::assertNotNull($fresh->widgetToken);
        self::assertSame(ApiTokenScope::SiteReview, $fresh->widgetToken->scope);
        self::assertNotSame((string) $oldTokenId, (string) $fresh->widgetToken->id, 'a fresh token must replace the old one');
        self::assertNull($em->find(ApiToken::class, $oldTokenId), 'the previous token must be revoked');
        self::assertNull($fresh->mcpToken, 'regenerating the widget token must not touch the MCP binding');

        $client->followRedirect();
        $content = (string) $client->getResponse()->getContent();
        if (1 !== preg_match('/[0-9a-f]{64}/', $content, $matches)) {
            self::fail('the regenerated raw token must be shown');
        }
        self::assertTrue($fresh->widgetToken->matches($matches[0]), 'flashed raw token must match the new stored hash');
        self::assertFalse($fresh->widgetToken->matches($oldRaw), 'the old secret must no longer be valid');
    }

    public function test_non_owner_cannot_regenerate(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'widget-regen-b@example.com');
        $other = $this->user($em, 'widget-regen-c@example.com');
        $project = new Project($owner, 'not-yours-widget-regen');

        [$token] = ApiToken::issue($owner, 'Widget: not-yours-widget-regen', ApiTokenScope::SiteReview);
        $project->widgetToken = $token;
        $em->persist($token);
        $em->persist($project);
        $em->flush();
        $projectId = $project->id;
        $tokenId = $token->id;

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects');
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/widget-token/regenerate', ['_csrf_token' => 'csrf-token']);

        self::assertResponseStatusCodeSame(403);
        $em->clear();
        $fresh = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $fresh);
        self::assertSame((string) $tokenId, (string) $fresh->widgetToken?->id, 'the token must be unchanged');
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
