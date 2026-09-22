<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowAccountDataControllerTest extends WebTestCase
{
    public function test_the_section_renders_export_and_deletion_alone(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedUser('data-section@example.com'));
        $crawler = $client->request(Request::METHOD_GET, '/account/data');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="export-section"]');
        self::assertSelectorExists('[data-testid="delete-account-section"]');
        self::assertSelectorNotExists('[data-testid="profile-section"]');
        self::assertCount(3, $crawler->filter('.lp-settings-nav a.lp-settings-nav__item'));
        self::assertSelectorExists('.lp-settings-nav__item--active[aria-current="page"][href="/account/data"]');
    }

    public function test_a_ready_export_offers_a_download_link_and_a_pending_one_does_not(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser('data-export-link@example.com');

        $pending = new DataExport($user);
        $em->persist($pending);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/account/data');

        self::assertResponseIsSuccessful();
        $pendingId = $pending->id;
        self::assertNotNull($pendingId);
        self::assertCount(0, $crawler->filter(sprintf('a[href*="/account/exports/%s/download"]', $pendingId)));

        $pending->complete();
        $em->flush();
        $em->clear();

        $crawler = $client->request(Request::METHOD_GET, '/account/data');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('a[href*="/account/exports/%s/download"]', $pendingId)));
    }

    public function test_anonymous_user_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/account/data');

        self::assertResponseRedirects('/login');
    }

    /** @param non-empty-string $email */
    private function createVerifiedUser(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Data Person', email: $email, password: 'hashed-password-placeholder');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
