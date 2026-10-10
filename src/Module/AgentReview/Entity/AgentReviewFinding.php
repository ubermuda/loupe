<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Entity;

/**
 * One finding of an agent review. A code finding sits on a line range of one file of the pull request.
 * A spec finding can name no file and no lines, such as a requirement that no code builds.
 */
final readonly class AgentReviewFinding
{
    public function __construct(
        public ?string $path,
        public ?int $startLine,
        public ?int $endLine,
        public AgentReviewSeverity $severity,
        public string $title,
        public string $body,
        public AgentReviewCategory $category = AgentReviewCategory::Code,
    ) {
        if (null === $path) {
            if (AgentReviewCategory::Spec !== $category || null !== $startLine || null !== $endLine) {
                throw new \InvalidArgumentException('Only a spec finding can have no path, and it then has no lines.');
            }

            return;
        }
        if (null === $startLine || null === $endLine || $startLine < 1 || $endLine < $startLine) {
            throw new \InvalidArgumentException(\sprintf('The line range %s-%s is not valid.', $startLine ?? 'none', $endLine ?? 'none'));
        }
    }

    /**
     * A stored finding with no category reads as a code finding.
     *
     * @param array{path: ?string, startLine: ?int, endLine: ?int, severity: string, title: string, body: string, category?: string} $finding
     */
    public static function fromArray(array $finding): self
    {
        return new self(
            $finding['path'],
            $finding['startLine'],
            $finding['endLine'],
            AgentReviewSeverity::from($finding['severity']),
            $finding['title'],
            $finding['body'],
            AgentReviewCategory::tryFrom($finding['category'] ?? '') ?? AgentReviewCategory::Code,
        );
    }

    /**
     * @return array{path: ?string, startLine: ?int, endLine: ?int, severity: string, title: string, body: string, category: string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'startLine' => $this->startLine,
            'endLine' => $this->endLine,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'body' => $this->body,
            'category' => $this->category->value,
        ];
    }
}
