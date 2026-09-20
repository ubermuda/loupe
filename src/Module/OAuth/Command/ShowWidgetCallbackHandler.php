<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Widget\WidgetCallbackStore;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\SiteOrigins;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decides where the widget callback may post its answer. The origin comes
 * from the entry the authorize step stored for this state, and it must still
 * be on the project's allowed sites. Anything else posts nothing.
 */
final readonly class ShowWidgetCallbackHandler
{
    public const string MESSAGE_TYPE = 'loupe-site-review-oauth';

    public function __construct(
        private WidgetCallbackStore $callbacks,
        private ProjectRepository $projects,

        #[Autowire(param: 'app.url')]
        private string $issuer,
    ) {
    }

    public function __invoke(ShowWidgetCallbackCommand $command): ShowWidgetCallbackView
    {
        $message = [
            'type' => self::MESSAGE_TYPE,
            'state' => $command->state,
            'iss' => rtrim($this->issuer, '/'),
            ...('' !== $command->code ? ['code' => $command->code] : ['error' => '' !== $command->error ? $command->error : 'server_error']),
        ];

        $entry = '' === $command->state ? null : $this->callbacks->take($command->state);
        if (null === $entry) {
            return new ShowWidgetCallbackView(null, $message);
        }

        $project = $this->projects->find($entry['projectId']);
        $allowed = null !== $project && SiteOrigins::allows($project->allowedOrigins, $entry['origin']);

        return new ShowWidgetCallbackView($allowed ? $entry['origin'] : null, $message);
    }
}
