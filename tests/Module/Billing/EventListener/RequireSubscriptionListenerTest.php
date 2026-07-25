<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\EventListener\RequireSubscriptionListener;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\PaywallGate;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class RequireSubscriptionListenerTest extends TestCase
{
    private const string SUBSCRIBE_URL = '/billing/subscribe';

    private function user(bool $verified = true): User
    {
        $user = new User(username: 'walled', fullName: 'Walled User', email: 'walled@example.com', password: 'irrelevant');
        if ($verified) {
            $user->emailVerifiedAt = new \DateTimeImmutable();
        }

        return $user;
    }

    private function listener(?User $user, BillingProfile $profile): RequireSubscriptionListener
    {
        $token = null;
        if (null !== $user) {
            $token = $this->createStub(TokenInterface::class);
            $token->method('getUser')->willReturn($user);
        }

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $profiles = $this->createStub(BillingProfileRepository::class);
        $profiles->method('findOneByUser')->willReturn($profile);

        $gate = new PaywallGate(
            FeatureFlags::service(['billing.enabled' => true]),
            new TrialProvisioner($profiles, FeatureFlags::service(), $this->createStub(EntityManagerInterface::class)),
        );

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn(self::SUBSCRIBE_URL);

        return new RequireSubscriptionListener($tokenStorage, $gate, $urlGenerator);
    }

    private function expiredProfile(?User $user = null): BillingProfile
    {
        return new BillingProfile($user ?? $this->user(), trialEndsAt: new \DateTimeImmutable('-1 day'));
    }

    private function event(string $route, string $path = '/projects'): RequestEvent
    {
        $request = Request::create($path);
        $request->attributes->set('_route', $route);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function test_blocked_user_is_redirected_to_the_subscribe_page(): void
    {
        $user = $this->user();
        $event = $this->event('app_project_list');

        $this->listener($user, $this->expiredProfile($user))($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(self::SUBSCRIBE_URL, $response->headers->get('Location'));
    }

    public function test_user_within_trial_is_not_redirected(): void
    {
        $user = $this->user();
        $event = $this->event('app_project_list');

        $this->listener($user, new BillingProfile($user, trialEndsAt: new \DateTimeImmutable('+2 days')))($event);

        self::assertNull($event->getResponse());
    }

    public function test_anonymous_requests_are_ignored(): void
    {
        $event = $this->event('app_login');

        $this->listener(null, $this->expiredProfile())($event);

        self::assertNull($event->getResponse());
    }

    public function test_unverified_users_are_left_to_the_verification_listener(): void
    {
        $user = $this->user(verified: false);
        $event = $this->event('app_project_list');

        $this->listener($user, $this->expiredProfile($user))($event);

        self::assertNull($event->getResponse());
    }

    public function test_sub_requests_are_ignored(): void
    {
        $user = $this->user();
        $request = Request::create('/projects');
        $request->attributes->set('_route', 'app_project_list');
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::SUB_REQUEST);

        $this->listener($user, $this->expiredProfile($user))($event);

        self::assertNull($event->getResponse());
    }

    public function test_requests_without_a_route_are_ignored(): void
    {
        $user = $this->user();
        $request = Request::create('/nowhere');
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->listener($user, $this->expiredProfile($user))($event);

        self::assertNull($event->getResponse());
    }

    public function test_admin_area_stays_reachable(): void
    {
        $user = $this->user();
        $event = $this->event('app_admin_dashboard', '/admin');

        $this->listener($user, $this->expiredProfile($user))($event);

        self::assertNull($event->getResponse());
    }

    public function test_feature_flag_admin_routes_stay_reachable(): void
    {
        $user = $this->user();
        $event = $this->event('ubermuda_feature_flags_admin_list', '/admin/feature-flags');

        $this->listener($user, $this->expiredProfile($user))($event);

        self::assertNull($event->getResponse());
    }

    /** @return iterable<string, array{string}> */
    public static function machinePaths(): iterable
    {
        yield 'api' => ['/api/site-review/batches'];
        yield 'mcp' => ['/mcp'];
    }

    #[DataProvider('machinePaths')]
    public function test_machine_endpoints_never_receive_an_html_redirect(string $path): void
    {
        $user = $this->user();
        $event = $this->event('api_site_review_submit', $path);

        $this->listener($user, $this->expiredProfile($user))($event);

        self::assertNull($event->getResponse());
    }

    /**
     * The escape routes: a user who has stopped paying must still be able to
     * pay, log out, and reach the pages that hand back or delete their data.
     * A rename on either side of this list surfaces here.
     *
     * @return iterable<string, array{string}>
     */
    public static function allowedRoutes(): iterable
    {
        foreach (RequireSubscriptionListener::ALLOWED_ROUTES as $route) {
            yield $route => [$route];
        }
    }

    #[DataProvider('allowedRoutes')]
    public function test_allowlisted_routes_are_never_paywalled(string $route): void
    {
        $user = $this->user();
        $event = $this->event($route);

        $this->listener($user, $this->expiredProfile($user))($event);

        self::assertNull($event->getResponse(), $route);
    }
}
