<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

final class RegisterInviteFlowTest extends WebTestCase
{
    public function test_invite_link_is_exchanged_for_a_session_token_and_a_clean_url(): void
    {
        $client = static::createClient();
        $this->closeRegistration();
        $token = $this->seedInvite('invitee@example.com');

        $client->request(Request::METHOD_GET, '/register?invite='.$token);

        $this->assertResponseRedirects('/register');
    }

    public function test_registration_succeeds_through_a_closed_gate_after_an_invite_redirect(): void
    {
        $client = static::createClient();
        $this->closeRegistration();
        // The token is a capacity voucher bound to the invited address, so the
        // registration email must match it.
        $token = $this->seedInvite('invitee2@example.com');

        $client->request(Request::METHOD_GET, '/register?invite='.$token);
        $client->request(Request::METHOD_GET, '/register');
        $client->submitForm('Create account', [
            'registration_form[fullName]' => 'Invited Person',
            'registration_form[username]' => 'invitedperson',
            'registration_form[email]' => 'invitee2@example.com',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);

        $this->assertResponseRedirects('/register/check-email');

        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('invitee2@example.com');
        $this->assertNotNull($user);

        $entry = static::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('invitee2@example.com');
        $this->assertNotNull($entry?->convertedAt);
    }

    public function test_registration_with_a_mismatched_email_is_rejected_and_invite_stays_unconverted(): void
    {
        $client = static::createClient();
        $this->closeRegistration();
        $token = $this->seedInvite('rightful-invitee@example.com');

        $client->request(Request::METHOD_GET, '/register?invite='.$token);
        $client->request(Request::METHOD_GET, '/register');
        $client->submitForm('Create account', [
            'registration_form[fullName]' => 'Someone Else',
            'registration_form[username]' => 'someoneelse',
            'registration_form[email]' => 'someone-else@example.com',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);

        $this->assertResponseStatusCodeSame(422);

        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('someone-else@example.com');
        $this->assertNull($user);

        $entry = static::getContainer()->get(WaitlistEntryRepository::class)->findOneByEmail('rightful-invitee@example.com');
        $this->assertNull($entry?->convertedAt);
    }

    public function test_invalid_invite_token_bounces_to_the_waitlist(): void
    {
        $client = static::createClient();
        $this->closeRegistration();

        $client->request(Request::METHOD_GET, '/register?invite=not-a-real-token');
        $this->assertResponseRedirects('/register');

        $client->request(Request::METHOD_GET, '/register');
        $this->assertResponseRedirects('/waitlist');
    }

    private function closeRegistration(): EntityManagerInterface
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $em->persist(new User(username: 'gate-filler', fullName: 'Gate Filler', email: 'gate-filler@example.com', password: 'x'));
        $em->flush();

        $userCount = $container->get(UserRepository::class)->countActive();
        $em->persist(new FeatureFlag(name: 'registration.cap', type: FeatureFlagType::Int, value: $userCount));
        $em->flush();

        return $em;
    }

    /** @param non-empty-string $email */
    private function seedInvite(string $email): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = new WaitlistEntry($email);
        $token = $entry->issueInviteToken();
        $em->persist($entry);
        $em->flush();

        return $token;
    }
}
