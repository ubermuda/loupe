<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Highlight;
use App\Module\Review\Service\AnchorService;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SetDocumentHighlightsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private AnchorService $anchorService,
    ) {
    }

    /**
     * Rebuilds the current version's highlight set from the quoted passages.
     *
     * A quote that cannot be located is reported rather than fatal: the caller
     * quotes from a Markdown source while the anchor basis is the rendered plain
     * text, so one passage carrying inline markup must not cost it the rest of
     * the set.
     *
     * @return array{highlighted: list<string>, skipped: list<array{quote: string, reason: string}>}
     */
    public function __invoke(SetDocumentHighlightsCommand $command): array
    {
        $version = $command->document->currentVersion();
        $text = $version->plainText();

        // The command states the whole set, so the previous one goes first;
        // orphanRemoval on the collection is what turns this into DELETEs.
        $version->highlights->clear();

        $highlighted = [];
        $skipped = [];

        foreach ($command->quotes as $original) {
            // Surrounding whitespace is never part of the passage a reader sees
            // tinted, and a newline carried over from a Markdown source would stop
            // the quote matching at all.
            $quote = trim($original);

            // Every skip echoes the caller's own string rather than the trimmed
            // one: a whitespace-only entry trims to '', which matches nothing the
            // caller sent and leaves it unable to tell which entry was rejected.
            if ('' === $quote) {
                $skipped[] = ['quote' => $original, 'reason' => 'blank'];
                continue;
            }

            // The same quote always resolves to the same occurrence, so a repeat
            // would paint a span already painted rather than reach a second one.
            if (\in_array($quote, $highlighted, true)) {
                $skipped[] = ['quote' => $original, 'reason' => 'duplicate'];
                continue;
            }

            $anchor = $this->anchorService->fromQuote($text, $quote);
            if (null === $anchor) {
                $skipped[] = ['quote' => $original, 'reason' => 'not_found'];
                continue;
            }

            $version->highlights->add(new Highlight($version, $anchor));
            $highlighted[] = $quote;
        }

        $this->em->flush();

        return ['highlighted' => $highlighted, 'skipped' => $skipped];
    }
}
