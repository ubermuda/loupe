<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\ValueObject;

use App\Module\Bridge\ValueObject\WorkSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WorkSubjectTest extends TestCase
{
    public function test_a_card_subject_names_the_card(): void
    {
        $cardId = Uuid::v7();
        $subject = WorkSubject::card($cardId);

        self::assertSame('card', $subject->type);
        self::assertSame($cardId, $subject->id);
        self::assertTrue($subject->isCard());
        self::assertFalse(new WorkSubject('analysis', $cardId)->isCard());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['Card'];
        yield 'a leading digit' => ['1card'];
        yield 'a space' => ['an analysis'];
        yield 'too long' => [str_repeat('a', 41)];
        yield 'a trailing newline' => ["card\n"];
    }

    #[DataProvider('invalidTypes')]
    public function test_a_type_that_is_no_code_is_refused(string $type): void
    {
        $this->expectException(\LogicException::class);

        new WorkSubject($type, Uuid::v7());
    }
}
