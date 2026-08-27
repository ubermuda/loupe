<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowAccountSettingsControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createVerifiedUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function test_logged_in_user_sees_the_export_section(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="export-section"]');
    }

    public function test_a_ready_export_offers_a_download_link_and_a_pending_one_does_not(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        $pending = new DataExport($user);
        $em->persist($pending);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/account');

        self::assertResponseIsSuccessful();
        $pendingId = $pending->id;
        self::assertNotNull($pendingId);
        self::assertCount(0, $crawler->filter(sprintf('a[href*="/account/exports/%s/download"]', $pendingId)));

        $pending->complete();
        $em->flush();
        $em->clear();

        $crawler = $client->request(Request::METHOD_GET, '/account');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('a[href*="/account/exports/%s/download"]', $pendingId)));
    }

    public function test_anonymous_user_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/account');

        self::assertResponseRedirects('/login');
    }
}
