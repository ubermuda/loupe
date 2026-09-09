<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
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
        self::assertSelectorTextContains('.lp-workspace-title', 'Version history');
        self::assertSelectorTextContains('.lp-workspace-desc', 'Historied Doc');

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

        // The way back to the document the history belongs to.
        self::assertCount(
            1,
            $crawler->filter('.lp-history-back[href="/projects/'.$projectId.'/documents/'.$id.'/review"]'),
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
