<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\WorkSubject;

use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use PHPUnit\Framework\TestCase;

final class WorkSubjectHandlersTest extends TestCase
{
    public function test_it_finds_the_handler_of_its_subject_type(): void
    {
        $handler = new RecordingWorkSubjectHandler();
        $handlers = new WorkSubjectHandlers([$handler]);

        self::assertSame($handler, $handlers->for(RecordingWorkSubjectHandler::TYPE));
        self::assertTrue($handlers->has(RecordingWorkSubjectHandler::TYPE));
    }

    public function test_a_card_has_no_handler_and_is_always_known(): void
    {
        $handlers = new WorkSubjectHandlers([]);

        self::assertNull($handlers->for(WorkSubject::CARD));
        self::assertTrue($handlers->has(WorkSubject::CARD));
    }

    public function test_a_type_that_no_module_registers_is_unknown(): void
    {
        $handlers = new WorkSubjectHandlers([new RecordingWorkSubjectHandler()]);

        self::assertFalse($handlers->has('report'));
        $this->expectException(\LogicException::class);
        $handlers->for('report');
    }
}
