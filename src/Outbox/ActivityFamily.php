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
    case PullRequest = 'pull-request';
    case Worker = 'worker';
    case Rule = 'rule';
    case SiteReview = 'site-review';

    /** Older document events carry a `review.*` type. */
    private const string LEGACY_DOCUMENT_PREFIX = 'review';

    public static function fromType(string $type): ?self
    {
        $dot = strpos($type, '.');
        if (false === $dot) {
            return null;
        }

        $prefix = substr($type, 0, $dot);
        if (self::LEGACY_DOCUMENT_PREFIX === $prefix) {
            return self::Document;
        }

        foreach (self::cases() as $family) {
            if ($family->prefix() === $prefix) {
                return $family;
            }
        }

        return null;
    }

    /** @return non-empty-string */
    public function prefix(): string
    {
        return match ($this) {
            self::Board => 'board',
            self::Document => 'document',
            self::Inbox => 'inbox',
            self::Project => 'project',
            self::PullRequest => 'pull_request',
            self::Worker => 'worker',
            self::Rule => 'rule',
            self::SiteReview => 'site_review',
        };
    }

    /** @return non-empty-list<non-empty-string> */
    public function typePrefixes(): array
    {
        return self::Document === $this
            ? [$this->prefix().'.', self::LEGACY_DOCUMENT_PREFIX.'.']
            : [$this->prefix().'.'];
    }

    public function translationKey(): string
    {
        return 'activity.family.'.$this->prefix();
    }
}
