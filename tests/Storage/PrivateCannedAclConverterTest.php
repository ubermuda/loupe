<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\PrivateCannedAclConverter;
use League\Flysystem\Visibility;
use PHPUnit\Framework\TestCase;

final class PrivateCannedAclConverterTest extends TestCase
{
    /**
     * The whole point of this converter: a default AWS S3 bucket rejects every
     * canned ACL except `bucket-owner-full-control`, so the configured value
     * must reach the adapter unchanged rather than being remapped to `private`
     * the way the stock converter does.
     */
    public function test_the_configured_acl_is_used_for_every_visibility(): void
    {
        $converter = new PrivateCannedAclConverter('bucket-owner-full-control');

        self::assertSame('bucket-owner-full-control', $converter->visibilityToAcl(Visibility::PRIVATE));
        self::assertSame('bucket-owner-full-control', $converter->visibilityToAcl(Visibility::PUBLIC));
    }

    public function test_stored_objects_are_always_reported_private(): void
    {
        $converter = new PrivateCannedAclConverter('private');

        self::assertSame(Visibility::PRIVATE, $converter->aclToVisibility([]));
        self::assertSame(Visibility::PRIVATE, $converter->defaultForDirectories());
    }
}
