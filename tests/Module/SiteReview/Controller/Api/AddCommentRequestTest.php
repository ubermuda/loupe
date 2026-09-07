<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller\Api;

use App\Module\SiteReview\Controller\Api\AddCommentRequest;
use PHPUnit\Framework\TestCase;

final class AddCommentRequestTest extends TestCase
{
    public function test_the_context_is_null_unless_the_embed_carried_a_real_one(): void
    {
        self::assertNull(new AddCommentRequest(context: null)->context());
        self::assertNull(new AddCommentRequest(context: '')->context());
        self::assertNull(new AddCommentRequest(context: '   ')->context());
        self::assertSame('card:abc', new AddCommentRequest(context: '  card:abc  ')->context());
    }
}
