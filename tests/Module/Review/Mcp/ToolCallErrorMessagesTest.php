<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Mcp\ToolCallErrorMessages;
use PHPUnit\Framework\TestCase;

final class ToolCallErrorMessagesTest extends TestCase
{
    public function test_a_known_failure_becomes_the_message_an_agent_reads(): void
    {
        $exception = new ToolCallErrorMessages()->forAgent(
            new DomainErrors(['title' => 'review.rename.error.too_long']),
        );

        self::assertSame('A document title must be at most 255 characters.', $exception->getMessage());
    }

    /**
     * The point of the fallback: a domain failure added later must not put a
     * translation key in front of a caller that has no locale and no UI.
     */
    public function test_an_unmapped_failure_falls_back_instead_of_leaking_its_key(): void
    {
        $exception = new ToolCallErrorMessages()->forAgent(
            new DomainErrors(['whatever' => 'review.some.future.error']),
        );

        self::assertSame('The request was rejected. The error has been logged.', $exception->getMessage());
        self::assertStringNotContainsString('review.some.future.error', $exception->getMessage());
    }

    public function test_the_original_failure_is_kept_as_the_previous_exception(): void
    {
        $errors = new DomainErrors(['title' => 'review.rename.error.blank']);

        self::assertSame($errors, new ToolCallErrorMessages()->forAgent($errors)->getPrevious());
    }
}
