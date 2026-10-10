<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\PullRequestComment;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Workflow\Contract\RuleBudgets;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The Markdown a fix run comment posts on the pull request. */
final readonly class FixRunCommentBody
{
    private const array REASONS = ['checks-failed', 'conflict', 'changes-requested', 'agent-review'];

    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkRequestRepository $workRequests,
        private RuleBudgets $ruleBudgets,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    public function of(PullRequestComment $comment, ForgePullRequest $pullRequest): string
    {
        $reason = \in_array($comment->reason, self::REASONS, true) ? $comment->reason : 'other';
        $facts = [$this->trans('board.fix_run_comment.reason', ['%reason%' => $this->trans('board.fix_run_comment.reason.'.$reason)])];

        // The checks belong to checksSha. Checks of another head would describe a commit the run does not fix.
        $sameHead = null !== $comment->headSha && null !== $pullRequest->checksSha
            && mb_strtolower($comment->headSha) === mb_strtolower($pullRequest->checksSha);
        $checks = FailedCheckNames::clean($pullRequest->failedChecks);
        if ('checks-failed' === $reason && $sameHead && [] !== $checks) {
            // A code span keeps a check name from mentioning a user or opening a link.
            $names = array_map(static fn (string $name): string => '`'.str_replace('`', '', $name).'`', $checks);
            $facts[] = $this->trans('board.fix_run_comment.failed_checks', ['%checks%' => implode(', ', $names)]);
        }

        $limit = null === $comment->fixRound ? null : $this->limitOf($comment);
        if (null !== $comment->fixRound && null !== $limit) {
            $facts[] = $this->trans('board.fix_run_comment.round', ['%round%' => $comment->fixRound, '%limit%' => $limit]);
        }

        $link = $this->trans('board.fix_run_comment.card_link', [
            '%card_url%' => $this->urls->generate('app_board_card', ['projectId' => (string) $comment->project->id, 'cardId' => (string) $comment->cardId], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);

        return $this->trans('board.fix_run_comment.intro')."\n\n".implode("\n", $facts)."\n\n".$link."\n\n".self::marker($comment->runId);
    }

    /** A hidden line that lets a retry find the comment an earlier try already posted. */
    public static function marker(Uuid $runId): string
    {
        return '<!-- loupe-fix-run:'.$runId->toRfc4122().' -->';
    }

    /** The limit of the rule that opened the work request of the run. */
    private function limitOf(PullRequestComment $comment): ?int
    {
        $run = $this->workerRuns->find($comment->runId);
        if (null === $run) {
            return null;
        }
        $request = null === $run->workRequestId ? null : $this->workRequests->findOneOfSubject($run->workRequestId, $run->project, $run->subject());
        $ruleId = $request->ruleId ?? $run->ruleId;

        return null === $ruleId ? null : $this->ruleBudgets->limit($comment->project->id ?? throw new \LogicException('A stored comment has a project id.'), $ruleId);
    }

    /** @param array<string, string|int> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'messages', $this->locale);
    }
}
