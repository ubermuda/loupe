<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Export;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Export\DataExportArchiveBuilder;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\ValueObject\Anchor;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewReply;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Builds the archive through the real, container-wired tagged exporters
 * against a full object graph — the unit tests per exporter prove each file's
 * shape, this proves they all run together and nothing (e.g. an API token
 * hash) leaks across the boundary.
 */
final class DataExportArchiveIntegrationTest extends KernelTestCase
{
    public function test_builds_the_complete_archive_from_a_full_object_graph(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = new User('Alice A', 'alice@example.com', 'hashed');
        $em->persist($user);

        $project = new Project($user, 'My project', 'example.com');
        $em->persist($project);

        $document = new Document($user, $project, 'My doc');
        $em->persist($document);
        $version1 = $document->addVersion('# v1', '<h1>v1</h1>');
        $version2 = $document->addVersion('# v2', '<h1>v2</h1>');

        $parentComment = new Comment($version1, $user, 'first', Anchor::unanchored());
        $parentComment->deletedAt = new \DateTimeImmutable('2026-09-16T20:00:00+00:00');
        $reply = new Comment($version2, $user, 'reply', new Anchor('quote', 'pre', 'post', 3), $parentComment);
        $em->persist($parentComment);
        $em->persist($reply);

        $review = new Review($version1, Verdict::Approved, $user);
        $em->persist($review);

        $inboxItem = new InboxItem($project, 1, InboxItemKind::Review, 'Review my doc', true);
        $inboxReview = new InboxReview($inboxItem, $document);
        $em->persist($inboxItem);
        $em->persist($inboxReview);

        $siteComment = new SiteReviewComment($project, 0, 'Fix this', 'https://example.com/')->addAnchor('.hero h1', 'Hello world');
        $em->persist($siteComment);
        $siteReply = new SiteReviewReply($siteComment, $user, 'Keep this site context.', Uuid::v4());
        $em->persist($siteReply);

        [$apiToken] = ApiToken::issue($user, 'My agent', ApiTokenScope::Mcp);
        $em->persist($apiToken);

        $em->flush();

        $builder = self::getContainer()->get(DataExportArchiveBuilder::class);
        self::assertInstanceOf(DataExportArchiveBuilder::class, $builder);

        $storage = self::getContainer()->get('test.export.storage');
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        $key = $builder->build($user, Uuid::v7());
        $localPath = tempnam(sys_get_temp_dir(), 'loupe-export-assert-');
        self::assertIsString($localPath);
        // ZipArchive reads local files only, so the stored archive has to come
        // back down before it can be inspected.
        file_put_contents($localPath, $storage->read($key));

        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($localPath));

            $names = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                self::assertIsString($name);
                $names[] = $name;
            }
            sort($names);
            self::assertSame(
                ['api_tokens.json', 'audit_log.json', 'billing_profile.json', 'bridges.json', 'cards.json', 'comments.json', 'connected_accounts.json', 'documents.json', 'inbox_asks.json', 'inbox_items.json', 'inbox_reviews.json', 'profile.json', 'projects.json', 'reviews.json', 'section_approvals.json', 'site_review_replies.json', 'site_reviews.json', 'worker_runs.json'],
                $names,
            );

            $rawInboxReviews = $zip->getFromName('inbox_reviews.json');
            self::assertIsString($rawInboxReviews);
            $inboxReviews = json_decode($rawInboxReviews, true, flags: \JSON_THROW_ON_ERROR);
            self::assertCount(1, $inboxReviews);
            self::assertSame((string) $inboxReview->id, $inboxReviews[0]['id']);
            self::assertSame((string) $inboxItem->id, $inboxReviews[0]['itemId']);
            self::assertSame((string) $document->id, $inboxReviews[0]['documentId']);
            self::assertSame('document', $inboxReviews[0]['targetKind']);
            self::assertNull($inboxReviews[0]['verdict']);

            $rawSiteReplies = $zip->getFromName('site_review_replies.json');
            self::assertIsString($rawSiteReplies);
            $siteReplies = json_decode($rawSiteReplies, true, flags: \JSON_THROW_ON_ERROR);
            self::assertCount(1, $siteReplies);
            self::assertSame((string) $siteReply->id, $siteReplies[0]['id']);
            self::assertSame((string) $siteComment->id, $siteReplies[0]['commentId']);
            self::assertSame('Keep this site context.', $siteReplies[0]['body']);

            $rawComments = $zip->getFromName('comments.json');
            self::assertIsString($rawComments);
            $comments = json_decode($rawComments, true, flags: \JSON_THROW_ON_ERROR);
            self::assertCount(2, $comments);
            $replyRow = current(array_filter($comments, static fn (array $row): bool => 'reply' === $row['body']));
            self::assertNotFalse($replyRow);
            self::assertSame('My doc', $replyRow['document']);
            self::assertSame(2, $replyRow['versionNumber']);
            self::assertSame((string) $parentComment->id, $replyRow['parentId']);
            self::assertSame('2026-09-16T20:00:00+00:00', $replyRow['deletedAt']);

            $allJson = '';
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                self::assertIsString($name);
                $content = $zip->getFromName($name);
                self::assertIsString($content);
                $allJson .= $content;
            }
            self::assertStringNotContainsString($apiToken->tokenHash, $allJson);

            $zip->close();
        } finally {
            @unlink($localPath);
            $storage->delete($key);
        }
    }
}
