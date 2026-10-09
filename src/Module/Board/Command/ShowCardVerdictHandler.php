<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardVerdict;
use App\Module\Board\Entity\CardVerdictKind;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardVerdictDeliveryRepository;
use App\Module\Board\Repository\CardVerdictRepository;
use App\Module\Board\Service\CardNoteSnapshot;
use App\Module\Board\Service\PullRequestLabel;
use App\Module\Board\Service\ReviewerForgeAccount;
use App\Module\Board\Service\VerdictActionPreview;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Project\Entity\Project;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Reads what the widget shows before a reviewer sends a verdict on a card. */
final readonly class ShowCardVerdictHandler
{
    public function __construct(
        private CardRepository $cards,
        private CardPullRequestRepository $cardPullRequests,
        private CardNoteSnapshot $notes,
        private CardVerdictRepository $cardVerdicts,
        private CardVerdictDeliveryRepository $cardVerdictDeliveries,
        private ReviewerForgeAccount $forgeAccount,
        private VerdictActionPreview $preview,
        private TranslatorInterface $translator,
    ) {
    }

    /** Null when the project has no such card. */
    public function __invoke(ShowCardVerdictCommand $command): ?ShowCardVerdictView
    {
        $card = $this->cards->findOneByIdAndProjectId($command->cardId, (string) $command->project->id);
        if (!$card instanceof Card) {
            return null;
        }

        $reviewerForgeId = $this->forgeAccount->forgeUserIdOf($command->reviewer);
        $latest = $this->cardVerdicts->findLatestForCard($card);

        return new ShowCardVerdictView(
            card: $card,
            pullRequests: array_map(
                static fn (ForgePullRequest $pullRequest): VerdictPullRequestOption => new VerdictPullRequestOption(
                    $pullRequest->id ?? throw new \LogicException('A stored pull request has an id.'),
                    PullRequestLabel::of($pullRequest),
                    null !== $reviewerForgeId && $reviewerForgeId === $pullRequest->authorId,
                ),
                $this->cardPullRequests->findOpenGitHubForCard($card),
            ),
            notes: $this->notes->pendingOf($card),
            connection: $this->forgeAccount->stateOf($command->reviewer),
            latest: $latest,
            latestDeliveries: $latest instanceof CardVerdict ? $this->cardVerdictDeliveries->findForVerdicts([$latest])[(string) $latest->id] ?? [] : [],
            actions: $this->actionsFor($command->project),
        );
    }

    /** @return array<string, list<VerdictActionOption>> */
    private function actionsFor(Project $project): array
    {
        $actions = [];
        foreach (CardVerdictKind::cases() as $kind) {
            $actions[$kind->value] = array_map(
                fn (string $code): VerdictActionOption => new VerdictActionOption(
                    $code,
                    $this->translator->trans('board.verdict.action.'.str_replace('-', '_', $code)),
                ),
                $this->preview->actionsFor($project, $kind),
            );
        }

        return $actions;
    }
}
