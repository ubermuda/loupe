<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Service\AnchorService;
use App\Module\Review\Service\ReanchoringService;
use App\Module\Review\ValueObject\Anchor;
use PHPUnit\Framework\TestCase;

final class ReanchoringServiceTest extends TestCase
{
    public function test_remaps_reply_parent_to_the_copied_parent_on_new_version(): void
    {
        $user = new User(username: 'r2', fullName: 'R2', email: 'r2@example.com');
        $doc = new Document(owner: $user, project: new Project($user, 'p'), title: 'Doc');
        $v1 = $doc->addVersion('use JWTs and rate limiting', 'use JWTs and rate limiting');

        $parent = new Comment($v1, $user, 'why JWT?', new Anchor('JWTs', 'use ', ' and', 4));
        $reply = new Comment($v1, $user, 'good question', new Anchor('rate limiting', 'and ', '', 13), $parent);

        $v2 = $doc->addVersion('use JWTs and rate limiting', 'use JWTs and rate limiting');
        $summary = new ReanchoringService(new AnchorService())->reanchor([$parent, $reply], $v2);

        self::assertSame(2, $summary['carried']);
        self::assertSame(0, $summary['orphaned']);

        $copies = $v2->comments->toArray();
        self::assertCount(2, $copies);

        $copiedParents = array_filter($copies, static fn (Comment $c) => 'why JWT?' === $c->body);
        $copiedParent = reset($copiedParents);
        self::assertInstanceOf(Comment::class, $copiedParent);
        self::assertNull($copiedParent->parent, 'Copied parent should have no parent itself');

        $copiedReplies = array_filter($copies, static fn (Comment $c) => 'good question' === $c->body);
        $copiedReply = reset($copiedReplies);
        self::assertInstanceOf(Comment::class, $copiedReply);

        // The copied reply's parent must be the NEW copied parent (on v2), not the original v1 parent
        self::assertSame($copiedParent, $copiedReply->parent, 'Copied reply must point to the copied parent on v2');
        self::assertNotSame($parent, $copiedReply->parent, 'Copied reply must NOT point to the original v1 parent');
    }

    public function test_carries_surviving_comment_and_orphans_the_rest(): void
    {
        $user = new User(username: 'r', fullName: 'R', email: 'r@example.com');
        $doc = new Document(owner: $user, project: new Project($user, 'p'), title: 'Doc');
        $v1 = $doc->addVersion('use JWTs and rate limiting', 'use JWTs and rate limiting');
        $kept = new Comment($v1, $user, 'why JWT?', new Anchor('JWTs', 'use ', ' and', 4));
        $gone = new Comment($v1, $user, 'limit?', new Anchor('rate limiting', 'and ', '', 13));

        $v2 = $doc->addVersion('use JWTs only', 'use JWTs only');
        $summary = new ReanchoringService(new AnchorService())->reanchor([$kept, $gone], $v2);

        self::assertSame(1, $summary['carried']);
        self::assertSame(1, $summary['orphaned']);

        // The copies are attached to the new version as fresh Comment objects.
        $copies = $v2->comments->toArray();
        self::assertCount(2, $copies);

        $carriedCopies = array_filter($copies, static fn (Comment $c) => 'why JWT?' === $c->body);
        $carried = reset($carriedCopies);
        self::assertInstanceOf(Comment::class, $carried);
        self::assertFalse($carried->orphaned);
        self::assertSame('JWTs', $carried->anchor->quote);
        // The carried anchor is rebuilt at the quote's offset in the NEW text ("use JWTs only").
        self::assertSame(strpos('use JWTs only', 'JWTs'), $carried->anchor->offsetHint);

        $orphanCopies = array_filter($copies, static fn (Comment $c) => 'limit?' === $c->body);
        $orphan = reset($orphanCopies);
        self::assertInstanceOf(Comment::class, $orphan);
        self::assertTrue($orphan->orphaned);
        self::assertSame('rate limiting', $orphan->anchor->quote);
    }

    public function test_carries_a_multibyte_quote_without_stretching_it(): void
    {
        // The quote length handed to AnchorService::create() must be a character
        // count. A byte count (3× larger for these characters) makes the rebuilt
        // anchor swallow the rest of the sentence.
        $user = new User(username: 'r3', fullName: 'R3', email: 'r3@example.com');
        $doc = new Document(owner: $user, project: new Project($user, 'p'), title: 'Doc');
        $v1 = $doc->addVersion('設計方針の草案。', '設計方針の草案。');
        $comment = new Comment($v1, $user, 'なぜ？', new Anchor('設計方針', '', 'の草案。', 0));

        $v2 = $doc->addVersion('前書き。設計方針を再検討する。', '前書き。設計方針を再検討する。');
        $summary = new ReanchoringService(new AnchorService())->reanchor([$comment], $v2);

        self::assertSame(1, $summary['carried']);

        $copies = $v2->comments->toArray();
        self::assertCount(1, $copies);
        self::assertSame('設計方針', $copies[0]->anchor->quote);
        self::assertSame(4, $copies[0]->anchor->offsetHint);
    }
}
