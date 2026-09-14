<?php

declare(strict_types=1);

namespace App\Mercure;

/**
 * Live updates in the browser, over Mercure: the subscriber cookie, the page
 * element assets/lib/mercure.js reads, its renewal, and every publish a page
 * listens for. Agent push has its own flag, so each switches on its own.
 *
 * The flag also carries an environment prerequisite, so an instance with no
 * configured hub reads it as off whatever is stored.
 */
final class LiveUpdates
{
    public const string FLAG = 'live_updates.enabled';

    private function __construct()
    {
    }
}
