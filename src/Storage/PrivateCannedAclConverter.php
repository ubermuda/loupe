<?php

declare(strict_types=1);

namespace App\Storage;

use AsyncAws\S3\ValueObject\Grant;
use League\Flysystem\AsyncAwsS3\VisibilityConverter;
use League\Flysystem\Visibility;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Makes the canned ACL that Flysystem's S3 adapter sends on every write
 * configurable, because no single value works on every S3-compatible provider.
 *
 * The adapter always sends an ACL header and offers no way to send none
 * (https://github.com/thephpleague/flysystem/issues/1874). AWS S3 buckets
 * created since 2023 default to "Bucket owner enforced", which rejects every
 * canned ACL except `bucket-owner-full-control` with a 400
 * `AccessControlListNotSupported` — while MinIO and DigitalOcean Spaces accept
 * only `private`. So the value has to come from configuration.
 *
 * Everything this app stores in a bucket is private, so the mapping is
 * one-way and constant: no caller asks for public visibility, and nothing
 * reads an object's ACL back.
 */
final readonly class PrivateCannedAclConverter implements VisibilityConverter
{
    public function __construct(
        #[Autowire(env: 'EXPORT_STORAGE_ACL')]
        private string $acl,
    ) {
    }

    #[\Override]
    public function visibilityToAcl(string $visibility): string
    {
        return $this->acl;
    }

    /** @param Grant[] $grants */
    #[\Override]
    public function aclToVisibility(array $grants): string
    {
        return Visibility::PRIVATE;
    }

    #[\Override]
    public function defaultForDirectories(): string
    {
        return Visibility::PRIVATE;
    }
}
