<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * Why a comparison was declined. Returned in place of a diff so the page can
 * say which of these happened — both send the reader to the version list, but
 * for reasons they would act on differently.
 */
enum DiffRefusal: string
{
    case TooLarge = 'too-large';
    case UnsupportedCharacters = 'unsupported-characters';

    /** Spelled out rather than built from the case value, so the keys are greppable. */
    public function translationKey(): string
    {
        return match ($this) {
            self::TooLarge => 'review.document.diff.refused.too_large',
            self::UnsupportedCharacters => 'review.document.diff.refused.unsupported_characters',
        };
    }
}
