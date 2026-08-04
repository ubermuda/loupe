<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EditProfileControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    /** @param non-empty-string $email */
    private function signedInUser(string $email, string $fullName): User
    {
        $user = new User(username: 'profile'.substr(md5($email), 0, 8), fullName: $fullName, email: $email, password: 'hashed');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function reload(User $user): User
    {
        $this->em->clear();
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $reloaded = $users->find($user->id);
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    public function test_the_form_is_prefilled_with_the_current_name(): void
    {
        $this->signedInUser('profile-prefill@e.com', 'Original Name');

        $crawler = $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/account/profile');

        self::assertResponseIsSuccessful();
        self::assertSame('Original Name', $crawler->filter('#profile_form_fullName')->attr('value'));
    }

    public function test_submitting_a_new_name_persists_it_and_redirects_to_settings(): void
    {
        $user = $this->signedInUser('profile-save@e.com', 'Original Name');

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/account/profile');
        $this->client->submitForm('Save', [
            'profile_form[fullName]' => 'Renamed Person',
        ]);

        self::assertResponseRedirects('/account');
        // The redirect alone only proves routing; the point of the page is the write.
        self::assertSame('Renamed Person', $this->reload($user)->fullName);
    }

    public function test_a_blank_name_is_rejected_and_nothing_is_written(): void
    {
        $user = $this->signedInUser('profile-blank@e.com', 'Original Name');

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/account/profile');
        $this->client->submitForm('Save', [
            'profile_form[fullName]' => '',
        ]);

        // renderFormResponse sets 422 on an invalid submit.
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Original Name', $this->reload($user)->fullName);
    }

    public function test_a_signed_out_visitor_is_sent_to_the_login_page(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/account/profile');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }
}
