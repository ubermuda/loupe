<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\ValueObject\DiffRefusal;
use App\Module\Review\ValueObject\NewTextAnchors;
use App\Module\Review\ValueObject\NewTextReason;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Finds the passages a version added since the version before it, as anchors
 * the document pane can paint.
 *
 * It reuses the offsets {@see RenderedDiffBuilder} stamps on the newer side of a
 * comparison, so an anchor is a verbatim slice of the version's plain text, the
 * same basis a comment anchor uses.
 */
final readonly class NewTextAnchorsBuilder
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private MarkdownDiffer $markdownDiffer,
        private MarkdownRenderer $markdownRenderer,
        private DecisionBlockService $decisionBlocks,
        private TranslatorInterface $translator,
        private RenderedDiffBuilder $renderedDiffs,
        private AnchorService $anchors,
    ) {
    }

    public function build(DocumentVersion $version): NewTextAnchors
    {
        $previous = $this->documentVersions->findByNumber($version->document, $version->versionNumber - 1);
        if (null === $previous) {
            return new NewTextAnchors(reason: NewTextReason::NoPreviousVersion);
        }

        $diff = $this->markdownDiffer->diff($previous->markdownSource, $version->markdownSource);
        if ($diff instanceof DiffRefusal) {
            return new NewTextAnchors(reason: NewTextReason::DiffRefused);
        }
        if (!$diff->hasChanges()) {
            return new NewTextAnchors();
        }

        $plainText = $version->plainText();
        $rendered = $this->renderedDiffs->build(
            $this->decisionBlocks->withBadgeLabels(
                $this->markdownRenderer->renderDiff($diff),
                DecisionBlockService::badgeLabels($this->translator),
            ),
            $plainText,
        );

        $anchors = [];
        foreach ($this->spans($rendered->html, $plainText) as [$start, $length]) {
            $anchors[] = $this->anchors->create($plainText, $start, $length);
        }

        return new NewTextAnchors($anchors);
    }

    /**
     * Character spans of the added text, merged where one run ends where the
     * next begins or only whitespace lies between them, and trimmed of the
     * whitespace at either end.
     *
     * @return list<array{int, int}> start and length, in characters
     */
    private function spans(string $html, string $plainText): array
    {
        $body = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR, 'UTF-8')->body
            ?? throw new \RuntimeException('Rendered diff parsed to a document with no body.');

        $spans = [];
        $current = null;
        $inserted = MarkdownRenderer::DIFF_MARK_CLASS.'--inserted';
        $deleted = MarkdownRenderer::DIFF_MARK_CLASS.'--deleted';
        foreach ($body->querySelectorAll('.'.$inserted) as $mark) {
            if (null !== $mark->parentElement?->closest('.'.$inserted)) {
                continue;
            }

            foreach ($mark->querySelectorAll('['.RenderedDiffBuilder::OFFSET_ATTRIBUTE.']') as $run) {
                if (null !== $run->closest('.'.$deleted)) {
                    continue;
                }

                $start = (int) $run->getAttribute(RenderedDiffBuilder::OFFSET_ATTRIBUTE);
                $end = $start + mb_strlen($run->textContent ?? '', 'UTF-8');
                if (null !== $current && $this->joins($current[1], $start, $plainText)) {
                    $current[1] = $end;

                    continue;
                }

                if (null !== $current) {
                    $spans[] = $current;
                }
                $current = [$start, $end];
            }
        }
        if (null !== $current) {
            $spans[] = $current;
        }

        $trimmed = [];
        foreach ($spans as [$start, $end]) {
            $text = mb_substr($plainText, $start, $end - $start, 'UTF-8');
            $lead = mb_strlen($text, 'UTF-8') - mb_strlen(ltrim($text), 'UTF-8');
            $core = mb_strlen(trim($text), 'UTF-8');
            if ($core > 0) {
                $trimmed[] = [$start + $lead, $core];
            }
        }

        return $trimmed;
    }

    private function joins(int $end, int $start, string $plainText): bool
    {
        if ($start < $end) {
            return false;
        }

        return $start === $end || '' === trim(mb_substr($plainText, $end, $start - $end, 'UTF-8'));
    }
}
