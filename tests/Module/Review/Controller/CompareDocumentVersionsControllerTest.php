<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The pickers on the history page and in the review page's versions panel are
 * plain GET forms, and the diff route carries its two versions as path
 * segments, so this route turns the pair into that path.
 */
final class CompareDocumentVersionsControllerTest extends WebTestCase
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

    /** @return array{0: string, 1: string} the project id and the document id */
    private function seedThreeVersions(EntityManagerInterface $em, User $owner, string $title): array
    {
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: $title);
        $doc->addVersion('# v1', '<h1>v1</h1>');
        $doc->addVersion('# v2', '<h1>v2</h1>');
        $doc->addVersion('# v3', '<h1>v3</h1>');
        $em->persist($doc);
        $em->flush();

        $ids = [(string) $project->id, (string) $doc->id];
        $em->clear();

        return $ids;
    }

    public function test_a_pair_becomes_the_diff_url(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-compare', 'owner-compare@example.com');
        [$projectId, $id] = $this->seedThreeVersions($em, $owner, 'Compare Doc');

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/compare?from=1&to=3');

        self::assertResponseRedirects('/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/3');
    }

    /**
     * The versions panel's picker is used while a comparison is on screen, so a
     * reader on the Markdown view must not be sent back to the rendered one.
     */
    public function test_the_chosen_view_survives_the_redirect(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-compare-view', 'owner-compare-view@example.com');
        [$projectId, $id] = $this->seedThreeVersions($em, $owner, 'Viewed Doc');

        $client->loginUser($owner);
        $base = '/projects/'.$projectId.'/documents/'.$id.'/review';
        $client->request(Request::METHOD_GET, $base.'/compare?from=1&to=3&view=source');

        self::assertResponseRedirects($base.'/diff/1/3?view=source');

        // The history page's picker sends no view, and must not gain one.
        $client->request(Request::METHOD_GET, $base.'/compare?from=1&to=3');
        self::assertResponseRedirects($base.'/diff/1/3');
    }

    /**
     * A pair given the wrong way round names the same comparison, so it is
     * answered rather than refused: the diff route 404s on a backwards pair.
     */
    public function test_a_backwards_pair_is_swapped_rather_than_refused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-compare-swap', 'owner-compare-swap@example.com');
        [$projectId, $id] = $this->seedThreeVersions($em, $owner, 'Swapped Doc');

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/compare?from=3&to=1');

        self::assertResponseRedirects('/projects/'.$projectId.'/documents/'.$id.'/review/diff/1/3');

        // And the target really is a page, not the 404 the raw pair would give.
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function test_one_version_against_itself_returns_to_the_history(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-compare-same', 'owner-compare-same@example.com');
        [$projectId, $id] = $this->seedThreeVersions($em, $owner, 'Same Doc');

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/compare?from=2&to=2');

        self::assertResponseRedirects('/projects/'.$projectId.'/documents/'.$id.'/review/history');
    }

    /**
     * Every rejected pair lands on the same page, so the redirector has one
     * contract rather than two: a missing parameter, an unreadable one and a
     * number no version of this document carries all return to the history
     * rather than to a diff URL that answers 404.
     */
    public function test_a_pair_that_names_no_version_of_this_document_returns_to_the_history(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-compare-junk', 'owner-compare-junk@example.com');
        [$projectId, $id] = $this->seedThreeVersions($em, $owner, 'Junk Doc');
        $historyUrl = '/projects/'.$projectId.'/documents/'.$id.'/review/history';

        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/compare');
        self::assertResponseRedirects($historyUrl);

        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/compare?from=nonsense&to=3');
        self::assertResponseRedirects($historyUrl);

        // The document has three versions, so 999 names none of them.
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/compare?from=1&to=999');
        self::assertResponseRedirects($historyUrl);
    }

    public function test_non_owner_gets_403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-compare-private', 'owner-compare-private@example.com');
        $other = $this->createUser($em, 'other-compare', 'other-compare@example.com');
        [$projectId, $id] = $this->seedThreeVersions($em, $owner, 'Private Compare');

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review/compare?from=1&to=2');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_document_under_the_wrong_project_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner-compare-scoped', 'owner-compare-scoped@example.com');
        $projectB = $this->project($em, $owner);
        [, $id] = $this->seedThreeVersions($em, $owner, 'Scoped Compare');
        $projectBId = (string) $projectB->id;

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectBId.'/documents/'.$id.'/review/compare?from=1&to=2');

        self::assertResponseStatusCodeSame(404);
    }
}
