<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Review\Entity\Highlight;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\AnchorService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @phpstan-type HighlightSummary array{highlighted: list<string>, skipped: list<array{quote: string, reason: string}>}
 */
final readonly class SetDocumentHighlightsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private AnchorService $anchorService,
        private DocumentVersionRepository $documentVersions,
        private Auditor $auditor,
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
     * @return HighlightSummary
     */
    public function __invoke(SetDocumentHighlightsCommand $command): array
    {
        $version = $this->documentVersions->findLatest($command->document);
        $text = $version->plainText();

        // The command states the whole set, so the previous one goes first;
        // orphanRemoval on the collection is what turns this into DELETEs.
        $version->highlights->clear();

        $highlighted = [];
        $skipped = [];
        $collapsedHighlights = [];

        foreach ($command->quotes as $original) {
            // Surrounding whitespace is never part of the passage a reader sees
            // tinted. Interior whitespace needs no such treatment: fromQuote()
            // matches a whitespace run against any other.
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
            // Compared collapsed, because two quotes that differ only in how they
            // were wrapped now reach that same span.
            $collapsed = (string) preg_replace('~\s+~', ' ', $quote);
            if (\in_array($collapsed, $collapsedHighlights, true)) {
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
            $collapsedHighlights[] = $collapsed;
        }

        $this->em->flush();

        // Counts, not the quotes: every one of them is document text.
        $this->auditor->record(
            'review.document_highlights_updated',
            AuditOutcome::Success,
            [
                'documentId' => (string) $command->document->id,
                'versionId' => (string) $version->id,
                'highlightedCount' => \count($highlighted),
                'skippedCount' => \count($skipped),
            ],
            new AuditSubject('document', (string) $command->document->id),
        );

        return ['highlighted' => $highlighted, 'skipped' => $skipped];
    }
}
