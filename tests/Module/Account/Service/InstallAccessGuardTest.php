<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\InstallAccessGuard;
use App\Module\Account\Service\InstallationState;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InstallAccessGuardTest extends TestCase
{
    private function requestWithSession(string $queryString = ''): Request
    {
        $request = Request::create('/install'.('' !== $queryString ? '?'.$queryString : ''));
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function installationState(int $userCount): InstallationState
    {
        /** @var UserRepository&Stub $users */
        $users = $this->createStub(UserRepository::class);
        $users->method('countHumans')->willReturn($userCount);

        return new InstallationState($users);
    }

    public function test_closed_installation_404s_regardless_of_token(): void
    {
        $guard = new InstallAccessGuard($this->installationState(1), '', 'test');

        $this->expectException(NotFoundHttpException::class);
        $guard->ensureAccessible($this->requestWithSession());
    }

    public function test_open_installation_with_no_configured_token_is_accessible(): void
    {
        $guard = new InstallAccessGuard($this->installationState(0), '', 'test');

        $guard->ensureAccessible($this->requestWithSession());
        $this->addToAssertionCount(1); // no exception thrown
    }

    public function test_configured_token_without_query_token_404s(): void
    {
        $guard = new InstallAccessGuard($this->installationState(0), 'secret-token', 'test');

        $this->expectException(NotFoundHttpException::class);
        $guard->ensureAccessible($this->requestWithSession());
    }

    public function test_configured_token_with_wrong_query_token_404s(): void
    {
        $guard = new InstallAccessGuard($this->installationState(0), 'secret-token', 'test');

        $this->expectException(NotFoundHttpException::class);
        $guard->ensureAccessible($this->requestWithSession('token=wrong'));
    }

    public function test_configured_token_with_array_query_token_404s_instead_of_500ing(): void
    {
        $guard = new InstallAccessGuard($this->installationState(0), 'secret-token', 'test');

        $this->expectException(NotFoundHttpException::class);
        $guard->ensureAccessible($this->requestWithSession('token[]=x'));
    }

    public function test_configured_token_with_correct_query_token_is_accessible_and_remembered(): void
    {
        $guard = new InstallAccessGuard($this->installationState(0), 'secret-token', 'test');
        $request = $this->requestWithSession('token=secret-token');

        $guard->ensureAccessible($request);
        $this->addToAssertionCount(1); // no exception thrown

        // A later step's request (no token in the URL) reuses the same
        // session and must not be re-challenged.
        $laterRequest = Request::create('/install/admin');
        $laterRequest->setSession($request->getSession());
        $guard->ensureAccessible($laterRequest);
        $this->addToAssertionCount(1);
    }

    public function test_production_without_a_configured_token_404s_rather_than_opening(): void
    {
        // A forgotten INSTALL_TOKEN in production must not leave an
        // admin-minting endpoint publicly reachable.
        $guard = new InstallAccessGuard($this->installationState(0), '', 'prod');

        $this->expectException(NotFoundHttpException::class);
        $guard->ensureAccessible($this->requestWithSession());
    }

    public function test_production_with_a_configured_token_still_admits_the_correct_token(): void
    {
        $guard = new InstallAccessGuard($this->installationState(0), 'secret-token', 'prod');

        $guard->ensureAccessible($this->requestWithSession('token=secret-token'));
        $this->addToAssertionCount(1); // no exception thrown
    }
}
