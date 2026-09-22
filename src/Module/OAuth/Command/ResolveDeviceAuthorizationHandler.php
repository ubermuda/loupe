<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Exception\DomainErrors;
use App\Module\OAuth\Repository\PendingDeviceCodeRepository;
use App\Module\OAuth\Scope\ApiScope;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Records a person's answer on a pending device code. The device's next poll
 * of the token endpoint then gets its tokens, or access_denied.
 */
final readonly class ResolveDeviceAuthorizationHandler
{
    public function __construct(
        private ShowDeviceConsentHandler $showConsent,
        private PendingDeviceCodeRepository $pendingDeviceCodes,
        private Auditor $auditor,
    ) {
    }

    /** @throws DomainErrors when the code is unknown, expired or already answered */
    public function __invoke(ResolveDeviceAuthorizationCommand $command): void
    {
        $view = ($this->showConsent)(new ShowDeviceConsentCommand($command->userCode, $command->user));
        $userId = $command->user->id?->toRfc4122() ?? throw new \LogicException('a persisted user always has an id');

        if (!$this->pendingDeviceCodes->answer($view->deviceCodeId, $userId, $command->approved)) {
            throw new DomainErrors(['userCode' => 'oauth.device.error.invalid_code']);
        }

        $this->auditor->record(
            $command->approved ? 'oauth.device_approved' : 'oauth.device_denied',
            AuditOutcome::Success,
            ['clientId' => $view->clientId, 'scopes' => implode(' ', array_map(static fn (ApiScope $scope): string => $scope->value, $view->scopes))],
            new AuditSubject('oauth_client', $view->clientId),
        );
    }
}
