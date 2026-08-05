<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Account\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Guards the deny-by-default rule on ^/api in config/packages/security.yaml.
 *
 * The api firewall matches every /api path but only /api/site-review names a
 * scope, and every API-token user also carries ROLE_USER — so an /api endpoint
 * added without its own access_control rule would be authorized by the ^/
 * catch-all for any scoped token. These tests assert the decision itself rather
 * than the YAML, so they keep holding however the rule is expressed.
 */
final class ApiAccessControlTest extends KernelTestCase
{
    /**
     * A token carrying every role the app issues — the strongest caller that
     * could reach an unguarded endpoint.
     */
    private const array ALL_ROLES = ['ROLE_USER', 'ROLE_API_SITE_REVIEW', 'ROLE_API_MCP', 'ROLE_ADMIN'];

    public function test_an_api_path_with_no_scope_rule_is_denied_to_every_role(): void
    {
        self::assertFalse($this->decide('/api/some-future-endpoint', self::ALL_ROLES));
    }

    public function test_the_deny_rule_does_not_shadow_the_site_review_scope(): void
    {
        self::assertTrue($this->decide('/api/site-review/review/submit', ['ROLE_USER', 'ROLE_API_SITE_REVIEW']));
    }

    public function test_a_site_review_token_stays_denied_outside_its_own_prefix(): void
    {
        self::assertFalse($this->decide('/api/other', ['ROLE_USER', 'ROLE_API_SITE_REVIEW']));
    }

    /**
     * Runs a path through the configured access_control map and returns the
     * authorization decision for a caller holding exactly $roles.
     *
     * @param list<string> $roles
     */
    private function decide(string $path, array $roles): bool
    {
        // security.access_map has no interface alias in the container, so it is
        // fetched by service id; the decision manager does, and is fetched by
        // interface for the type PHPStan needs.
        $container = static::getContainer();
        $accessMap = $container->get('security.access_map');
        self::assertInstanceOf(AccessMapInterface::class, $accessMap);
        $decisionManager = $container->get(AccessDecisionManagerInterface::class);

        $request = Request::create('https://loupe.test'.$path);
        [$attributes] = $accessMap->getPatterns($request);
        self::assertNotNull($attributes, sprintf('No access_control rule matches %s.', $path));

        $user = new User(fullName: 'Caller', email: 'caller@example.com', password: 'x');

        return $decisionManager->decide(
            new PostAuthenticationToken($user, 'api', $roles),
            $attributes,
            $request,
        );
    }
}
