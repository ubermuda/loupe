<?php

declare(strict_types=1);

namespace App\Outbox;

/** The family of an outbox event type, read from the prefix before its first dot. */
enum ActivityFamily: string
{
    case Board = 'board';
    case Document = 'document';
    case Inbox = 'inbox';
    case Project = 'project';
    case PullRequest = 'pull_request'; // @phpstan-ignore enum.notKebabCase (the value is the event type prefix)
    case Worker = 'worker';
    case Rule = 'rule';
    case SiteReview = 'site_review'; // @phpstan-ignore enum.notKebabCase (the value is the event type prefix)

    /** Older document events carry a `review.*` type. */
    private const string LEGACY_DOCUMENT_PREFIX = 'review';

    public static function fromType(string $type): ?self
    {
        $dot = strpos($type, '.');
        if (false === $dot) {
            return null;
        }

        $prefix = substr($type, 0, $dot);

        return self::LEGACY_DOCUMENT_PREFIX === $prefix ? self::Document : self::tryFrom($prefix);
    }

    /** @return non-empty-list<non-empty-string> */
    public function typePrefixes(): array
    {
        return self::Document === $this
            ? [$this->value.'.', self::LEGACY_DOCUMENT_PREFIX.'.']
            : [$this->value.'.'];
    }

    public function translationKey(): string
    {
        return 'activity.family.'.$this->value;
    }
}
