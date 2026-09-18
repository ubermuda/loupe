<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ShowDocumentHistoryControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }

    private function project(EntityManagerInterface $em, User $owner): Project
    {
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);

        return $project;
    }

    public function test_the_history_lists_every_version_newest_first(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-history', 'owner-history@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Historied Doc');
        $doc->addVersion('# v1', '<h1>v1</h1>', 'The original brief.');
        $doc->addVersion('# v2', '<h1>v2</h1>', 'Phased the rollout.');
        $doc->addVersion('# v3', '<h1>v3</h1>', 'Named the owner of each phase.');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/history');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-history-title', 'Version history');
        self::assertSelectorTextContains('.lp-review-doc__title', 'Historied Doc');
        self::assertSelectorTextContains('.lp-review-doc__version', 'v3');
        self::assertSelectorTextContains('.lp-review-view-tabs__item[aria-current="page"]', 'History');
        self::assertSelectorCount(0, '.lp-review-margin-tabs');
        self::assertSelectorExists('#revise-document-title');
        self::assertSelectorExists('#finish-review-title');
        self::assertSelectorExists('input[name="submit_review_form[versionNumber]"][value="3"]');

        self::assertSame(
            ['3', '2', '1'],
            $crawler->filter('.lp-history__row')->each(
                static fn (Crawler $node): string => (string) $node->attr('data-version-number'),
            ),
        );

        self::assertSame(
            ['Named the owner of each phase.', 'Phased the rollout.', 'The original brief.'],
            $crawler->filter('.lp-history__note')->each(
                static fn (Crawler $node): string => trim($node->text()),
            ),
        );

        self::assertCount(
            1,
            $crawler->filter('.lp-review-view-tabs__item[href="/projects/'.$projectId.'/documents/'.$id.'/review"]'),
        );
    }

    /**
     * One control per adjacent pair, and none on the oldest version, which has no
     * predecessor the diff route would answer for.
     */
    public function test_the_oldest_version_carries_no_compare_control(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-history-diff', 'owner-history-diff@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Compare Controls');
        $doc->addVersion('# v1', '<h1>v1</h1>');
        $doc->addVersion('# v2', '<h1>v2</h1>');
        $doc->addVersion('# v3', '<h1>v3</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/history');

        self::assertResponseIsSuccessful();
        $base = '/projects/'.$projectId.'/documents/'.$id.'/review/diff/';
        self::assertSame(
            [$base.'2/3', $base.'1/2'],
            $crawler->filter('.lp-history__compare')->each(
                static fn (Crawler $node): string => (string) $node->attr('href'),
            ),
        );

        self::assertCount(0, $crawler->filter('.lp-history__row[data-version-number="1"] .lp-history__compare'));
    }

    public function test_reviews_remain_with_their_version_and_withdrawals_preserve_the_log(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createUser($em, 'history-reviewer', 'history-reviewer@example.com');
        $project = $this->project($em, $owner);
        $document = new Document(owner: $owner, project: $project, title: 'Reviewed history');
        $first = $document->addVersion('# First', '<h1>First</h1>');
        $second = $document->addVersion('# Second', '<h1>Second</h1>');
        $document->addVersion('# Third', '<h1>Third</h1>');
        $unrelated = new Document(owner: $owner, project: $project, title: 'Other document');
        $unrelatedVersion = $unrelated->addVersion('# Other', '<h1>Other</h1>');
        $time = new \DateTimeImmutable('2026-09-16T10:00:00+00:00');
        $em->persist($document);
        $em->persist($unrelated);
        $em->persist(new Review($first, Verdict::Approved, $owner, 1, $time, 'Ready to ship.'));
        $em->persist(new Review($first, Verdict::Withdrawn, $owner, 2, $time));
        $em->persist(new Review($second, Verdict::ChangesRequested, $owner, 1, $time, "Fix <script>alert(1)</script>.\nThen submit again."));
        $em->persist(new Review($unrelatedVersion, Verdict::Approved, $owner, 1, $time, 'Unrelated verdict.'));
        $em->flush();
        $path = '/projects/'.$project->id.'/documents/'.$document->id.'/review/history';
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        $firstRow = $crawler->filter('.lp-history__row[data-version-number="1"]');
        self::assertSame(['Approved', 'Verdict withdrawn'], $firstRow->filter('.lp-history__verdict')->each(
            static fn (Crawler $node): string => $node->text(),
        ));
        self::assertSame(['1', '2'], $firstRow->filter('[data-review-sequence]')->each(
            static fn (Crawler $node): string => (string) $node->attr('data-review-sequence'),
        ));
        self::assertStringContainsString('Ready to ship.', $firstRow->text());
        self::assertStringContainsString('History-reviewer', $firstRow->text());
        self::assertSame('2026-09-16T10:00:00+00:00', $firstRow->filter('time')->first()->attr('datetime'));
        $secondRow = $crawler->filter('.lp-history__row[data-version-number="2"]');
        self::assertStringContainsString('Changes requested', $secondRow->text());
        self::assertStringContainsString('Fix <script>alert(1)</script>.', $secondRow->text());
        self::assertCount(0, $secondRow->filter('script'));
        self::assertCount(1, $secondRow->filter('.lp-history__review'));
        self::assertSelectorTextContains('.lp-history__row[data-version-number="3"]', 'No reviews for this version.');
        self::assertStringNotContainsString('Unrelated verdict.', $crawler->text());
    }

    /**
     * Both selects offer every version, because the redirect swaps a pair given
     * the wrong way round rather than refusing it.
     */
    public function test_the_compare_picker_offers_every_version_on_both_sides(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-history-picker', 'owner-history-picker@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Picker Doc');
        $doc->addVersion('# v1', '<h1>v1</h1>');
        $doc->addVersion('# v2', '<h1>v2</h1>');
        $doc->addVersion('# v3', '<h1>v3</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/history');

        self::assertResponseIsSuccessful();
        self::assertSame(
            '/projects/'.$projectId.'/documents/'.$id.'/review/compare',
            $crawler->filter('.lp-history-compare form')->attr('action'),
        );
        self::assertCount(3, $crawler->filter('#history-compare-from option'));
        self::assertCount(3, $crawler->filter('#history-compare-to option'));
        self::assertSame('2', $crawler->filter('#history-compare-from option[selected]')->attr('value'));
        self::assertSame('3', $crawler->filter('#history-compare-to option[selected]')->attr('value'));
    }

    /**
     * A single version has nothing to compare against, so the picker is absent
     * rather than offering one version against itself.
     */
    public function test_a_single_version_document_gets_no_compare_picker(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-history-one', 'owner-history-one@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'One Version');
        $doc->addVersion('# v1', '<h1>v1</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/history');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-history__row'));
        self::assertCount(0, $crawler->filter('.lp-history-compare'));
        self::assertCount(0, $crawler->filter('.lp-history__compare'));
    }

    public function test_non_owner_gets_403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-history-private', 'owner-history-private@example.com');
        $other = $this->createUser($em, 'other-history', 'other-history@example.com');

        $project = $this->project($em, $owner);
        $doc = new Document(owner: $owner, project: $project, title: 'Owner Only History');
        $doc->addVersion('# Private', '<h1>Private</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/history');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_document_under_the_wrong_project_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-history-scoped', 'owner-history-scoped@example.com');
        $projectA = $this->project($em, $owner);
        $projectB = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $projectA, title: 'Belongs To A');
        $doc->addVersion('# A', '<h1>A</h1>');
        $em->persist($doc);
        $em->flush();

        $projectBId = (string) $projectB->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectBId.'/documents/'.$id.'/review/history');

        self::assertResponseStatusCodeSame(404);
    }
}
