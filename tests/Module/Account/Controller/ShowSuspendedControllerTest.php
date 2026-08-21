<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowSuspendedControllerTest extends WebTestCase
{
    public function test_it_shows_the_reason_when_one_was_given(): void
    {
        $client = static::createClient();
        $user = $this->seedSuspendedUser('reason@suspended-test.example.com', 'Repeated spam reports');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/account/suspended');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Repeated spam reports');
        $this->assertSelectorExists('form[action="/logout"][method="post"] input[name="_csrf_token"]');
    }

    public function test_it_falls_back_to_a_generic_message_without_a_reason(): void
    {
        $client = static::createClient();
        $user = $this->seedSuspendedUser('no-reason@suspended-test.example.com', null);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/account/suspended');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.auth-error');
    }

    public function test_a_user_who_is_not_suspended_is_redirected_away(): void
    {
        $client = static::createClient();
        $user = $this->seedSuspendedUser('active@suspended-test.example.com', null, suspended: false);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/account/suspended');

        $this->assertResponseRedirects('/');
    }

    /**
     * @param non-empty-string $email
     */
    private function seedSuspendedUser(string $email, ?string $reason, bool $suspended = true): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Suspended User', email: $email);
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        if ($suspended) {
            $user->suspendedAt = new \DateTimeImmutable();
        }

        $user->suspendedReason = $reason;
        $em->persist($user);
        $em->flush();
        $em->clear();

        return $em->find(User::class, $user->id) ?? throw new \LogicException('User not found after clear');
    }
}
