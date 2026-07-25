<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\SocialProvider;
use App\Module\Account\Entity\User;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PendingSocialLink
{
    private const string KEY = 'pending_social_link';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function store(SocialProfile $profile, User $user): void
    {
        $this->requestStack->getSession()->set(self::KEY, [
            'provider' => $profile->provider->value,
            'providerUserId' => $profile->providerUserId,
            'email' => $profile->email,
            'fullName' => $profile->fullName,
            'emailVerified' => $profile->emailVerified,
            'userId' => (string) ($user->id ?? throw new \LogicException('Cannot hold a social link for an unsaved user.')),
        ]);
    }

    /** Returns the pending link and clears it (single-use). */
    public function pull(): ?PendingLink
    {
        $link = $this->peek();
        $this->requestStack->getSession()->remove(self::KEY);

        return $link;
    }

    /** Returns the pending link without consuming it. */
    public function peek(): ?PendingLink
    {
        /** @var array{provider: string, providerUserId: string, email: ?string, fullName: ?string, emailVerified: bool, userId: string}|null $data */
        $data = $this->requestStack->getSession()->get(self::KEY);

        if (null === $data) {
            return null;
        }

        return new PendingLink(
            new SocialProfile(
                SocialProvider::from($data['provider']),
                $data['providerUserId'],
                $data['email'],
                $data['fullName'],
                $data['emailVerified'],
            ),
            $data['userId'],
        );
    }
}
