<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Service\DisplayNameDeriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DisplayNameDeriverTest extends TestCase
{
    /**
     * The same table drives the browser-side check in
     * e2e/tests/account/display-name-suggestion.spec.ts, which exercises the
     * JavaScript twin of these rules. Add a row here and add it there too.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function derivations(): iterable
    {
        yield 'a dotted local part becomes two words' => ['jane.doe@example.com', 'Jane Doe'];
        yield 'hyphens are kept and capitalized on both sides' => ['jean-luc_picard@x.com', 'Jean-Luc Picard'];
        yield 'a plus tag is dropped' => ['jane+loupe@example.com', 'Jane'];
        yield 'shouting is normalized' => ['JANE.DOE@EXAMPLE.COM', 'Jane Doe'];
        yield 'a single word is capitalized' => ['jane@example.com', 'Jane'];
        yield 'digits are kept' => ['jsmith2@x.com', 'Jsmith2'];
        yield 'single letters are words too' => ['a.b@x.com', 'A B'];
        yield 'a hyphenated first name survives a dot' => ['mary-jane.watson@x.com', 'Mary-Jane Watson'];
    }

    #[DataProvider('derivations')]
    public function test_it_derives_a_display_name_from_an_email(string $email, string $expected): void
    {
        self::assertSame($expected, new DisplayNameDeriver()->derive($email));
    }

    public function test_a_local_part_of_only_separators_falls_back_to_the_local_part(): void
    {
        self::assertSame('...', new DisplayNameDeriver()->derive('...@x.com'));
    }

    public function test_an_empty_local_part_falls_back_to_the_whole_address(): void
    {
        self::assertSame('@x.com', new DisplayNameDeriver()->derive('@x.com'));
    }

    public function test_it_is_multibyte_safe(): void
    {
        self::assertSame('Élodie Renard', new DisplayNameDeriver()->derive('élodie.renard@x.com'));
    }

    public function test_it_truncates_to_the_column_length(): void
    {
        $derived = new DisplayNameDeriver()->derive(str_repeat('é', 200).'@x.com');

        self::assertSame(150, mb_strlen($derived));
    }
}
