<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\View\CardWaitLine;
use App\Module\Review\Repository\DocumentRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Builds the translation key, the values and the link for one wait. Each type has the keys of the part that owns it. */
final readonly class CardWaitText
{
    public function __construct(
        private DocumentRepository $documents,
        private ForgePullRequestRepository $forgePullRequests,
        private WorkerRunRepository $workerRuns,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function line(InboxCardWait $wait, bool $documentLinked): CardWaitLine
    {
        $projectId = $wait->watch->project->id;
        $params = ['%card%' => $wait->watch->cardNumber];
        $href = null;
        $detail = null;

        switch ($wait->type) {
            case InboxCardWaitType::Document:
                $document = null === $wait->documentId ? null : $this->documents->find($wait->documentId);
                $params['%title%'] = $document->title ?? '';
                $params['%version%'] = $wait->versionNumber ?? 0;
                if ($documentLinked && null !== $wait->documentId) {
                    $href = $this->urls->generate('app_document_review', ['projectId' => $projectId, 'documentId' => $wait->documentId]);
                }
                break;
            case InboxCardWaitType::PullRequest:
                $pullRequest = null === $wait->pullRequestId ? null : $this->forgePullRequests->find($wait->pullRequestId);
                $params['%number%'] = $pullRequest->number ?? 0;
                $href = null === $pullRequest ? null : \sprintf('https://github.com/%s/pull/%d', $pullRequest->repository, $pullRequest->number);
                break;
            case InboxCardWaitType::WorkerRun:
                $run = null === $wait->runId ? null : $this->workerRuns->findOneByIdAndProjectId($wait->runId->toRfc4122(), (string) $projectId);
                $detail = null === $run ? null : RunOutputText::clean($run->output);
                $href = null === $wait->runId ? null : $this->urls->generate('app_project_worker_runs', ['id' => $projectId, 'search' => $wait->runId]);
                break;
            case InboxCardWaitType::CardPause:
                $href = $this->urls->generate('app_board_card', ['projectId' => $projectId, 'cardId' => $wait->watch->cardId]);
                break;
        }

        return new CardWaitLine(self::prefix($wait->type).'.inbox_wait.'.$wait->reason->keyPart(), $params, $href, '' === $detail ? null : $detail);
    }

    private static function prefix(InboxCardWaitType $type): string
    {
        return match ($type) {
            InboxCardWaitType::Document => 'review',
            InboxCardWaitType::PullRequest => 'forge',
            InboxCardWaitType::WorkerRun => 'bridge',
            InboxCardWaitType::CardPause => 'board',
        };
    }
}
