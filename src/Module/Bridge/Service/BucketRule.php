<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

/** One rule of a project: a call with a signature that matches the pattern counts in the bucket. */
final readonly class BucketRule
{
    public const string NAME_PATTERN = '/^[a-z0-9_-]{1,64}$/D';

    /** The bucket of a call that no rule takes. */
    public const string FALLBACK = 'other';

    public function __construct(
        /** A glob: `*` matches any run of characters and `?` matches one. */
        public string $pattern,
        public string $bucket,
    ) {
        if (1 !== preg_match(self::NAME_PATTERN, $bucket)) {
            throw new \InvalidArgumentException(\sprintf('The bucket name "%s" is not 1 to 64 characters of a-z, 0-9, "_" and "-".', $bucket));
        }
    }

    public function matches(string $signature): bool
    {
        $regex = '/^'.str_replace(['\*', '\?'], ['.*', '.'], preg_quote($this->pattern, '/')).'$/Dsu';

        return 1 === preg_match($regex, $signature);
    }
}
