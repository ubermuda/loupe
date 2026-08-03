<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Reduces `class` on `<code>` to the one value the renderer means by it: the
 * fenced-code info string, as `language-<name>`.
 *
 * Allowing the attribute without constraining its value is not a smaller version
 * of the same grant. Every `@layer components` class is compiled unconditionally,
 * `<code>` is an ordinary box, and there is no CSP — so a document could give
 * itself `position: fixed`, full-viewport size and an opaque background, and
 * cover an authenticated page with its own content.
 */
final readonly class CodeLanguageClassSanitizer implements AttributeSanitizerInterface
{
    /** @return list<string> */
    #[\Override]
    public function getSupportedElements(): array
    {
        return ['code'];
    }

    /** @return list<string> */
    #[\Override]
    public function getSupportedAttributes(): array
    {
        return ['class'];
    }

    #[\Override]
    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return 1 === preg_match('~^language-[\w.+-]{1,32}$~', $value) ? $value : null;
    }
}
