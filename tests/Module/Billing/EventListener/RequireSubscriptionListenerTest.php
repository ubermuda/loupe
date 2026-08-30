<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\EventListener\RequireSubscriptionListener;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\PaywallExemptions;
use App\Module\Billing\Service\PaywallGate;
use App\Module\Billing\Service\TrialProvisioner;
use App\Tests\Support\BillingGrants;
use App\Tests\Support\FeatureFlags;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        $user = new User(fullName: 'Walled User', email: 'walled@example.com', password: 'irrelevant');
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

        return new RequireSubscriptionListener($tokenStorage, $gate, new PaywallExemptions(), $urlGenerator);
    }

    private function expiredProfile(?User $user = null): BillingProfile
    {
        return BillingGrants::profileWithTrial($user ?? $this->user(), new \DateTimeImmutable('-1 day'));
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

        $this->listener($user, BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+2 days')))($event);

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
    public function test_machine_endpoints_are_gated_with_a_machine_readable_402(string $path): void
    {
        $user = $this->user();
        $event = $this->event('api_site_review_submit', $path);

        $this->listener($user, $this->expiredProfile($user))($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_PAYMENT_REQUIRED, $response->getStatusCode());
        self::assertStringContainsString('subscription_required', (string) $response->getContent());
    }

    #[DataProvider('machinePaths')]
    public function test_machine_endpoints_pass_while_the_trial_runs(string $path): void
    {
        $user = $this->user();
        $event = $this->event('api_site_review_submit', $path);

        $this->listener($user, BillingGrants::profileWithTrial($user, new \DateTimeImmutable('+2 days')))($event);

        self::assertNull($event->getResponse());
    }

    /**
     * The listener's own honoring of PaywallExemptions, in isolation from
     * routing. Whether every name it lists is still a real route is verified
     * against the router in PaywallRedirectTest.
     */
    public function test_a_route_listed_as_exempt_is_never_paywalled(): void
    {
        $user = $this->user();
        $event = $this->event('app_account_delete_request', '/account/delete');

        $this->listener($user, $this->expiredProfile($user))($event);

        self::assertNull($event->getResponse());
    }

    public function test_a_route_absent_from_the_exemption_list_is_blocked(): void
    {
        $user = $this->user();
        $event = $this->event('app_account_delete_request_not_a_real_route', '/account/delete');

        $this->listener($user, $this->expiredProfile($user))($event);

        self::assertNotNull($event->getResponse());
    }
}
