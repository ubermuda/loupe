<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Monolog\Attribute\WithMonologChannel;
use PHPUnit\Framework\Assert;
use Psr\Log\LoggerInterface;

/**
 * A migrated call site records through the Auditor, whose Monolog sink emits
 * the log line for it. A logger kept beside that emits the same operation
 * twice, and no assertion on the record itself can see it. The exception is a
 * deliberate split, where the record stays clean and the log line carries the
 * detail the trail must not hold; assertDiagnosticsLoggedBeside() pins that.
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

    /**
     * The deliberate split: the record answers what happened and to whom, and
     * the log line beside it carries the diagnostics the trail must not hold,
     * under the same operation name. Both halves are asserted, so neither a
     * leaked key nor a dropped log line passes.
     *
     * @param list<string> $diagnosticKeys
     */
    public static function assertDiagnosticsLoggedBeside(
        RecordingAuditor $audit,
        RecordingLogger $logger,
        string $operation,
        array $diagnosticKeys,
    ): void {
        Assert::assertContains(
            $operation,
            $audit->operations(),
            sprintf('"%s" must be recorded through the Auditor for this check to mean anything', $operation),
        );

        foreach ($audit->records($operation) as $event) {
            foreach ($diagnosticKeys as $key) {
                Assert::assertArrayNotHasKey(
                    $key,
                    $event->context,
                    sprintf('"%s" belongs in the log line beside the "%s" record, not in the record', $key, $operation),
                );
            }
        }

        $matching = array_values(array_filter(
            $logger->records,
            static fn (array $entry): bool => $entry['message'] === $operation,
        ));

        Assert::assertCount(
            1,
            $matching,
            sprintf('expected exactly one "%s" log line beside the record', $operation),
        );

        foreach ($diagnosticKeys as $key) {
            Assert::assertArrayHasKey(
                $key,
                $matching[0]['context'],
                sprintf('"%s" is the detail the "%s" record drops: the log line must carry it', $key, $operation),
            );
        }
    }
}
