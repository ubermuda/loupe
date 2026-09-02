<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Diagnostics;

use App\Module\Account\Diagnostics\TrustedProxyCheck;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/**
 * Request holds the trusted list in a static, so a kernel booted by an earlier
 * test in the same process leaves PRIVATE_SUBNETS behind. Each test sets its
 * own list and setUp/tearDown put the process back as it was.
 */
final class TrustedProxyCheckTest extends TestCase
{
    /** @var list<string> */
    private array $trustedProxies;

    /** @var int<0, 63> */
    private int $trustedHeaderSet;

    #[\Override]
    protected function setUp(): void
    {
        $this->trustedProxies = array_values(Request::getTrustedProxies());

        // Untouched, Symfony holds -1 here, which is no header mask at all. It
        // pairs with an empty proxy list, where the mask is never read.
        $headerSet = Request::getTrustedHeaderSet();
        $this->trustedHeaderSet = $headerSet >= 0 && $headerSet <= 63 ? $headerSet : Request::HEADER_X_FORWARDED_FOR;
    }

    #[\Override]
    protected function tearDown(): void
    {
        Request::setTrustedProxies($this->trustedProxies, $this->trustedHeaderSet);
    }

    public function test_a_console_run_has_no_request_to_judge(): void
    {
        self::assertNull((new TrustedProxyCheck(new RequestStack()))());
    }

    public function test_an_instance_with_no_proxy_in_front_of_it_is_not_reported(): void
    {
        $check = $this->checkFor(remoteAddress: '198.51.100.7', forwardedFor: null);

        self::assertNull($check());
    }

    public function test_a_trusted_proxy_resolving_the_caller_passes(): void
    {
        $diagnostic = ($this->checkFor(remoteAddress: '10.0.0.1', forwardedFor: '203.0.113.9'))();

        self::assertNotNull($diagnostic);
        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('trusted_proxies', $diagnostic->key);
        self::assertSame('messages', $diagnostic->domain);
    }

    public function test_a_proxy_on_a_public_address_is_a_warning(): void
    {
        $diagnostic = ($this->checkFor(remoteAddress: '198.51.100.7', forwardedFor: '203.0.113.9'))();

        self::assertNotNull($diagnostic);
        self::assertSame(DiagnosticState::Warning, $diagnostic->state);
        self::assertSame('account.system_status.trusted_proxies.ignored', $diagnostic->detail);
    }

    /**
     * The hop the app talks to is the platform's private ingress, which the
     * default trusts, and the public CDN behind it is not. isFromTrustedProxy()
     * answers true here while the resolved address is still the CDN's.
     */
    public function test_a_trusted_first_hop_does_not_excuse_an_untrusted_second(): void
    {
        $request = self::requestFrom('10.0.0.1', '203.0.113.9, 198.51.100.7');
        Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);

        self::assertTrue($request->isFromTrustedProxy());
        self::assertSame('198.51.100.7', $request->getClientIp());

        $diagnostic = (new TrustedProxyCheck(self::stackFor($request)))();

        self::assertNotNull($diagnostic);
        self::assertSame(DiagnosticState::Warning, $diagnostic->state);
    }

    private function checkFor(string $remoteAddress, ?string $forwardedFor): TrustedProxyCheck
    {
        $request = self::requestFrom($remoteAddress, $forwardedFor);
        Request::setTrustedProxies(['10.0.0.0/8'], Request::HEADER_X_FORWARDED_FOR);

        return new TrustedProxyCheck(self::stackFor($request));
    }

    private static function requestFrom(string $remoteAddress, ?string $forwardedFor): Request
    {
        $server = ['REMOTE_ADDR' => $remoteAddress];
        if (null !== $forwardedFor) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }

        return Request::create('/admin/status', server: $server);
    }

    private static function stackFor(Request $request): RequestStack
    {
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
