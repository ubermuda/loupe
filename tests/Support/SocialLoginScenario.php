<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Account\Entity\SocialProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/**
 * WebTestCase helpers for the social login flows: switching a provider's feature
 * flag on or off, and priming the session with a pending social identity the way
 * the OAuth callback would.
 *
 * The feature-flag reader caches every flag on first read for the lifetime of its
 * instance, and a client with rebooting disabled keeps that instance across
 * requests — so always set flags before the first request of a test.
 */
trait SocialLoginScenario
{
    private function setProviderFlag(KernelBrowser $client, SocialProvider $provider, bool $enabled): void
    {
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $flags = $container->get(FeatureFlagRepository::class);

        $flag = $flags->findOneBy(['name' => $provider->flagName()]);
        if (null === $flag) {
            $flag = new FeatureFlag($provider->flagName(), FeatureFlagType::Bool, $enabled);
            $em->persist($flag);
        } else {
            $flag->value = $enabled;
        }

        $em->flush();
    }

    /**
     * Stashes a pending social identity plus the id of the account the resolver
     * matched it to — the shape PendingSocialLink reads back.
     */
    private function primePendingSocialLink(
        KernelBrowser $client,
        SocialProvider $provider,
        string $providerUserId,
        string $email,
        string $userId,
        bool $emailVerified = true,
    ): void {
        $session = $client->getContainer()->get('session.factory')->createSession();
        $session->set('pending_social_link', [
            'provider' => $provider->value,
            'providerUserId' => $providerUserId,
            'email' => $email,
            'fullName' => 'Link User',
            'emailVerified' => $emailVerified,
            'userId' => $userId,
        ]);
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }
}
