<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\RegistrationGate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final class JoinWaitlistControllerTest extends WebTestCase
{
    public function test_gate_open_redirects_to_register(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/waitlist');

        $this->assertResponseRedirects('/register');
    }

    public function test_gate_closed_renders_the_waitlist_form(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $client->request(Request::METHOD_GET, '/waitlist');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Registration is full');
    }

    public function test_joining_creates_an_entry(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $client->request(Request::METHOD_GET, '/waitlist');
        $client->submitForm('Join the waitlist', [
            'waitlist_join_form[email]' => 'waiting@example.com',
        ]);

        // Post/Redirect/Get: Turbo Drive requires a redirect on a successful
        // top-level form submission, so the controller redirects to the same
        // joined-confirmation branch the OAuth-at-cap flow uses.
        $this->assertResponseRedirects('/waitlist?joined=1');
        $client->request(Request::METHOD_GET, '/waitlist?joined=1');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', "You're on the list");

        $entry = static::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('waiting@example.com');
        $this->assertNotNull($entry);
    }

    public function test_duplicate_join_is_silent_and_creates_no_second_row(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $client->request(Request::METHOD_GET, '/waitlist');
        $client->submitForm('Join the waitlist', [
            'waitlist_join_form[email]' => 'dup-waiting@example.com',
        ]);

        $client->request(Request::METHOD_GET, '/waitlist');
        $client->submitForm('Join the waitlist', [
            'waitlist_join_form[email]' => 'dup-waiting@example.com',
        ]);

        $this->assertResponseRedirects('/waitlist?joined=1');
        $client->request(Request::METHOD_GET, '/waitlist?joined=1');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', "You're on the list");

        $entries = static::getContainer()->get(WaitlistEntryRepository::class)->findBy(['email' => 'dup-waiting@example.com']);
        $this->assertCount(1, $entries);
    }

    private function closeRegistration(): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // Guarantee at least one user exists, then cap at the resulting count —
        // count(0) < cap(N>0) would leave the gate open with no users seeded.
        $em->persist(new User(username: 'gate-filler', fullName: 'Gate Filler', email: 'gate-filler@example.com', password: 'x'));
        $em->flush();

        $userCount = $container->get(UserRepository::class)->countActive();

        $flag = new FeatureFlag(name: RegistrationGate::CAP_FLAG, type: FeatureFlagType::Int, value: $userCount);
        $em->persist($flag);
        $em->flush();
    }
}
