<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Workflow\Contract\CardTypeCatalog;
use App\Module\Workflow\Contract\CardTypeDefinition;
use App\Module\Workflow\Contract\CardTypes;
use App\Module\Workflow\Contract\LabelTone;
use Symfony\Component\Uid\Uuid;

/** The card types both shipped workflow templates declare, for a test that builds its handlers by hand. */
final class ShippedCardTypes implements CardTypeCatalog
{
    public static function types(): CardTypes
    {
        return new CardTypes([
            new CardTypeDefinition('feature', 'board.card.type.feature', LabelTone::Lime, false, false),
            new CardTypeDefinition('bug', 'board.card.type.bug', LabelTone::Amber, false, false),
            new CardTypeDefinition('security', 'board.card.type.security', LabelTone::Red, false, false),
            new CardTypeDefinition('tooling', 'board.card.type.tooling', LabelTone::Neutral, false, false),
            new CardTypeDefinition('docs', 'board.card.type.docs', LabelTone::Green, false, false),
            new CardTypeDefinition('idea', 'board.card.type.idea', LabelTone::Purple, false, false),
            new CardTypeDefinition('epic', 'board.card.type.epic', LabelTone::Blue, true, true),
        ], 'feature');
    }

    #[\Override]
    public function forProject(Uuid $projectId): CardTypes
    {
        return self::types();
    }
}
