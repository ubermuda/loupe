<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Twig\Components;

use App\Module\Review\Twig\Components\CommentCardComponent;
use PHPUnit\Framework\TestCase;

/**
 * The status pill, which does not always read the status back.
 *
 * An orphaned thread once read "Open" whatever it was, from the days when the
 * card announced its own orphaning. The group heading says that now, so the
 * pill answers the same question here as on every other card.
 */
final class CommentCardComponentTest extends TestCase
{
    public function test_a_resolved_orphan_reads_as_resolved(): void
    {
        $card = new CommentCardComponent();
        $card->orphaned = true;
        $card->quote = 'a passage this version no longer holds';
        $card->status = 'resolved';

        self::assertSame('resolved', $card->statusLabel());
    }

    public function test_a_pending_orphan_still_reads_as_open(): void
    {
        $card = new CommentCardComponent();
        $card->orphaned = true;
        $card->quote = 'a passage this version no longer holds';

        self::assertSame('pending', $card->statusLabel());
    }

    public function test_a_pending_comment_with_no_quote_reads_as_general(): void
    {
        $card = new CommentCardComponent();

        self::assertSame('general', $card->statusLabel());
    }

    public function test_a_pending_strike_reads_as_a_strike(): void
    {
        $card = new CommentCardComponent();
        $card->quote = 'the wording it replaces';
        $card->kind = 'strike';

        self::assertSame('strike', $card->statusLabel());
    }
}
