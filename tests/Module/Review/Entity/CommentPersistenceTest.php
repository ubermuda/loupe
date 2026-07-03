<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Entity;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the readonly Anchor embeddable can be hydrated from the database
 * (Doctrine sets readonly properties via reflection under ORM 3).
 */
final class CommentPersistenceTest extends KernelTestCase
{
    public function test_anchor_embeddable_round_trips_through_database(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = new User(username: 'roundtrip', fullName: 'Round Trip', email: 'roundtrip@example.com', password: 'hashed_password');
        $project = new Project($user, 'p-'.uniqid());
        $doc = new Document(owner: $user, project: $project, title: 'RT Doc');
        $version = $doc->addVersion('hello world', '<p>hello world</p>');
        $anchor = new Anchor('hello', 'pre', ' world', 42);
        $comment = new Comment($version, $user, 'round trip?', $anchor);

        $em->persist($user);
        $em->persist($project);
        $em->persist($doc);
        $em->persist($comment);
        $em->flush();

        $id = $comment->id;
        self::assertNotNull($id);

        $em->clear();

        /** @var CommentRepository $repo */
        $repo = self::getContainer()->get(CommentRepository::class);
        $fetched = $repo->find($id);

        self::assertNotNull($fetched);
        self::assertSame('hello', $fetched->anchor->quote);
        self::assertSame('pre', $fetched->anchor->prefix);
        self::assertSame(' world', $fetched->anchor->suffix);
        self::assertSame(42, $fetched->anchor->offsetHint);
    }
}
