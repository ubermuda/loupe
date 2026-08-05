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
