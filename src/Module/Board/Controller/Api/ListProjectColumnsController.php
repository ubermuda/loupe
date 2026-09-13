<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\ListProjectColumnsCommand;
use App\Module\Board\Command\ListProjectColumnsHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of one of the caller's boards, which the bridge CLI checks its
 * rule file against at start. The firewall admits agent-scoped tokens alone.
 */
#[Route(
    '/api/agent/projects/{handle}/columns',
    name: 'api_agent_project_columns',
    methods: ['GET'],
)]
final class ListProjectColumnsController extends AppController
{
    public function __construct(
        private readonly ListProjectColumnsHandler $listColumns,
        private readonly BoardAvailability $board,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(string $handle): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Columns endpoint reached without an authenticated User.');
        }

        // The same status as the board's other API routes, with a body the CLI can name.
        if (!$this->board->isEnabled()) {
            return $this->json(['error' => 'board_disabled'], JsonResponse::HTTP_NOT_FOUND);
        }

        $view = ($this->listColumns)(new ListProjectColumnsCommand($user, $handle));
        if (null === $view->project) {
            return $this->json(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json([
            'project' => ['id' => (string) $view->project->id, 'slug' => null],
            'columns' => array_map(
                fn (BoardColumn $column): array => [
                    'slug' => $column->slug,
                    // A seeded label is a translation key, and a typed one matches no key.
                    'label' => $this->translator->trans($column->label),
                    'terminal' => $column->terminal,
                    'default' => $column->isDefault,
                ],
                $view->columns,
            ),
        ]);
    }
}
