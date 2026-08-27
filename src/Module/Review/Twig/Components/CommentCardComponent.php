<?php

declare(strict_types=1);

namespace App\Module\Review\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The visual body of a comment card, over scalars rather than a Comment.
 *
 * Split out of CommentThread so the landing page's try-it demo can render a
 * prototype card from the same markup with no entity, no ids and no route-bound
 * forms behind it. Those forms are the caller's job: CommentThread passes them
 * through the content block, the demo passes nothing.
 *
 * Props:
 *   domId        string              Value for the element id; also what a Turbo
 *                                    Stream targets. Empty on the prototype.
 *   author       string              Display name shown in the header.
 *   age          string              Pre-formatted relative age ("2h ago"). Empty
 *                                    when the comment predates the timestamp
 *                                    column, and on the prototype.
 *   status       string              pending | addressed | resolved.
 *   kind         string              comment | suggestion | strike.
 *   quote        string              The anchored passage.
 *   prefix       string              Text immediately before the passage.
 *   suffix       string              Text immediately after the passage.
 *   replacement  string              Proposed wording; only read for a suggestion.
 *   body         string              The comment itself; empty for a strike.
 *   orphaned     bool                The passage is gone from this version.
 *   replies      list<array{author: string, body: string, age: string}>
 */
#[AsTwigComponent(name: 'CommentCard')]
final class CommentCardComponent
{
    public string $domId = '';

    public string $author = '';

    public string $age = '';

    public string $status = 'pending';

    public string $kind = 'comment';

    public string $quote = '';

    public string $prefix = '';

    public string $suffix = '';

    public string $replacement = '';

    public string $body = '';

    public bool $orphaned = false;

    /** @var list<array{author: string, body: string, age: string}> */
    public array $replies = [];

    /**
     * The status pill's label, which is not always the status: a general comment
     * and a strike both read as their kind while still pending, and an orphaned
     * passage reads as open whatever its status.
     */
    public function statusLabel(): string
    {
        if ($this->orphaned) {
            return 'open';
        }

        if ('pending' !== $this->status) {
            return $this->status;
        }

        if ('' === $this->quote) {
            return 'general';
        }

        return 'strike' === $this->kind ? 'strike' : $this->status;
    }

    public function isGeneral(): bool
    {
        return '' === $this->quote;
    }
}
