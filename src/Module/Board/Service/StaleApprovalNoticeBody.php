<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\PullRequestNotice;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The Markdown a stale approval notice posts on the pull request. */
final readonly class StaleApprovalNoticeBody
{
    private const string KEY_PREFIX = 'stale-approval:';

    public function __construct(
        private TranslatorInterface $translator,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    public static function key(string $headSha): string
    {
        return self::KEY_PREFIX.$headSha;
    }

    /** A hidden line that lets a retry find the comment an earlier try already posted. */
    public static function marker(string $noticeKey): string
    {
        return '<!-- loupe-notice: '.$noticeKey.' -->';
    }

    /** Delivery is async, so a new head, a new approval or a merge can outdate the notice before it posts. */
    public static function stillHolds(PullRequestNotice $notice, ForgePullRequest $pullRequest): bool
    {
        return PullRequestState::Open === $pullRequest->state
            && null !== $pullRequest->headSha
            && self::key($pullRequest->headSha) === $notice->noticeKey
            && $pullRequest->uncoveredSha === $pullRequest->headSha
            && $pullRequest->approvalIsStale();
    }

    public function of(PullRequestNotice $notice): string
    {
        if (!str_starts_with($notice->noticeKey, self::KEY_PREFIX)) {
            throw new \LogicException('Not a stale approval notice: '.$notice->noticeKey);
        }
        $sha = substr($notice->noticeKey, \strlen(self::KEY_PREFIX), 7);

        return self::marker($notice->noticeKey)."\n\n"
            .$this->translator->trans('board.stale_approval_notice.body', ['%sha%' => $sha], 'messages', $this->locale);
    }
}
