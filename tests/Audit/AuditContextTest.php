<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServicesResetter;

final class AuditContextTest extends KernelTestCase
{
    public function test_reset_clears_the_channel_and_the_ambient_context(): void
    {
        $context = new AuditContext();
        $context->channel = AuditChannel::Cron;
        $context->ambientContext = ['async' => true];

        $context->reset();

        self::assertNull($context->channel);
        self::assertSame([], $context->ambientContext);
    }

    /**
     * A messenger worker outlives its messages, so the reset has to be one the
     * container performs between them — not merely a method that works.
     */
    public function test_the_container_resets_it_between_messages(): void
    {
        self::bootKernel();

        $context = self::getContainer()->get(AuditContext::class);
        self::assertInstanceOf(AuditContext::class, $context);
        $context->channel = AuditChannel::Session;

        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ServicesResetter::class, $resetter);
        $resetter->reset();

        self::assertNull($context->channel);
    }
}
