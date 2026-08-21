<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\EventListener;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequireNotSuspendedListenerTest extends WebTestCase
{
    private const string SUSPENDED_PATH = '/account/suspended';

    public function test_a_suspended_user_is_redirected_to_the_explanation_page(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('pinned@suspended-gate.example.com', suspended: true));

        $client->request(Request::METHOD_GET, '/account');

        $this->assertResponseRedirects(self::SUSPENDED_PATH);
    }

    public function test_a_suspended_user_can_still_reach_the_explanation_page(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('reachable@suspended-gate.example.com', suspended: true));

        $client->request(Request::METHOD_GET, self::SUSPENDED_PATH);

        $this->assertResponseIsSuccessful();
    }

    public function test_a_suspended_user_can_still_log_out(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('leaving@suspended-gate.example.com', suspended: true));

        // Submitting the page's own form rather than requesting /logout: the
        // firewall handles logout at a higher priority and stops propagation,
        // so this gate never sees that path and asserting on it proves nothing.
        $crawler = $client->request(Request::METHOD_GET, self::SUSPENDED_PATH);
        $client->submit($crawler->filter('form[action="/logout"]')->form());

        $this->assertResponseRedirects('/login');
    }

    public function test_an_unsuspended_user_is_not_redirected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('active@suspended-gate.example.com', suspended: false));

        $client->request(Request::METHOD_GET, '/account');

        $this->assertResponseIsSuccessful();
    }

    public function test_a_suspended_and_unverified_user_still_reaches_the_explanation_page(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('unverified@suspended-gate.example.com', suspended: true, verified: false));

        $client->request(Request::METHOD_GET, self::SUSPENDED_PATH);

        $this->assertResponseIsSuccessful();
    }

    public function test_a_suspended_user_who_has_not_accepted_terms_still_reaches_it(): void
    {
        $client = static::createClient();
        $client->loginUser($this->seedUser('no-terms@suspended-gate.example.com', suspended: true, acceptedTerms: false));

        $client->request(Request::METHOD_GET, self::SUSPENDED_PATH);

        $this->assertResponseIsSuccessful();
    }

    /**
     * @param non-empty-string $email
     */
    private function seedUser(
        string $email,
        bool $suspended,
        bool $verified = true,
        bool $acceptedTerms = true,
    ): User {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Gate User', email: $email);
        if ($acceptedTerms) {
            AcceptedTerms::stamp($user, static::getContainer());
        }

        if ($verified) {
            $user->emailVerifiedAt = new \DateTimeImmutable();
        }

        if ($suspended) {
            $user->suspendedAt = new \DateTimeImmutable();
        }

        $em->persist($user);
        $em->flush();
        $em->clear();

        return $em->find(User::class, $user->id) ?? throw new \LogicException('User not found after clear');
    }
}
