<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\Site;
use App\Module\SiteReview\Repository\SiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SitesPageTest extends WebTestCase
{
    public function test_lists_only_own_sites(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'sites-a@example.com');
        $other = $this->user($em, 'sites-b@example.com');
        $em->persist(new Site($owner, 'mine'));
        $em->persist(new Site($other, 'theirs'));
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/site-review/sites');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-site-id]'));
        self::assertSame('mine', trim($crawler->filter('[data-site-id] .bp-doc-row__title')->text()));
    }

    public function test_create_site_persists_and_redirects(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'sites-c@example.com');
        $em->flush();

        $client->loginUser($owner);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/site-review/sites');
        $client->submitForm('Add site', ['create_site_form[name]' => 'my-app']);

        self::assertResponseRedirects('/site-review/sites');
        $site = static::getContainer()->get(SiteRepository::class)->findOneByOwnerAndName($owner, 'my-app');
        self::assertNotNull($site);
        self::assertNull($site->token);
    }

    public function test_duplicate_name_for_same_owner_is_rejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'sites-d@example.com');
        $em->persist(new Site($owner, 'dup'));
        $em->flush();

        $client->loginUser($owner);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/site-review/sites');
        $client->submitForm('Add site', ['create_site_form[name]' => 'dup']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, static::getContainer()->get(SiteRepository::class)->findBy(['name' => 'dup']));
    }

    public function test_same_name_for_different_owner_is_allowed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $a = $this->user($em, 'sites-e@example.com');
        $b = $this->user($em, 'sites-f@example.com');
        $em->persist(new Site($a, 'shared-name'));
        $em->flush();

        $client->loginUser($b);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/site-review/sites');
        $client->submitForm('Add site', ['create_site_form[name]' => 'shared-name']);

        self::assertResponseRedirects('/site-review/sites');
        self::assertNotNull(static::getContainer()->get(SiteRepository::class)->findOneByOwnerAndName($b, 'shared-name'));
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }
}
