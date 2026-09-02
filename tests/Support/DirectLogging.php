<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Monolog\Attribute\WithMonologChannel;
use PHPUnit\Framework\Assert;
use Psr\Log\LoggerInterface;

/**
 * A migrated call site records through the Auditor, whose Monolog sink emits
 * the log line for it. A logger kept beside that would emit the same operation
 * twice, and no assertion on the record itself can see it.
 */
final class DirectLogging
{
    /** @param class-string $class */
    public static function assertRemovedFrom(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        Assert::assertSame(
            [],
            $reflection->getAttributes(WithMonologChannel::class),
            sprintf('%s must not carry a Monolog channel attribute: the audit category routes the record now', $class),
        );

        $constructor = $reflection->getConstructor();
        Assert::assertNotNull($constructor, sprintf('%s must have a constructor', $class));

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $isLogger = $type instanceof \ReflectionNamedType
                && !$type->isBuiltin()
                && is_a($type->getName(), LoggerInterface::class, true);

            Assert::assertFalse($isLogger, sprintf('%s must not inject a logger beside the Auditor', $class));
        }
    }

    /**
     * For a class whose other call sites are diagnostics, so it keeps its
     * logger and the reflection check above cannot apply. The Auditor must
     * hold the operation, and the log stream must get it through the sink
     * alone. Without the first check, a call site that records nothing passes.
     */
    public static function assertOperationNotLoggedBy(
        RecordingAuditor $audit,
        RecordingLogger $logger,
        string $operation,
    ): void {
        Assert::assertContains(
            $operation,
            $audit->operations(),
            sprintf('"%s" must be recorded through the Auditor for this check to mean anything', $operation),
        );

        $messages = array_map(
            static fn (array $entry): string => $entry['message'],
            $logger->records,
        );

        Assert::assertNotContains(
            $operation,
            $messages,
            sprintf('"%s" is recorded through the Auditor: a direct log call beside it emits the operation twice', $operation),
        );
    }
}
