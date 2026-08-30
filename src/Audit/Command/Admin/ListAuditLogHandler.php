<?php

declare(strict_types=1);

namespace App\Audit\Command\Admin;

use App\Audit\AuditChannel;
use App\Module\Audit\Repository\AuditLogRepository;
use Ubermuda\AdminBundle\Listing\ListPagePagination;

final readonly class ListAuditLogHandler
{
    public const int PER_PAGE = 50;
    public const array ALLOWED_SORTS = ['occurredAt'];

    private const string DATE_FORMAT = 'Y-m-d';

    public function __construct(
        private AuditLogRepository $auditLogs,
        private ListPagePagination $pagination,
    ) {
    }

    public function __invoke(ListAuditLogCommand $command): ListAuditLogView
    {
        $channels = array_map(
            static fn (AuditChannel $channel): string => $channel->value,
            AuditChannel::cases(),
        );

        // null means "not filtering"; an allowlist miss and an unparsable date
        // collapse to the same thing, so a rejected value never reaches the query.
        $filters = array_filter([
            'q' => trim($command->actor ?? '') ?: null,
            'operation' => trim($command->operation ?? '') ?: null,
            'channel' => in_array($command->channel, $channels, true) ? $command->channel : null,
            'from' => $this->parseDate($command->from)?->format(self::DATE_FORMAT),
            'to' => $this->parseDate($command->to)?->format(self::DATE_FORMAT),
        ], static fn (?string $value): bool => null !== $value);

        $occurredFrom = $this->parseDate($filters['from'] ?? null);
        // The bound is a whole day, so the range ends at the start of the next one.
        $occurredBefore = $this->parseDate($filters['to'] ?? null)?->modify('+1 day');

        $total = $this->auditLogs->countForAdmin(
            actorLabel: $filters['q'] ?? null,
            operationPrefix: $filters['operation'] ?? null,
            channel: $filters['channel'] ?? null,
            occurredFrom: $occurredFrom,
            occurredBefore: $occurredBefore,
        );
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        $rows = $this->auditLogs->findPageForAdmin(
            actorLabel: $filters['q'] ?? null,
            operationPrefix: $filters['operation'] ?? null,
            channel: $filters['channel'] ?? null,
            occurredFrom: $occurredFrom,
            occurredBefore: $occurredBefore,
            direction: $command->dir,
            limit: self::PER_PAGE,
            offset: (max(1, $command->page) - 1) * self::PER_PAGE,
        );

        return new ListAuditLogView(
            rows: array_map(
                static fn (array $row): AuditLogRow => new AuditLogRow(
                    id: (string) $row['id'],
                    operation: $row['operation'],
                    outcome: $row['outcome'],
                    category: $row['category'],
                    channel: $row['channel'],
                    occurredAt: $row['occurredAt'],
                    context: $row['context'],
                    actorLabel: $row['actorLabel'],
                    subjectType: $row['subjectType'],
                    subjectId: $row['subjectId'],
                ),
                $rows,
            ),
            total: $total,
            totalPages: $totalPages,
            pageList: $this->pagination->buildPageList($command->page, $totalPages),
            filters: $filters,
            channels: $channels,
            // clampPage answers null on an empty result set, so a filter that
            // matches nothing would otherwise render an empty page 99.
            clampedPage: $this->pagination->clampPage('audit_log', $command->page, $total, self::PER_PAGE, $filters)
                ?? ($command->page > $totalPages ? $totalPages : null),
        );
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $value);

        // createFromFormat rolls an impossible date over rather than failing, so
        // the round trip is what rejects one.
        return false !== $date && $date->format(self::DATE_FORMAT) === $value ? $date : null;
    }
}
