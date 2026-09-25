<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use App\Module\SiteReview\Command\NewAnchor;
use App\Module\SiteReview\Command\NewStroke;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single widget comment, saved immediately on composer save. A comment with
 * no anchor at all is an unanchored page note.
 *
 * `selector` and `text` are the pre-anchors request body. The widget script
 * carries no version in its URL, so a browser can hold a cached copy for a long
 * time and still post that shape, and newAnchors() maps it to one anchor.
 *
 * Not final: the board's feedback request is this payload plus a target.
 */
class AddCommentRequest
{
    /**
     * @param list<SiteReviewAnchorInput> $anchors
     * @param list<SiteReviewStrokeInput> $strokes
     */
    public function __construct(
        #[Assert\Length(max: 10000)]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $body = null,

        #[Assert\Length(max: 2000)]
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Url(protocols: ['http', 'https'], requireTld: false)]
        public ?string $url = null,

        #[Assert\All([new Assert\Type(SiteReviewAnchorInput::class)])]
        #[Assert\Count(max: 10)]
        #[Assert\Valid]
        public array $anchors = [],

        #[Assert\All([new Assert\Type(SiteReviewStrokeInput::class)])]
        #[Assert\Count(max: 50)]
        #[Assert\Valid]
        public array $strokes = [],

        #[Assert\Length(max: 2000)]
        public string $selector = '',

        #[Assert\Length(max: 2000)]
        public string $text = '',

        /**
         * Whatever the embed's `data-context` carried. Nothing here reads it:
         * it is stored as given and interpreted by whichever module recognises
         * the value it wrote.
         */
        #[Assert\Length(max: 255)]
        public ?string $context = null,

        #[Assert\Uuid]
        public ?string $deliveryId = null,
    ) {
    }

    /**
     * An absent attribute and a blank one both mean "no context", so both
     * become null rather than one of them reaching the column as an empty
     * string that later reads like data. A deployment that leaves the variable
     * empty renders no attribute at all, so the blank case is a misconfigured
     * one rather than a theoretical one.
     */
    public function context(): ?string
    {
        $context = trim($this->context ?? '');

        return '' === $context ? null : $context;
    }

    /**
     * A body that carries neither anchors[] nor a selector is a page note.
     *
     * @return list<NewAnchor>
     */
    public function newAnchors(): array
    {
        if ([] === $this->anchors) {
            return '' === $this->selector ? [] : [new NewAnchor($this->selector, $this->text)];
        }

        return array_values(array_map(
            static fn (SiteReviewAnchorInput $anchor): NewAnchor => new NewAnchor(
                selector: $anchor->selector ?? '',
                text: $anchor->text,
                quote: $anchor->quote,
                quotePrefix: $anchor->quotePrefix,
                quoteSuffix: $anchor->quoteSuffix,
            ),
            $this->anchors,
        ));
    }

    /**
     * Validation has already proved every point is a numeric pair, so the cast
     * here narrows the type rather than repairing the value.
     *
     * @return list<NewStroke>
     */
    public function newStrokes(): array
    {
        return array_values(array_map(
            static fn (SiteReviewStrokeInput $stroke): NewStroke => new NewStroke(
                space: $stroke->space,
                points: array_values(array_map(
                    /** @param array{0: float|int, 1: float|int} $point */
                    static fn (array $point): array => [
                        round((float) $point[0], 5),
                        round((float) $point[1], 5),
                    ],
                    $stroke->points,
                )),
            ),
            $this->strokes,
        ));
    }
}
