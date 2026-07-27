<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SidebarStatesTest extends WebTestCase
{
    public function test_projects_index_shows_brand_but_no_switcher(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'sidebar-a@example.com');
        $em->persist(new Project($owner, 'acme'));
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-sidebar__brand'));
        // Outside a project context there is no switcher and no project-scoped
        // links — the account nav (settings, billing) renders on every page.
        self::assertCount(0, $crawler->filter('.lp-sidebar__switcher'));
        self::assertCount(0, $crawler->filter('.lp-sidebar__link[href*="/documents"]'));
        self::assertCount(1, $crawler->filter('.lp-sidebar__link[href="/account"]'));
    }

    public function test_project_scoped_page_shows_switcher_and_active_nav(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'sidebar-b@example.com');
        $project = new Project($owner, 'acme', 'acme.com');
        $em->persist($project);
        $em->flush();
        $id = (string) $project->id;

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$id.'/documents');

        self::assertResponseIsSuccessful();

        // Switcher present and naming the resolved project.
        self::assertCount(1, $crawler->filter('.lp-sidebar__switcher'));
        self::assertSame('acme', trim($crawler->filter('.lp-sidebar__switcher-name')->text()));

        // Scoped nav links present, Documents marked active.
        self::assertSelectorExists('a.lp-sidebar__link--active[href="/projects/'.$id.'/documents"]');
        self::assertSelectorExists('a.lp-sidebar__link[href="/projects/'.$id.'/site-review"]');
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
