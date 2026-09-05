<?php

declare(strict_types=1);

namespace App\Module\SiteReview;

/**
 * Freehand drawing over the page, as part of a site-review comment.
 *
 * The flag gates the two places a drawing is *made*: the widget's Draw control
 * and the API's acceptance of strokes on a new comment. It gates nothing that
 * reads one. A comment saved while the flag was on keeps its strokes, still
 * reports them to the widget, still renders them on the page, and still exports
 * them, because turning a flag off must not hide data a reviewer already left.
 */
final class SiteReviewDrawing
{
    public const string FLAG = 'site_review.drawing.enabled';

    /**
     * Read at every call site as the default, so a seeded instance and one that
     * never ran the seeder behave the same. `docker/prod/release.sh` runs
     * migrations only, so an instance installed before this flag existed has no
     * row for it and falls back to this value.
     */
    public const bool DEFAULT = true;

    private function __construct()
    {
    }
}
