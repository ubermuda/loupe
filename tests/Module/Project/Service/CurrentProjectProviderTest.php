<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\CurrentProjectProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class CurrentProjectProviderTest extends KernelTestCase
{
    public function test_resolves_owner_project_from_route_param(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'provider-owner@example.com');
        $project = new Project($owner, 'mine');
        $em->persist($project);
        $em->flush();

        $provider = $this->provider($this->requestWith('id', (string) $project->id), $owner);

        self::assertSame($project->id, $provider->current()?->id);
    }

    public function test_returns_null_for_another_users_project(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'provider-owner2@example.com');
        $other = $this->user($em, 'provider-other@example.com');
        $project = new Project($owner, 'not-yours');
        $em->persist($project);
        $em->flush();

        // Same project id in the route, but the authenticated user is someone else.
        $provider = $this->provider($this->requestWith('id', (string) $project->id), $other);

        self::assertNull($provider->current());
    }

    public function test_returns_null_when_no_route_param(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'provider-noparam@example.com');
        $em->flush();

        $provider = $this->provider(new Request(), $owner);

        self::assertNull($provider->current());
    }

    public function test_memoizes_the_resolved_project_across_calls(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'provider-memo@example.com');
        $project = new Project($owner, 'memoized');
        $em->persist($project);
        $em->flush();

        $provider = $this->provider($this->requestWith('id', (string) $project->id), $owner);

        // A second call returns the identical instance from the per-request memo.
        $first = $provider->current();
        self::assertSame($first, $provider->current());
        self::assertSame($project->id, $first?->id);
    }

    public function test_a_page_without_a_project_falls_back_to_the_last_visited_one(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'provider-remember@example.com');
        $project = new Project($owner, 'visited');
        $em->persist($project);
        $em->flush();

        $session = new Session(new MockArraySessionStorage());
        $visit = $this->requestWith('id', (string) $project->id);
        $visit->setSession($session);
        self::assertSame($project->id, $this->provider($visit, $owner)->current()?->id);

        $account = new Request();
        $account->setSession($session);
        $provider = $this->provider($account, $owner);
        self::assertSame($project->id, $provider->currentOrLastVisited()?->id);
        self::assertNull($provider->current(), 'only the sidebar falls back; the page itself has no project');
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['X-Sec-Purpose'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['Sec-Purpose'])]
    public function test_a_prefetch_does_not_change_the_remembered_project(string $header): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'provider-prefetch-'.strtolower($header).'@example.com');
        $visited = new Project($owner, 'visited');
        $hovered = new Project($owner, 'hovered');
        $em->persist($visited);
        $em->persist($hovered);
        $em->flush();

        $session = new Session(new MockArraySessionStorage());
        $visit = $this->requestWith('id', (string) $visited->id);
        $visit->setSession($session);
        $this->provider($visit, $owner)->current();

        // Turbo prefetches a link on hover; that is not a visit.
        $prefetch = $this->requestWith('id', (string) $hovered->id);
        $prefetch->headers->set($header, 'prefetch');
        $prefetch->setSession($session);
        self::assertSame($hovered->id, $this->provider($prefetch, $owner)->current()?->id);

        $account = new Request();
        $account->setSession($session);
        self::assertSame($visited->id, $this->provider($account, $owner)->currentOrLastVisited()?->id);
    }

    public function test_a_remembered_project_of_another_user_is_ignored(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'provider-remember-owner@example.com');
        $other = $this->user($em, 'provider-remember-other@example.com');
        $project = new Project($owner, 'not-yours-either');
        $em->persist($project);
        $em->flush();

        $session = new Session(new MockArraySessionStorage());
        $visit = $this->requestWith('id', (string) $project->id);
        $visit->setSession($session);
        self::assertNotNull($this->provider($visit, $owner)->current());

        $account = new Request();
        $account->setSession($session);
        self::assertNull($this->provider($account, $other)->currentOrLastVisited());
    }

    public function test_an_unresolvable_route_project_does_not_fall_back(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'provider-remember-miss@example.com');
        $project = new Project($owner, 'remembered-but-not-this-page');
        $em->persist($project);
        $em->flush();

        $session = new Session(new MockArraySessionStorage());
        $visit = $this->requestWith('id', (string) $project->id);
        $visit->setSession($session);
        self::assertNotNull($this->provider($visit, $owner)->current());

        $miss = $this->requestWith('id', 'no-such-project');
        $miss->setSession($session);
        self::assertNull($this->provider($miss, $owner)->currentOrLastVisited());
    }

    private function provider(Request $request, User $user): CurrentProjectProvider
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return new CurrentProjectProvider(
            $requestStack,
            static::getContainer()->get(ProjectRepository::class),
            $tokenStorage,
        );
    }

    private function requestWith(string $key, string $value): Request
    {
        $request = new Request();
        $request->attributes->set($key, $value);

        return $request;
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }
}
