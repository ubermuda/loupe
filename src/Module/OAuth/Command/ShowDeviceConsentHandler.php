<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Exception\DomainErrors;
use App\Module\OAuth\Device\UserCode;
use App\Module\OAuth\Repository\PendingDeviceCodeRepository;
use App\Module\OAuth\Scope\GrantedScope;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Finds the pending device code a person typed. Every lookup spends one
 * attempt of the person's allowance, found or not, so a signed-in account
 * cannot walk the code space.
 */
final readonly class ShowDeviceConsentHandler
{
    public function __construct(
        private PendingDeviceCodeRepository $pendingDeviceCodes,

        #[Autowire(service: 'limiter.oauth_device_verification')]
        private RateLimiterFactoryInterface $limiter,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws DomainErrors on the userCode field */
    public function __invoke(ShowDeviceConsentCommand $command): DeviceConsentView
    {
        $userId = $command->user->id?->toRfc4122() ?? throw new \LogicException('a persisted user always has an id');
        if (!$this->limiter->create('user:'.$userId)->consume()->isAccepted()) {
            $this->logger->info('oauth.device_verification_throttled', ['userId' => $userId]);

            throw new DomainErrors(['userCode' => 'oauth.device.error.too_many_attempts']);
        }

        $userCode = UserCode::normalize($command->userCode);
        $pending = '' === $userCode ? null : $this->pendingDeviceCodes->findPending($userCode);
        $granted = null === $pending ? null : GrantedScope::fromScopes($pending->scopes);
        if (null === $pending || null === $granted || null !== $granted->projectId) {
            $this->logger->info('oauth.device_code_not_found', ['userId' => $userId]);

            throw new DomainErrors(['userCode' => 'oauth.device.error.invalid_code']);
        }

        return new DeviceConsentView(
            deviceCodeId: $pending->identifier,
            userCode: $pending->userCode,
            displayUserCode: UserCode::display($pending->userCode),
            clientId: $pending->clientId,
            clientName: $pending->clientName,
            scopes: $granted->scopes,
            allProjects: $granted->allProjects,
        );
    }
}
