<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowAccountSettingsControllerTest extends WebTestCase
{
    #[TestWith(['/account', '/account/profile'])]
    #[TestWith(['/account?tab=profile', '/account/profile'])]
    #[TestWith(['/account?tab=connected-apps', '/account/connected-apps'])]
    #[TestWith(['/account?tab=data', '/account/data'])]
    #[TestWith(['/account?tab=unknown', '/account/profile'])]
    public function test_the_account_root_and_legacy_tab_links_redirect_to_a_section(string $from, string $to): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Alice', email: 'alice@example.com', password: 'hashed-password-placeholder');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, $from);

        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects($to);
    }

    public function test_anonymous_user_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/account');

        self::assertResponseRedirects('/login');
    }
}
