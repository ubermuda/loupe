<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
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

    public function test_site_review_pill_counts_submitted_comments_and_tints_only_while_pending(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'sidebar-pill@example.com');
        $project = new Project($owner, 'pill-project');
        $em->persist($project);

        // A draft never reaches the shared record, so it must not be counted.
        foreach ([
            SiteReviewCommentStatus::Draft,
            SiteReviewCommentStatus::Pending,
            SiteReviewCommentStatus::Addressed,
            SiteReviewCommentStatus::Resolved,
        ] as $position => $status) {
            $comment = new SiteReviewComment($project, $position, 'Body', '', '', 'https://example.com/');
            $comment->status = $status;
            $em->persist($comment);
        }
        $em->flush();
        $id = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$id.'/documents');

        self::assertResponseIsSuccessful();
        $pill = $crawler->filter('a[href="/projects/'.$id.'/site-review"] .lp-sidebar__pill');
        self::assertSame('3', trim($pill->text()));
        // One comment is still Pending, so the pill flags work awaiting the agent.
        self::assertStringContainsString('lp-sidebar__pill--amber', (string) $pill->attr('class'));
    }

    public function test_site_review_pill_is_untinted_once_nothing_is_pending(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'sidebar-pill-calm@example.com');
        $project = new Project($owner, 'calm-project');
        $em->persist($project);

        $comment = new SiteReviewComment($project, 0, 'Body', '', '', 'https://example.com/');
        $comment->status = SiteReviewCommentStatus::Resolved;
        $em->persist($comment);
        $em->flush();
        $id = (string) $project->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$id.'/documents');

        self::assertResponseIsSuccessful();
        $pill = $crawler->filter('a[href="/projects/'.$id.'/site-review"] .lp-sidebar__pill');
        // Counted, but resolved work must not keep the pill shouting.
        self::assertSame('1', trim($pill->text()));
        self::assertStringNotContainsString('lp-sidebar__pill--amber', (string) $pill->attr('class'));
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    /**
     * The panel is built into every project-scoped page whether or not it opens,
     * so it shows a bounded set and defers the rest to its own see-all link. The
     * counts beside each name come from one batched query, keyed by the project
     * id — a key-format change would render every count as zero rather than fail.
     */
    public function test_the_switcher_caps_its_list_and_counts_each_project(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'sidebar-switcher@example.com');

        $projects = [];
        for ($i = 0; $i < 10; ++$i) {
            $project = new Project($owner, 'switch-'.$i);
            $em->persist($project);
            $projects[] = $project;
        }
        // One document on the newest project, so a count has something to report.
        $document = new Document(owner: $owner, project: $projects[9], title: 'Counted');
        $document->addVersion('# Hi', '<h1>Hi</h1>');
        $em->persist($document);
        $em->flush();

        $id = (string) $projects[9]->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$id.'/documents');

        self::assertResponseIsSuccessful();
        $items = $crawler->filter('.lp-switcher__item');
        self::assertCount(8, $items, 'the panel shows a bounded set, not every project');
        self::assertCount(1, $crawler->filter('.lp-switcher__all'), 'and offers the rest');

        // Newest first, so the project holding the document leads and its count
        // is the batched one rather than the |default(0) fallback.
        self::assertStringContainsString('switch-9', $items->first()->text());
        self::assertStringContainsString('1', $items->first()->filter('.lp-switcher__item-count')->text());
    }
}
