<?php

declare(strict_types=1);

namespace App\Module\Project\EventListener;

use App\Module\Account\Event\ApiTokenRevoked;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A revoked token keeps its row, so the ON DELETE SET NULL cascade on
 * Project.widgetToken / Project.mcpToken never fires and the binding has to be
 * cleared by hand. Project does it to its own fields rather than Account
 * reaching across to write them.
 *
 * Which query matched decides, not object identity, which stays correct even if
 * the association and the revoked token load as distinct instances.
 */
#[AsEventListener]
final readonly class ClearProjectTokenOnApiTokenRevoked
{
    public function __construct(
        private ProjectRepository $projects,
    ) {
    }

    public function __invoke(ApiTokenRevoked $event): void
    {
        $widgetProject = $this->projects->findOneByWidgetToken($event->token);
        if (null !== $widgetProject) {
            $widgetProject->widgetToken = null;

            return;
        }

        $mcpProject = $this->projects->findOneByMcpToken($event->token);
        if (null !== $mcpProject) {
            $mcpProject->mcpToken = null;
        }
    }
}
