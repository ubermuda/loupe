<?php

declare(strict_types=1);

namespace App\Tests\Audit\Controller\Admin;

use App\Module\Account\Entity\User;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\Entity\AuditLog;
use App\Tests\Support\AcceptedTerms;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class ListAuditLogControllerTest extends WebTestCase
{
    private const string URL = '/admin/audit-log';

    public function test_admin_gets_200(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedAdmin($em, 'audit-admin@admin-test.example.com');
        $log = $this->seedLog($em, 'document.created');
        $em->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, self::URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf('[data-audit-log-id="%s"]', $log->id));
        $this->assertSelectorTextNotContains('body', 'audit.admin.log.');
    }

    public function test_logged_in_non_admin_gets_403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->seedAdmin($em, 'audit-plain@admin-test.example.com', []);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, self::URL);

        $this->assertResponseStatusCodeSame(403);
    }

    public function test_anonymous_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, self::URL);

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * The listing renders the label the record stored, never the actor
     * association. Reaching through the association costs one query per row,
     * and the page looks identical either way.
     */
    public function test_a_full_page_costs_no_query_per_row(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedAdmin($em, 'audit-count@admin-test.example.com');

        // One actor per row: a shared actor would be a single identity-map hit,
        // so a page that walks the association would cost one extra query and
        // this assertion would not see it.
        for ($i = 0; $i < 50; ++$i) {
            $actor = new User(fullName: 'Actor '.$i, email: sprintf('audit-actor-%02d@admin-test.example.com', $i));
            $em->persist($actor);
            $this->seedLog($em, 'document.created', actor: $actor, actorLabel: $actor->fullName);
        }
        $em->flush();
        $em->clear();

        $client->loginUser($admin);
        $client->enableProfiler();
        $crawler = $client->request(Request::METHOD_GET, self::URL);
        $this->assertResponseIsSuccessful();
        self::assertCount(50, $crawler->filter('[data-audit-log-id]'));

        [$listingQueries, $userReads] = $this->queryCounts($client);

        // Without this the bound below would also hold for a request that read
        // no audit rows at all.
        self::assertGreaterThan(0, $listingQueries);
        self::assertLessThanOrEqual(4, $userReads, 'the listing must not read the users table once per row');
    }

    public function test_a_row_with_no_actor_label_renders_the_translated_fallback(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedAdmin($em, 'audit-nolabel@admin-test.example.com');
        $log = $this->seedLog($em, 'trial.swept', actorLabel: null);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, self::URL);

        $this->assertResponseIsSuccessful();
        $cell = $crawler->filter(sprintf('[data-audit-log-id="%s"] td', $log->id))->eq(1);
        self::assertSame('No actor', trim($cell->text()));
    }

    public function test_each_filter_narrows_the_result_set_and_filters_combine(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedAdmin($em, 'audit-filter@admin-test.example.com');

        $wanted = $this->seedLog(
            $em,
            'document.created',
            actorLabel: 'Ada Lovelace',
            channel: 'session',
            occurredAt: new \DateTimeImmutable('2026-03-10 12:00:00'),
        );
        $otherActor = $this->seedLog($em, 'document.created', actorLabel: 'Grace Hopper', channel: 'session', occurredAt: new \DateTimeImmutable('2026-03-10 12:00:00'));
        $otherOperation = $this->seedLog($em, 'token.revoked', actorLabel: 'Ada Lovelace', channel: 'session', occurredAt: new \DateTimeImmutable('2026-03-10 12:00:00'));
        $otherChannel = $this->seedLog($em, 'document.created', actorLabel: 'Ada Lovelace', channel: 'mcp', occurredAt: new \DateTimeImmutable('2026-03-10 12:00:00'));
        $otherDate = $this->seedLog($em, 'document.created', actorLabel: 'Ada Lovelace', channel: 'session', occurredAt: new \DateTimeImmutable('2026-04-01 12:00:00'));
        $em->flush();

        $client->loginUser($admin);

        $cases = [
            '?q=lovelace' => [$otherActor],
            '?operation=document.' => [$otherOperation],
            '?channel=session' => [$otherChannel],
            '?from=2026-03-01&to=2026-03-31' => [$otherDate],
            '?q=lovelace&operation=document.&channel=session&from=2026-03-01&to=2026-03-31' => [$otherActor, $otherOperation, $otherChannel, $otherDate],
        ];

        foreach ($cases as $query => $excluded) {
            $crawler = $client->request(Request::METHOD_GET, self::URL.$query);
            $this->assertResponseIsSuccessful($query);
            self::assertCount(1, $crawler->filter(sprintf('[data-audit-log-id="%s"]', $wanted->id)), $query);
            foreach ($excluded as $log) {
                self::assertCount(0, $crawler->filter(sprintf('[data-audit-log-id="%s"]', $log->id)), $query);
            }
        }
    }

    public function test_the_date_range_includes_the_whole_of_its_last_day(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedAdmin($em, 'audit-range@admin-test.example.com');
        $lateInTheDay = $this->seedLog($em, 'document.created', occurredAt: new \DateTimeImmutable('2026-03-31 23:59:59'));
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, self::URL.'?from=2026-03-01&to=2026-03-31');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('[data-audit-log-id="%s"]', $lateInTheDay->id)));
    }

    /**
     * A wildcard typed into either box is text, not a pattern. An operator
     * filters an audit log to answer a specific question, and a filter that
     * quietly matches more than it was asked for is worse than one that
     * matches nothing.
     */
    public function test_a_wildcard_typed_into_a_text_filter_matches_itself_literally(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedAdmin($em, 'audit-wildcard@admin-test.example.com');

        $literalPercent = $this->seedLog($em, 'document.created', actorLabel: 'Ada 100% Lovelace');
        $plainLabel = $this->seedLog($em, 'document.created', actorLabel: 'Ada Lovelace');
        // Reachable only through the wildcard reading of the %: it contains
        // "100" but not "100%".
        $bareNumber = $this->seedLog($em, 'document.created', actorLabel: 'Ada 1000 Lovelace');
        $literalUnderscore = $this->seedLog($em, 'report_export.started', actorLabel: 'Ada Lovelace');
        $differsAtTheUnderscore = $this->seedLog($em, 'reportXexport.started', actorLabel: 'Ada Lovelace');
        $em->flush();

        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, self::URL.'?q='.rawurlencode('100%'));
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('[data-audit-log-id="%s"]', $literalPercent->id)));
        self::assertCount(0, $crawler->filter(sprintf('[data-audit-log-id="%s"]', $plainLabel->id)));
        self::assertCount(0, $crawler->filter(sprintf('[data-audit-log-id="%s"]', $bareNumber->id)));

        $crawler = $client->request(Request::METHOD_GET, self::URL.'?operation='.rawurlencode('report_export'));
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('[data-audit-log-id="%s"]', $literalUnderscore->id)));
        self::assertCount(0, $crawler->filter(sprintf('[data-audit-log-id="%s"]', $differsAtTheUnderscore->id)));
    }

    /**
     * The escape character is typeable, so it must survive as text next to a
     * wildcard. Searching for the pair pins the replacement order too: escaping
     * % before the escape character doubles the wrong bangs, and the row that
     * was searched for stops matching itself.
     */
    public function test_the_escape_character_survives_beside_a_wildcard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedAdmin($em, 'audit-escapechar@admin-test.example.com');

        $literal = $this->seedLog($em, 'document.created', actorLabel: 'Ada!%Lovelace');
        // Reachable only where the % is still a wildcard.
        $wildcardOnly = $this->seedLog($em, 'document.created', actorLabel: 'Ada!ZZZLovelace');
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, self::URL.'?q='.rawurlencode('ada!%lovelace'));

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(sprintf('[data-audit-log-id="%s"]', $literal->id)));
        self::assertCount(0, $crawler->filter(sprintf('[data-audit-log-id="%s"]', $wildcardOnly->id)));
    }

    /**
     * An out-of-range page redirects whether or not the filters matched
     * anything. ListPagePagination::clampPage answers null on an empty result
     * set, so the empty case needs its own answer.
     */
    public function test_an_out_of_range_page_is_clamped_even_when_the_filters_match_nothing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedAdmin($em, 'audit-emptyclamp@admin-test.example.com');
        $this->seedLog($em, 'document.created');
        $em->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, self::URL.'?q=nothingmatchesthis&page=99');

        $this->assertResponseRedirects(self::URL.'?q=nothingmatchesthis&page=1');
    }

    public function test_pagination_walks_the_pages_and_clamps_an_out_of_range_one(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedAdmin($em, 'audit-pages@admin-test.example.com');

        // Three pages of 50, newest first, so the page a row lands on is known.
        $logs = [];
        for ($i = 0; $i < 120; ++$i) {
            $logs[] = $this->seedLog($em, 'document.created', occurredAt: new \DateTimeImmutable(sprintf('2026-03-01 00:00:00 +%d seconds', $i)));
        }
        $em->flush();

        $client->loginUser($admin);

        $first = $client->request(Request::METHOD_GET, self::URL.'?page=1');
        $this->assertResponseIsSuccessful();
        self::assertCount(50, $first->filter('[data-audit-log-id]'));
        self::assertCount(1, $first->filter(sprintf('[data-audit-log-id="%s"]', $logs[119]->id)));

        $middle = $client->request(Request::METHOD_GET, self::URL.'?page=2');
        $this->assertResponseIsSuccessful();
        self::assertCount(50, $middle->filter('[data-audit-log-id]'));
        self::assertCount(0, $middle->filter(sprintf('[data-audit-log-id="%s"]', $logs[119]->id)));
        self::assertCount(1, $middle->filter(sprintf('[data-audit-log-id="%s"]', $logs[69]->id)));

        $client->request(Request::METHOD_GET, self::URL.'?page=99');
        $this->assertResponseRedirects(self::URL.'?page=3');

        // The Time column offers the flip, so the oldest record must reach page 1.
        $ascending = $client->request(Request::METHOD_GET, self::URL.'?dir=asc');
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $ascending->filter(sprintf('[data-audit-log-id="%s"]', $logs[0]->id)));
        self::assertCount(0, $ascending->filter(sprintf('[data-audit-log-id="%s"]', $logs[119]->id)));
    }

    /**
     * The profiler's collector holds every query the connection ran in this
     * process, seeding included, so both counts are narrowed by table.
     *
     * @return array{0: int, 1: int} reads of audit_log, and reads of users
     */
    private function queryCounts(KernelBrowser $client): array
    {
        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $listingQueries = 0;
        $userReads = 0;
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                $sql = (string) $query['sql'];
                if (preg_match('/\bFROM\s+"?audit_log"?\b/i', $sql)) {
                    ++$listingQueries;
                }
                if (preg_match('/\bFROM\s+"?users"?\b/i', $sql)) {
                    ++$userReads;
                }
            }
        }

        return [$listingQueries, $userReads];
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function seedAdmin(EntityManagerInterface $em, string $email, array $roles = ['ROLE_ADMIN']): User
    {
        $user = new User(fullName: 'Audit Admin', email: $email);
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $user->roles = $roles;
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function seedLog(
        EntityManagerInterface $em,
        string $operation,
        ?User $actor = null,
        ?string $actorLabel = 'Ada Lovelace',
        string $channel = 'session',
        ?\DateTimeImmutable $occurredAt = null,
    ): AuditLog {
        $log = new AuditLog(
            operation: $operation,
            outcome: AuditOutcome::Success,
            category: 'write',
            channel: $channel,
            occurredAt: $occurredAt ?? new \DateTimeImmutable('2026-03-10 12:00:00'),
            context: ['documentId' => 'doc-1'],
            actor: $actor,
            actorLabel: $actorLabel,
        );
        $em->persist($log);

        return $log;
    }
}
