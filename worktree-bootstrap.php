<?php

declare(strict_types=1);

/**
 * Shared bootstrap for tools running inside a git worktree (e.g. PHPStan).
 *
 * When running from a git worktree with a rsynced vendor/, Composer's
 * autoload_psr4 map points App\ and App\Tests\ to the main repo's src/ and
 * tests/ instead of this worktree's directories. Re-register both namespaces
 * with __DIR__ so new classes created in the worktree are found.
 *
 * In a normal checkout (CI or local with real vendor/) this is a no-op: adding
 * the same absolute paths twice is harmless.
 */
$loader = require __DIR__.'/vendor/autoload.php';
$loader->addPsr4('App\\', __DIR__.'/src/', true);
$loader->addPsr4('App\\Tests\\', __DIR__.'/tests/', true);
