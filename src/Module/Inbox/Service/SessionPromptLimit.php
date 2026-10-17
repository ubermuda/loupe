<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** The longest prompt that a start-session suggestion, or the owner's edit of it, may carry. An admin-editable flag. */
final readonly class SessionPromptLimit
{
    public const string FLAG = 'inbox.session_prompt_max_length';

    /** The coded fallback and the seeded value. The inbox_ask schema publishes no maxLength, because the flag can raise it. */
    public const int DEFAULT = 2000;

    public function __construct(
        private FeatureFlagService $featureFlags,
    ) {
    }

    public function maxLength(): int
    {
        $length = $this->featureFlags->getIntValue(self::FLAG, self::DEFAULT);

        return $length >= 1 ? $length : self::DEFAULT;
    }
}
