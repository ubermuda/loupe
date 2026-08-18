<?php

declare(strict_types=1);

namespace App\Tests\Module\Landing\Controller;

use App\Module\Account\Entity\User;
use App\Module\Landing\Controller\LandingController;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class LandingControllerTest extends WebTestCase
{
    /** The migration seeds the row, so this moves it rather than creating it. */
    private function setLandingEnabled(KernelBrowser $client, bool $enabled): void
    {
        $flags = $client->getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $flags->findAllIndexed()[LandingController::ENABLED_FLAG]->value = $enabled;
        $em->flush();
    }

    /** @param non-empty-string $email */
    private function createUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    public function test_anonymous_visitor_sees_the_landing_page_where_the_flag_is_on(): void
    {
        $client = static::createClient();
        $this->setLandingEnabled($client, true);

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-landing');
        self::assertSelectorExists('a[href="/register"]');
    }

    /**
     * The flag is seeded off, so this is what a fresh or upgraded instance
     * does: exactly the redirect anonymous visitors got before the page
     * existed.
     */
    public function test_anonymous_visitor_is_sent_to_login_where_the_flag_is_off(): void
    {
        $client = static::createClient();
        $this->setLandingEnabled($client, false);

        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/login');
    }

    public function test_authenticated_user_never_sees_the_landing_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-landing', 'home-landing@example.com');
        $user->wizardCompletedAt = new \DateTimeImmutable();
        $em->flush();
        $this->setLandingEnabled($client, true);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects');
    }

    public function test_fresh_user_is_sent_to_the_wizard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-fresh', 'home-fresh@example.com');
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/welcome');
    }

    public function test_wizard_completed_user_with_no_projects_lands_on_the_projects_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-none', 'home-none@example.com');
        $user->wizardCompletedAt = new \DateTimeImmutable();
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects');
    }

    public function test_mid_wizard_user_with_project_goes_to_its_documents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-midwizard', 'home-midwizard@example.com');
        $project = new Project($user, 'mid-wizard-project');
        $em->persist($project);
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects/'.$projectId.'/documents');
    }

    public function test_user_with_one_project_lands_on_its_documents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-one', 'home-one@example.com');
        $project = new Project($user, 'only-project');
        $em->persist($project);
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects/'.$projectId.'/documents');
    }

    public function test_user_with_multiple_projects_lands_on_the_projects_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-many', 'home-many@example.com');
        $em->persist(new Project($user, 'first'));
        $em->persist(new Project($user, 'second'));
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects');
    }
}
