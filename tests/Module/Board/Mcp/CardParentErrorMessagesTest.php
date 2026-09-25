<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Mcp\BoardToolErrorMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CardParentErrorMessagesTest extends KernelTestCase
{
    /** @return iterable<string, array{string}> */
    public static function keys(): iterable
    {
        foreach (['parent_unknown', 'parent_not_epic', 'epic_cannot_have_parent', 'parent_card_cannot_be_epic', 'epic_type_locked', 'epic_delete_has_children'] as $name) {
            yield $name => ['board.card.error.'.$name];
        }
    }

    #[DataProvider('keys')]
    public function test_each_parent_refusal_reads_as_a_sentence_for_a_person_and_an_agent(string $key): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        self::assertNotSame($key, $translator->trans($key));
        self::assertStringNotContainsString(
            BoardToolErrorMessages::UNMAPPED,
            new BoardToolErrorMessages()->forAgent(new DomainErrors(['parent' => $key]))->getMessage(),
        );
    }
}
