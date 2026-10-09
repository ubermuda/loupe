<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Mcp;

use App\Exception\DomainErrors;
use App\Module\AgentReview\Command\SubmitAgentReviewCommand;
use App\Module\AgentReview\Command\SubmitAgentReviewHandler;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Board\Mcp\BoardSubjectResolver;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

#[McpTool(name: self::NAME, description: 'Submit the review of a pull request of a card. Only a running review worker of the card can call it: the call must come from the loupe CLI session of a worker run of the review work on this card. Pass the cardId, the URL of a pull request that the card links, the full 40-character commit SHA that you reviewed, a summary, and the findings. Each finding names a file path relative to the repository root, a line range on the reviewed commit, a severity (important, nit or pre-existing), a title and a body. Loupe stores the review against that commit, also when the pull request has a newer head. The result field current says whether the commit matches the last head commit that Loupe read from the forge. That commit can be older than the head on the forge. The review fails when a finding has a severity that the project counts as failing, important by default.')]
final readonly class AgentReviewSubmitTool
{
    public const string NAME = 'agent_review_submit';

    public const int MAX_FINDINGS = 200;
    public const int MAX_SUMMARY_LENGTH = 10000;
    public const int MAX_PATH_LENGTH = 1000;
    public const int MAX_TITLE_LENGTH = 200;
    public const int MAX_BODY_LENGTH = 4000;

    public const array FINDING_ITEM = [
        'type' => 'object',
        'properties' => [
            'path' => ['type' => 'string', 'description' => 'the file path relative to the repository root'],
            'startLine' => ['type' => 'integer', 'minimum' => 1, 'description' => 'the first line of the range, on the reviewed commit'],
            'endLine' => ['type' => 'integer', 'minimum' => 1, 'description' => 'the last line of the range, the same as startLine for one line'],
            'severity' => ['type' => 'string', 'enum' => ['important', 'nit', 'pre-existing']],
            'title' => ['type' => 'string', 'description' => 'one line that names the problem'],
            'body' => ['type' => 'string', 'description' => 'what is wrong and how to fix it'],
        ],
        'required' => ['path', 'startLine', 'endLine', 'severity', 'title', 'body'],
    ];

    private const string NO_REVIEW_RUN_MESSAGE = 'agent_review_submit takes a review only from a running review worker of this card. The call names no such run. A review worker sends its session with every call through the loupe CLI.';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private SubmitAgentReviewHandler $submitReview,
        private RequestStack $requests,
    ) {
    }

    /**
     * @param string         $cardId         the id of the card under review
     * @param string         $pullRequestUrl the URL of a pull request that the card links
     * @param string         $headSha        the full SHA of the commit you reviewed, 40 lowercase hex characters
     * @param string         $summary        a short summary of the review
     * @param array<mixed>[] $findings       the findings, an empty list when the review found nothing
     *
     * @return array{reviewId: string, conclusion: string, current: bool} current compares $headSha with the last head commit that Loupe read from the forge
     */
    public function __invoke(
        string $cardId,
        string $pullRequestUrl,
        #[Schema(pattern: '^[0-9a-f]{40}$')] string $headSha,
        string $summary,
        #[Schema(items: self::FINDING_ITEM, maxItems: self::MAX_FINDINGS)] array $findings,
    ): array {
        try {
            $card = $this->subjects->requireCard($cardId, McpBoundProjectVoter::CARD_WRITE);

            if (1 !== preg_match('/^[0-9a-f]{40}$/D', $headSha)) {
                throw new ToolCallException('headSha must be the full commit SHA: 40 lowercase hex characters.');
            }
            $summary = trim($summary);
            if ('' === $summary) {
                throw new ToolCallException('summary must not be empty.');
            }
            if (mb_strlen($summary) > self::MAX_SUMMARY_LENGTH) {
                throw new ToolCallException(\sprintf('summary must not be longer than %d characters.', self::MAX_SUMMARY_LENGTH));
            }

            $review = ($this->submitReview)(new SubmitAgentReviewCommand(
                card: $card,
                sessionId: $this->sessionId(),
                pullRequestUrl: $pullRequestUrl,
                headSha: $headSha,
                summary: $summary,
                findings: $this->findings($findings),
            ));

            return [
                'reviewId' => (string) $review->id,
                'conclusion' => $review->conclusion->value,
                'current' => $review->headSha === $review->pullRequest->headSha,
            ];
        } catch (DomainErrors $e) {
            throw new ToolCallException(match (array_first($e->errors)) {
                SubmitAgentReviewHandler::NO_REVIEW_RUN => self::NO_REVIEW_RUN_MESSAGE, SubmitAgentReviewHandler::NOT_LINKED => 'pullRequestUrl must name a pull request that the card links.', SubmitAgentReviewHandler::NOT_TRACKED => 'Loupe has not read this pull request from the forge yet. Try again in a minute.', default => 'The review was refused. The error has been logged.',
            }, previous: $e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The review could not be stored. The error has been logged.', previous: $e);
        }
    }

    private function sessionId(): ?Uuid
    {
        $session = $this->requests->getCurrentRequest()?->headers->get(AgentRunCause::SESSION_HEADER);

        return null !== $session && Uuid::isValid($session) ? Uuid::fromString($session) : null;
    }

    /**
     * @param array<mixed> $items
     *
     * @return list<AgentReviewFinding>
     */
    private function findings(array $items): array
    {
        if (\count($items) > self::MAX_FINDINGS) {
            throw new ToolCallException(\sprintf('A review takes at most %d findings.', self::MAX_FINDINGS));
        }

        $findings = [];
        foreach (array_values($items) as $index => $item) {
            $field = static fn (string $name): string => \sprintf('findings[%d].%s', $index, $name);
            if (!\is_array($item)) {
                throw new ToolCallException(\sprintf('findings[%d] must be an object.', $index));
            }

            $path = $item['path'] ?? null;
            if (!\is_string($path) || '' === trim($path) || str_starts_with($path, '/') || \in_array('..', explode('/', $path), true) || mb_strlen($path) > self::MAX_PATH_LENGTH) {
                throw new ToolCallException(\sprintf('%s must be a file path relative to the repository root, at most %d characters.', $field('path'), self::MAX_PATH_LENGTH));
            }
            $startLine = $item['startLine'] ?? null;
            $endLine = $item['endLine'] ?? null;
            if (!\is_int($startLine) || $startLine < 1) {
                throw new ToolCallException(\sprintf('%s must be a line number from 1.', $field('startLine')));
            }
            if (!\is_int($endLine) || $endLine < $startLine) {
                throw new ToolCallException(\sprintf('%s must be a line number not before startLine.', $field('endLine')));
            }
            $severity = $item['severity'] ?? null;
            $parsedSeverity = \is_string($severity) ? AgentReviewSeverity::tryFrom($severity) : null;
            if (null === $parsedSeverity) {
                throw new ToolCallException(\sprintf('%s must be one of: %s.', $field('severity'), implode(', ', array_map(static fn (AgentReviewSeverity $case): string => $case->value, AgentReviewSeverity::cases()))));
            }
            $title = \is_string($item['title'] ?? null) ? trim($item['title']) : '';
            if ('' === $title || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
                throw new ToolCallException(\sprintf('%s must not be empty, and at most %d characters.', $field('title'), self::MAX_TITLE_LENGTH));
            }
            $body = $item['body'] ?? null;
            if (!\is_string($body) || mb_strlen($body) > self::MAX_BODY_LENGTH) {
                throw new ToolCallException(\sprintf('%s must be a string of at most %d characters.', $field('body'), self::MAX_BODY_LENGTH));
            }

            $findings[] = new AgentReviewFinding($path, $startLine, $endLine, $parsedSeverity, $title, trim($body));
        }

        return $findings;
    }
}
