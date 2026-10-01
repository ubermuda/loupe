<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\EventListener;

use App\Module\Account\Event\UserRegistered;
use App\Module\Billing\EventListener\GrantBetaCompOnUserRegistered;
use App\Module\Billing\EventListener\ProvisionTrialOnUserRegistered;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A throw from the trial listener stops dispatch, so the beta comp must be
 * granted first or a tester would lose it.
 */
final class UserRegisteredListenerOrderTest extends KernelTestCase
{
    public function test_the_beta_comp_listener_runs_before_the_trial_listener(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);

        $classes = array_map(
            static fn (mixed $listener): string => \is_array($listener) && \is_object($listener[0])
                ? $listener[0]::class
                : (\is_object($listener) ? $listener::class : ''),
            $dispatcher->getListeners(UserRegistered::class),
        );

        $beta = array_search(GrantBetaCompOnUserRegistered::class, $classes, true);
        $trial = array_search(ProvisionTrialOnUserRegistered::class, $classes, true);

        self::assertIsInt($beta, 'the beta comp listener is registered');
        self::assertIsInt($trial, 'the trial listener is registered');
        self::assertLessThan($trial, $beta);
    }
}
