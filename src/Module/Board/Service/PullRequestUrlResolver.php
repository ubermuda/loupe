<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Turns pull request URLs into card links, asking each parser in turn.
 *
 * A URL nothing supports resolves to Forge::Other with no repository and no
 * number. The link is never rejected, because a self-hosted forge is a
 * legitimate answer and the URL is still what the reviewer wants on the card.
 */
final readonly class PullRequestUrlResolver
{
    /** @param iterable<PullRequestUrlParserInterface> $parsers */
    public function __construct(
        #[AutowireIterator('app.pull_request_url_parser')]
        private iterable $parsers,
    ) {
    }

    public function resolve(string $url): ForgeRef
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($url)) {
                return $parser->parse($url);
            }
        }

        return ForgeRef::unknown();
    }

    /**
     * @param list<string> $urls
     *
     * @return list<CardPullRequest>
     */
    public function linksFor(Card $card, array $urls): array
    {
        $links = [];
        $seen = [];
        foreach ($urls as $url) {
            $url = trim($url);
            if ('' === $url || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $ref = $this->resolve($url);
            $links[] = new CardPullRequest($card, $url, $ref->forge, $ref->repository, $ref->number);
        }

        return $links;
    }
}
