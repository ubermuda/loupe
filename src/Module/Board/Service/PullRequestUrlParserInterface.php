<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Reads the forge, repository and number out of a pull request URL.
 *
 * One implementation per forge. A URL no implementation supports is not an
 * error: a self-hosted forge is a legitimate answer, so the resolver falls back
 * to Forge::Other rather than rejecting the link.
 */
#[AutoconfigureTag('app.pull_request_url_parser')]
interface PullRequestUrlParserInterface
{
    public function supports(string $url): bool;

    public function parse(string $url): ForgeRef;
}
