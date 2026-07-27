<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * Pure pagination arithmetic mirroring Ubermuda\AdminBundle\Listing\ListPagePagination,
 * kept separate so non-admin callers don't inherit its hardcoded "admin.*" log event name.
 */
final class PageList
{
    /**
     * @return list<int|null> integer page numbers, null for ellipsis slots
     */
    public static function build(int $page, int $totalPages): array
    {
        $pages = [];
        $previous = 0;
        for ($candidate = 1; $candidate <= $totalPages; ++$candidate) {
            if (1 === $candidate
                || $candidate === $totalPages
                || ($candidate >= $page - 2 && $candidate <= $page + 2)
            ) {
                if ($previous > 0 && $candidate > $previous + 1) {
                    $pages[] = null;
                }
                $pages[] = $candidate;
                $previous = $candidate;
            }
        }

        return $pages;
    }

    /**
     * Returns the clamped page number when the requested page is out of range, or
     * null when it's already valid.
     */
    public static function clampedPage(int $page, int $total, int $perPage): ?int
    {
        if ($total <= 0 || $perPage <= 0) {
            return null;
        }

        $totalPages = max(1, (int) ceil($total / $perPage));

        return $page <= $totalPages ? null : $totalPages;
    }
}
