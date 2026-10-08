<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\LabelTone;
use App\Module\Board\Service\BoardParentTypeProvider;
use App\Module\Board\Service\CardTypeCatalog;
use App\Module\Board\Service\CardTypeDefinition;
use App\Module\Board\Service\CardTypes;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\ParentType\ParentType;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BoardParentTypeProviderTest extends TestCase
{
    public function test_it_lists_the_types_with_children_in_template_order_with_translated_labels(): void
    {
        $catalog = $this->createStub(CardTypeCatalog::class);
        $catalog->method('forProject')->willReturn(new CardTypes([
            new CardTypeDefinition('bug', 'board.card.type.bug', LabelTone::Red, false, false),
            new CardTypeDefinition('initiative', 'board.card.type.initiative', LabelTone::Blue, true, true),
            new CardTypeDefinition('epic', 'board.card.type.epic', LabelTone::Lime, true, true),
        ], 'bug'));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => 'T:'.$id);

        $types = new BoardParentTypeProvider($catalog, $translator)->forProject($this->createStub(Project::class));

        self::assertEquals([
            new ParentType('initiative', 'T:board.card.type.initiative'),
            new ParentType('epic', 'T:board.card.type.epic'),
        ], $types);
    }

    public function test_it_lists_nothing_when_no_type_has_children(): void
    {
        $catalog = $this->createStub(CardTypeCatalog::class);
        $catalog->method('forProject')->willReturn(new CardTypes([
            new CardTypeDefinition('bug', 'Bug', LabelTone::Red, false, false),
        ], 'bug'));

        $types = new BoardParentTypeProvider($catalog, $this->createStub(TranslatorInterface::class))->forProject($this->createStub(Project::class));

        self::assertSame([], $types);
    }
}
