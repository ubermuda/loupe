<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\ShowDeviceConsentCommand;
use App\Module\OAuth\Command\ShowDeviceConsentHandler;
use App\Module\OAuth\Repository\PendingDeviceCodeRepository;
use App\Tests\Support\DeviceCodeRows;
use App\Tests\Support\OAuthScenario;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ShowDeviceConsentHandlerTest extends KernelTestCase
{
    private ShowDeviceConsentHandler $handler;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->handler = new ShowDeviceConsentHandler(
            static::getContainer()->get(PendingDeviceCodeRepository::class),
            new RateLimiterFactory(['id' => 'oauth_device_verification', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'], new InMemoryStorage()),
            new NullLogger(),
        );
        $this->user = new OAuthScenario(static::getContainer())->createUser('guesser@example.com');
        DeviceCodeRows::insert(static::getContainer()->get(Connection::class), 'device-1', 'BCDFGHJK');
    }

    public function test_a_typed_code_is_read_without_case_spaces_or_dashes(): void
    {
        $view = ($this->handler)(new ShowDeviceConsentCommand(' bcdf-ghjk ', $this->user));

        self::assertSame('BCDFGHJK', $view->userCode);
        self::assertSame('BCDF-GHJK', $view->displayUserCode);
        self::assertSame('Loupe CLI', $view->clientName);
    }

    public function test_the_allowance_runs_out_and_then_refuses_even_the_right_code(): void
    {
        self::assertSame('oauth.device.error.invalid_code', $this->errorFor('BBBBBBBB'));
        self::assertSame('oauth.device.error.invalid_code', $this->errorFor('CCCCCCCC'));

        self::assertSame('oauth.device.error.too_many_attempts', $this->errorFor('BCDFGHJK'));
    }

    public function test_the_allowance_is_per_user(): void
    {
        $this->errorFor('BBBBBBBB');
        $this->errorFor('CCCCCCCC');

        $other = new OAuthScenario(static::getContainer())->createUser('other@example.com');
        $view = ($this->handler)(new ShowDeviceConsentCommand('BCDFGHJK', $other));

        self::assertSame('BCDFGHJK', $view->userCode);
    }

    private function errorFor(string $userCode): ?string
    {
        try {
            ($this->handler)(new ShowDeviceConsentCommand($userCode, $this->user));
        } catch (DomainErrors $e) {
            return $e->errors['userCode'] ?? null;
        }

        return null;
    }
}
