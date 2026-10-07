<?php

declare(strict_types=1);

namespace App\Module\Readiness\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Project\Workshop\WorkshopReadinessRow;
use App\Module\Readiness\Command\ShowReadinessCommand;
use App\Module\Readiness\Command\ShowReadinessHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Contracts\Translation\TranslatorInterface;

#[McpTool(name: self::NAME, description: 'Read the readiness checks of this project: whether an agent connected, a workflow is chosen, a bridge runs, the GitHub App is installed, the agents push as their own GitHub account, and discovery ran. Each row says whether it is done, with a short status. discovery is the latest discovery run, or null when discovery never ran. discovery.runId is the run id that readiness_report_submit takes. The checks show here while the Workshop guide is hidden too. To start discovery, call discovery_start.')]
final readonly class ReadinessGetTool
{
    use ResolvesBoundProject;

    public const string NAME = 'readiness_get';

    public function __construct(
        private ShowReadinessHandler $showReadiness,
        private AuthenticatedProjectResolver $projectResolver,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{
     *     guideHidden: bool,
     *     rows: list<array{key: string, done: bool, status: string}>,
     *     discovery: array{runId: string, state: string, cardId: string, cardNumber: int, reason: string|null, createdAt: string}|null,
     * }
     */
    public function __invoke(): array
    {
        try {
            $view = ($this->showReadiness)(new ShowReadinessCommand($this->requireBoundProject($this->projectResolver)));
            $run = $view->discovery;

            return [
                'guideHidden' => $view->guideHidden,
                'rows' => array_map(fn (WorkshopReadinessRow $row): array => [
                    'key' => $row->key,
                    'done' => $row->done,
                    'status' => null !== $row->detail ? $this->translator->trans($row->detail) : $this->translator->trans($row->status, $row->statusParameters),
                ], $view->readiness->rows),
                'discovery' => null === $run ? null : [
                    'runId' => (string) $run->id,
                    'state' => $run->state->value,
                    'cardId' => (string) $run->card->id,
                    'cardNumber' => $run->card->number,
                    'reason' => $run->failureReason,
                    'createdAt' => $run->createdAt->format(\DATE_ATOM),
                ],
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The readiness checks could not be read. The error has been logged.', previous: $e);
        }
    }
}
