<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Account\EventListener\RequireTermsAcceptanceListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The allowlist matrix. A wrong entry here is an infinite redirect loop or a
 * broken agent, and both are cheaper to pin down against a hand-built request
 * than through the firewall.
 */
final class RequireTermsAcceptanceListenerTest extends TestCase
{
    private const string CURRENT_VERSION = '2026-08-20';

    private const string ACCEPTANCE_PATH = '/account/accept-terms';

    /** @return iterable<string, array{string}> */
    public static function exemptPaths(): iterable
    {
        yield 'mcp endpoint' => ['/mcp'];
        yield 'mcp sub-path' => ['/mcp/messages'];
        yield 'json api' => ['/api/site-review/sites'];
        yield 'logout' => ['/logout'];
        yield 'terms' => ['/terms'];
        yield 'privacy' => ['/privacy'];
        yield 'ai policy' => ['/ai-policy'];
        yield 'profiler' => ['/_profiler/abc123'];
        yield 'install' => ['/install'];
        yield 'install sub-path' => ['/install/admin'];
    }

    /** @return iterable<string, array{string}> */
    public static function gatedPaths(): iterable
    {
        yield 'home' => ['/'];
        yield 'projects' => ['/projects'];
        yield 'account settings' => ['/account'];
        // Not exempted by the /terms entry: that match is exact, so a page
        // merely sharing the prefix stays gated.
        yield 'terms prefix lookalike' => ['/terms-and-conditions'];
    }

    #[DataProvider('exemptPaths')]
    public function test_exempt_paths_are_never_redirected(string $path): void
    {
        self::assertNull($this->handle(Request::create($path)));
    }

    #[DataProvider('gatedPaths')]
    public function test_user_without_accepted_terms_is_redirected(string $path): void
    {
        $response = $this->handle(Request::create($path));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(self::ACCEPTANCE_PATH, $response->getTargetUrl());
    }

    public function test_acceptance_route_is_exempt_so_it_cannot_redirect_to_itself(): void
    {
        $request = Request::create(self::ACCEPTANCE_PATH);
        $request->attributes->set('_route', RequireTermsAcceptanceListener::ACCEPTANCE_ROUTE);

        self::assertNull($this->handle($request));
    }

    public function test_acceptance_route_is_exempt_on_post_too(): void
    {
        $request = Request::create(self::ACCEPTANCE_PATH, Request::METHOD_POST);
        // The submit half is its own route; gating it would make accepting impossible.
        $request->attributes->set('_route', 'app_account_accept_terms_submit');

        self::assertNull($this->handle($request));
    }

    public function test_every_declared_acceptance_route_is_exempt(): void
    {
        foreach (RequireTermsAcceptanceListener::ACCEPTANCE_ROUTES as $route) {
            $request = Request::create(self::ACCEPTANCE_PATH);
            $request->attributes->set('_route', $route);

            self::assertNull($this->handle($request), "{$route} must never be diverted");
        }
    }

    public function test_turbo_stream_requests_are_not_redirected(): void
    {
        $request = Request::create('/projects', server: [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
        ]);

        self::assertNull($this->handle($request));
    }

    public function test_json_requests_are_not_redirected(): void
    {
        $request = Request::create('/projects', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertNull($this->handle($request));
    }

    public function test_browser_navigation_is_redirected(): void
    {
        $request = Request::create('/projects', server: [
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);

        self::assertInstanceOf(RedirectResponse::class, $this->handle($request));
    }

    public function test_anonymous_requests_are_not_redirected(): void
    {
        self::assertNull($this->handle(Request::create('/projects'), termsVersion: null, authenticated: false));
    }

    public function test_user_on_the_current_version_is_not_redirected(): void
    {
        self::assertNull($this->handle(Request::create('/projects'), termsVersion: self::CURRENT_VERSION));
    }

    public function test_user_on_an_older_version_is_reprompted(): void
    {
        self::assertInstanceOf(
            RedirectResponse::class,
            $this->handle(Request::create('/projects'), termsVersion: '2025-01-01'),
        );
    }

    public function test_sub_requests_are_not_redirected(): void
    {
        self::assertNull($this->handle(Request::create('/projects'), requestType: HttpKernelInterface::SUB_REQUEST));
    }

    public function test_a_gated_get_stashes_the_intended_path(): void
    {
        $request = Request::create('/projects?page=2');
        $request->setSession(new Session(new MockArraySessionStorage()));

        self::assertInstanceOf(RedirectResponse::class, $this->handle($request));
        self::assertSame(
            '/projects?page=2',
            $request->getSession()->get(RequireTermsAcceptanceListener::INTENDED_PATH_SESSION_KEY),
        );
    }

    public function test_a_gated_post_stashes_nothing(): void
    {
        $request = Request::create('/projects', Request::METHOD_POST);
        $request->setSession(new Session(new MockArraySessionStorage()));

        self::assertInstanceOf(RedirectResponse::class, $this->handle($request));
        // Replaying a POST URI as a redirect target after acceptance would 405.
        self::assertFalse($request->getSession()->has(RequireTermsAcceptanceListener::INTENDED_PATH_SESSION_KEY));
    }

    private function handle(
        Request $request,
        ?string $termsVersion = null,
        bool $authenticated = true,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): ?Response {
        $tokenStorage = new TokenStorage();

        if ($authenticated) {
            $user = new User(fullName: 'Terms User', email: 'terms@example.com');
            $user->termsVersion = $termsVersion;
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn(self::ACCEPTANCE_PATH);

        $listener = new RequireTermsAcceptanceListener($tokenStorage, $urlGenerator, self::CURRENT_VERSION);

        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $requestType);
        $listener($event);

        return $event->getResponse();
    }
}
