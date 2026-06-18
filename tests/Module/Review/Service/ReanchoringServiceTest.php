<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Service\AnchorService;
use App\Module\Review\Service\ReanchoringService;
use App\Module\Review\ValueObject\Anchor;
use PHPUnit\Framework\TestCase;

final class ReanchoringServiceTest extends TestCase
{
    public function test_carries_surviving_comment_and_orphans_the_rest(): void
    {
        $user = new User(username: 'r', fullName: 'R', email: 'r@example.com');
        $doc = new Document($user, 'Doc');
        $v1 = $doc->addVersion('use JWTs and rate limiting', 'use JWTs and rate limiting');
        $kept = new Comment($v1, $user, 'why JWT?', new Anchor('JWTs', 'use ', ' and', 4));
        $gone = new Comment($v1, $user, 'limit?', new Anchor('rate limiting', 'and ', '', 13));

        $v2 = $doc->addVersion('use JWTs only', 'use JWTs only');
        $summary = new ReanchoringService(new AnchorService())->reanchor([$kept, $gone], $v2);

        self::assertSame(1, $summary['carried']);
        self::assertSame(1, $summary['orphaned']);
    }
}
