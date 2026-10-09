<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Inbox\Service\RunOutputText;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class RunOutputTextTest extends TestCase
{
    #[TestWith(['STAGE RESULT: blocked: flake counter not on main [reason: needs-person]', 'flake counter not on main'])]
    #[TestWith(['STAGE RESULT: waiting https://example.test/pull/1', 'waiting https://example.test/pull/1'])]
    #[TestWith(["  Needs the API key.\nSecond line  ", "Needs the API key.\nSecond line"])]
    #[TestWith(['', ''])]
    public function test_it_removes_the_protocol_text(string $output, string $expected): void
    {
        self::assertSame($expected, RunOutputText::clean($output));
    }
}
