<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Forge;

/** Reads `https://github.com/<owner>/<repo>/pull/<number>`. */
final readonly class GitHubPullRequestUrlParser implements PullRequestUrlParserInterface
{
    private const string PATTERN = '#^/([^/]+)/([^/]+)/pull/(\d+)/?$#';

    private const array HOSTS = ['github.com', 'www.github.com'];

    #[\Override]
    public function supports(string $url): bool
    {
        return null !== $this->match($url);
    }

    #[\Override]
    public function parse(string $url): ForgeRef
    {
        $matches = $this->match($url);
        if (null === $matches) {
            return ForgeRef::unknown();
        }

        return new ForgeRef(Forge::GitHub, $matches[1].'/'.$matches[2], (int) $matches[3]);
    }

    /** @return array{1: string, 2: string, 3: string}|null */
    private function match(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (false === $parts || !isset($parts['host'], $parts['path'])) {
            return null;
        }

        if (!in_array(mb_strtolower($parts['host']), self::HOSTS, true)) {
            return null;
        }

        if (1 !== preg_match(self::PATTERN, $parts['path'], $matches)) {
            return null;
        }

        return [1 => $matches[1], 2 => $matches[2], 3 => $matches[3]];
    }
}
