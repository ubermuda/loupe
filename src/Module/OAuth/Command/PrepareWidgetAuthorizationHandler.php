<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Widget\WidgetAuthorizationRefused;
use App\Module\OAuth\Widget\WidgetCallbackStore;
use App\Module\OAuth\Widget\WidgetClient;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\SiteOrigins;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

/**
 * Checks a sign-in request from the site-review widget before the consent
 * page shows. The project is the one the embed names, and only its owner may
 * sign in. The page must be on the project's allowed sites, because the
 * callback posts the code to that origin.
 */
final readonly class PrepareWidgetAuthorizationHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private WidgetCallbackStore $callbacks,
        private Auditor $auditor,
    ) {
    }

    /** @throws WidgetAuthorizationRefused */
    public function __invoke(PrepareWidgetAuthorizationCommand $command): PrepareWidgetAuthorizationView
    {
        $request = $command->authorizationRequest;
        $scopes = array_map(static fn ($scope): string => $scope->getIdentifier(), $request->getScopes());
        $state = $request->getState();
        if ([WidgetClient::SCOPE] !== $scopes || null === $state || '' === $state) {
            $this->refuse('oauth.widget.refused.bad_request', Response::HTTP_BAD_REQUEST, null);
        }

        $project = Uuid::isValid($command->projectId) ? $this->projects->find(Uuid::fromString($command->projectId)) : null;
        if (null === $project || null === $project->id) {
            $this->refuse('oauth.widget.refused.unknown_project', Response::HTTP_NOT_FOUND, null);
        }

        if ($project->owner->id?->toRfc4122() !== $command->user->id?->toRfc4122()) {
            $this->refuse('oauth.widget.refused.not_owner', Response::HTTP_FORBIDDEN, $project->id);
        }

        // The concrete origin the request carried, never the pattern that
        // allowed it: this is what the callback posts the code to.
        $origin = SiteOrigins::normalise($command->origin);
        if (null === $origin || !SiteOrigins::allows($project->allowedOrigins, $origin)) {
            $this->refuse('oauth.widget.refused.origin_not_allowed', Response::HTTP_FORBIDDEN, $project->id);
        }

        $this->callbacks->remember($state, $origin, $project->id);

        return new PrepareWidgetAuthorizationView($project, $origin);
    }

    private function refuse(string $reasonKey, int $status, ?Uuid $projectId): never
    {
        $this->auditor->record(
            'oauth.widget_authorization_refused',
            AuditOutcome::Refused,
            ['reason' => $reasonKey, 'projectId' => $projectId?->toRfc4122()],
            category: Auditor::CATEGORY_SECURITY,
        );

        throw new WidgetAuthorizationRefused($reasonKey, $status);
    }
}
