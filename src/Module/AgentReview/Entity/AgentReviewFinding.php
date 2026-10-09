<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Entity;

/** One finding of an agent review, on a line range of one file of the pull request. */
final readonly class AgentReviewFinding
{
    public function __construct(
        public string $path,
        public int $startLine,
        public int $endLine,
        public AgentReviewSeverity $severity,
        public string $title,
        public string $body,
    ) {
        if ($startLine < 1 || $endLine < $startLine) {
            throw new \InvalidArgumentException(\sprintf('The line range %d-%d is not valid.', $startLine, $endLine));
        }
    }

    /**
     * @param array{path: string, startLine: int, endLine: int, severity: string, title: string, body: string} $finding
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
        );
    }

    /**
     * @return array{path: string, startLine: int, endLine: int, severity: string, title: string, body: string}
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
        ];
    }
}
