<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Review\Mcp\ToolCallErrorMessages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

final class ToolCallErrorMessagesTest extends TestCase
{
    public function test_a_known_failure_becomes_the_message_an_agent_reads(): void
    {
        $exception = new ToolCallErrorMessages()->forAgent(
            new DomainErrors(['title' => 'review.rename.error.too_long']),
        );

        self::assertSame('title: A document title must be at most 255 characters.', $exception->getMessage());
    }

    public function test_every_failing_argument_is_reported_and_named(): void
    {
        $exception = new ToolCallErrorMessages()->forAgent(new DomainErrors([
            'title' => 'review.rename.error.blank',
            'description' => 'review.revise.error.description_blank',
        ]));

        self::assertSame(
            "title: A document title must not be blank.\ndescription: A description of what changed in this version is required.",
            $exception->getMessage(),
        );
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

        self::assertSame('whatever: '.ToolCallErrorMessages::UNMAPPED, $exception->getMessage());
        self::assertStringNotContainsString('review.some.future.error', $exception->getMessage());
    }

    public function test_a_boundary_rule_reads_in_the_same_shape(): void
    {
        $exception = new ToolCallErrorMessages()->forArgument('markdown', 'The markdown content exceeds the maximum allowed size.');

        self::assertSame('markdown: The markdown content exceeds the maximum allowed size.', $exception->getMessage());
    }

    public function test_the_original_failure_is_kept_as_the_previous_exception(): void
    {
        $errors = new DomainErrors(['title' => 'review.rename.error.blank']);

        self::assertSame($errors, new ToolCallErrorMessages()->forAgent($errors)->getPrevious());
    }

    /**
     * The fallback hides an unmapped key from agents, which also hides it from
     * whoever added it. This is where that omission becomes visible instead.
     */
    public function test_every_domain_failure_the_review_module_throws_has_a_sentence(): void
    {
        $keys = self::domainErrorKeys();
        self::assertGreaterThan(15, \count($keys), 'the scan found too few keys to be reading the module');

        $messages = new ToolCallErrorMessages();
        $unmapped = array_values(array_filter(
            $keys,
            static fn (string $key): bool => 'x: '.ToolCallErrorMessages::UNMAPPED === $messages->forAgent(new DomainErrors(['x' => $key]))->getMessage(),
        ));

        self::assertSame([], $unmapped, 'add a sentence for each of these keys in ToolCallErrorMessages');
    }

    /**
     * Literal keys only. A handler that builds the map in a variable before it
     * throws is invisible here, and no handler in the module does that.
     *
     * @return list<string>
     */
    private static function domainErrorKeys(): array
    {
        $keys = [];
        $files = new Finder()->files()->in(__DIR__.'/../../../../src/Module/Review')->name('*.php');

        foreach ($files as $file) {
            preg_match_all('/DomainErrors\(\[(.*?)\]\)/s', $file->getContents(), $literals);

            foreach ($literals[1] as $literal) {
                preg_match_all("/=>\s*'([^']+)'/", $literal, $matches);
                $keys = [...$keys, ...$matches[1]];
            }
        }

        return array_values(array_unique($keys));
    }
}
