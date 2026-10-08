<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service;

use App\Module\Readiness\Command\ReportFinding;
use App\Module\Readiness\Entity\DiscoveryProposal;
use Twig\Environment;

/** Writes the Markdown of a discovery report, with one multi-choice decision block for the proposals that can become cards. */
final readonly class ReadinessReportWriter
{
    public const string DECISION_ID = 'proposals';

    public const string READY = 'ready';

    public const string GAP = 'gap';

    /** Every ASCII punctuation mark that CommonMark lets a backslash escape and that can change how a line reads. */
    private const string ESCAPED = '\\`*_{}[]()<>#+-.!|~&"\'';

    public function __construct(
        private Environment $twig,
    ) {
    }

    /**
     * The text that a reviewer's pick stores for a proposal, which is the title on one line.
     * A proposal with a position is a tick box, and its title is the label of that box.
     */
    public static function optionLabel(string $title): string
    {
        return trim(preg_replace('~\s+~u', ' ', $title) ?? $title);
    }

    /** Loupe strips a trailing recommendation marker from an option label, so a pick holds the title without it. */
    public static function pickLabel(string $title): string
    {
        return trim(preg_replace('~\s*\(recommended:\s*(?:high|moderate|low)\)$~i', '', self::optionLabel($title)) ?? $title);
    }

    /**
     * @param list<ReportFinding>     $findings
     * @param list<DiscoveryProposal> $proposals each proposal with a position is a tick box, in position order
     */
    public function write(string $workflow, string $summary, array $findings, array $proposals): string
    {
        $options = [];
        foreach ($proposals as $proposal) {
            if (null !== $proposal->position) {
                $options[$proposal->position] = addcslashes(self::optionLabel($proposal->title), self::ESCAPED);
            }
        }
        ksort($options);

        return $this->twig->render('@Readiness/report.md.twig', [
            'workflow' => $workflow,
            'summary' => trim($summary),
            'ready' => array_values(array_filter($findings, static fn (ReportFinding $finding): bool => self::READY === $finding->status)),
            'gaps' => array_values(array_filter($findings, static fn (ReportFinding $finding): bool => self::GAP === $finding->status)),
            'proposals' => array_map(static fn (DiscoveryProposal $proposal): array => [
                'title' => self::optionLabel($proposal->title),
                'type' => $proposal->type,
                'body' => $proposal->body,
                'openCardNumber' => $proposal->openCardNumber,
            ], $proposals),
            'options' => array_values($options),
        ]);
    }
}
