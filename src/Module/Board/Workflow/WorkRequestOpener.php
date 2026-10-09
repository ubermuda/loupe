<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Bridge\Command\OpenWorkRequestCommand;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Workflow\Contract\ActionContext;
use App\Module\Workflow\Contract\ActionOutcome;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Contract\ParameterNames;
use App\Module\Workflow\Contract\WorkOpener;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Opens a work request of a card for a rule, with what the card holds now as its context.
 * A live request of the kind already does the work.
 */
#[AsAlias(WorkOpener::class)]
final readonly class WorkRequestOpener implements WorkOpener
{
    public function __construct(
        private OpenWorkRequestHandler $openWorkRequest,
        private CardRepository $cards,
        private CardPullRequests $trackedPullRequests,
        private CardPullRequestRepository $cardPullRequests,
    ) {
    }

    #[\Override]
    public function open(ActionContext $context, string $kind, ?string $capability, ?string $reason = null): ActionOutcome
    {
        $facts = $context->facts;
        $card = $this->cards->find($context->card->id) ?? throw new \LogicException('A stored card has an id.');
        $documentId = null;
        $tag = $context->optionalString(ParameterNames::DOCUMENT_TAG);
        if (null !== $tag) {
            $status = $context->optionalString(ParameterNames::DOCUMENT_STATUS);
            $documents = array_values(array_filter(
                $facts->get(DocumentsFacts::class)->documents,
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
                ruleId: $context->ruleId,
                context: $this->context($card, $facts, $documentId, $reason),
                prompt: $context->prompt,
            ));
        } catch (DomainErrors $e) {
            return \in_array(OpenWorkRequestHandler::LIVE, $e->errors, true)
                ? ActionOutcome::alreadyLive()
                : ActionOutcome::refused('invalid-work-request');
        }

        return ActionOutcome::done($request->id);
    }

    /** A link URL or a head of another shape stays out, because a bridge fills commands with these values. */
    private function context(Card $card, Facts $facts, ?string $documentId, ?string $reason): WorkRequestContext
    {
        $reason ??= $facts->pullRequest?->fixReason();
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
