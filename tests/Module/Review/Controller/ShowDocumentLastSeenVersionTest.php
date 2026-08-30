<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowDocumentLastSeenVersionTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createUser(EntityManagerInterface $em, string $fullName, string $email): User
    {
        $user = new User(fullName: $fullName, email: $email, password: 'hashed-password-placeholder');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }

    /**
     * The comment is written on v1 and two revisions follow, so v2 and v3 each
     * carry a copy of it that inherits the original's createdAt. The version
     * resolved is the lowest of those three; taking the highest would resolve to
     * v3 — the current version — and the banner would never appear.
     */
    public function test_the_banner_offers_the_diff_since_the_version_the_reader_commented_on(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'Riley Chen', 'engagement-owner@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: 'Revised Doc');
        $firstVersion = $document->addVersion('The plan mentions JWTs.', '<p>The plan mentions JWTs.</p>');
        $em->persist($document);
        $em->flush();

        $em->persist(new Comment(
            version: $firstVersion,
            author: $owner,
            body: 'Which signing algorithm?',
            anchor: new Anchor('JWTs', 'mentions ', '.', 18),
        ));
        $em->flush();

        $revise = static::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($document, 'The plan mentions JWTs and rotation.', 'Add rotation'));
        $revise(new ReviseDocumentCommand($document, 'The plan mentions JWTs, rotation and revocation.', 'Add revocation'));

        $projectId = (string) $project->id;
        $documentId = (string) $document->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/review');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-version-banner--unread');

        $link = $crawler->filter('.lp-version-banner--unread a')->attr('href');
        self::assertSame(
            '/projects/'.$projectId.'/documents/'.$documentId.'/review/diff/1/3',
            $link,
        );
    }

    /**
     * An agent replying through the MCP writes as the agent account, so it is
     * that account's engagement it records — never the human's.
     */
    public function test_a_comment_by_someone_else_is_not_the_readers_engagement(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'Riley Chen', 'engagement-reader@example.com');
        $other = $this->createUser($em, 'Claude', 'engagement-agent@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: 'Untouched Doc');
        $firstVersion = $document->addVersion('The plan mentions JWTs.', '<p>The plan mentions JWTs.</p>');
        $em->persist($document);
        $em->flush();

        $em->persist(new Comment(
            version: $firstVersion,
            author: $other,
            body: 'Which signing algorithm?',
            anchor: new Anchor('JWTs', 'mentions ', '.', 18),
        ));
        $em->flush();

        $revise = static::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($document, 'The plan mentions JWTs and rotation.', 'Add rotation'));

        $projectId = (string) $project->id;
        $documentId = (string) $document->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$documentId.'/review');

        self::assertResponseIsSuccessful();
        // The revision landed, so the page really is at a later version than the
        // one the other account commented on — the banner is absent because the
        // reader has never engaged with it, not because there is nothing to compare.
        self::assertCount(2, $crawler->filter('.lp-version-entry'));
        self::assertSelectorNotExists('.lp-version-banner--unread');
    }
}
