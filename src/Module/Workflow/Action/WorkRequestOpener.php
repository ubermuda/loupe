<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Bridge\Command\OpenWorkRequestCommand;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Service\CardPullRequests;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\TemplateParser;

/**
 * Opens a work request of a card for a rule, with what the card holds now as its context.
 * A live request of the kind already does the work.
 */
final readonly class WorkRequestOpener
{
    public function __construct(
        private OpenWorkRequestHandler $openWorkRequest,
        private CardPullRequests $trackedPullRequests,
        private CardPullRequestRepository $cardPullRequests,
    ) {
    }

    public function open(Rule $rule, Card $card, Facts $facts, string $kind, ?string $capability): ActionOutcome
    {
        $documentId = null;
        $tag = ActionParams::optionalString($rule, TemplateParser::DOCUMENT_TAG);
        if (null !== $tag) {
            $status = ActionParams::optionalString($rule, TemplateParser::DOCUMENT_STATUS);
            $documents = array_values(array_filter(
                $facts->card->documents,
                static fn (DocumentFacts $document): bool => \in_array($tag, $document->tags, true) && (null === $status || $status === $document->status),
            ));
            if (1 !== \count($documents)) {
                return ActionOutcome::refused([] === $documents ? 'document-not-found' : 'document-ambiguous');
            }
            $documentId = $documents[0]->id;
        }

        try {
            $request = ($this->openWorkRequest)(new OpenWorkRequestCommand(
                project: $card->project,
                subject: WorkSubject::card($card->id ?? throw new \LogicException('A stored card has an id.')),
                cardNumber: $card->number,
                kind: $kind,
                capability: $capability,
                ruleId: $rule->id,
                context: $this->context($card, $facts, $documentId),
            ));
        } catch (DomainErrors $e) {
            return \in_array(OpenWorkRequestHandler::LIVE, $e->errors, true)
                ? ActionOutcome::alreadyLive()
                : ActionOutcome::refused('invalid-work-request');
        }

        return ActionOutcome::done($request->id);
    }

    /** A link URL or a head of another shape stays out, because a bridge fills commands with these values. */
    private function context(Card $card, Facts $facts, ?string $documentId): WorkRequestContext
    {
        $reason = $facts->pullRequest?->fixReason();
        $pullRequest = $this->trackedPullRequests->subjectOf($this->trackedPullRequests->forCard($card), $facts->pullRequest);
        if (null === $pullRequest) {
            return new WorkRequestContext(reason: $reason, documentId: $documentId);
        }

        $url = $this->cardPullRequests->findUrlOfPullRequest($card, $pullRequest->forge, $pullRequest->repository, $pullRequest->number);
        $headSha = null === $pullRequest->headSha ? null : mb_strtolower($pullRequest->headSha);

        return new WorkRequestContext(
            pullRequestNumber: $pullRequest->number,
            pullRequestUrl: null !== $url && WorkRequestContext::acceptsUrl($url) ? $url : null,
            headSha: null !== $headSha && WorkRequestContext::acceptsHeadSha($headSha) ? $headSha : null,
            reason: $reason,
            documentId: $documentId,
        );
    }
}
