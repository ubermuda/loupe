<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use App\Mercure\ProjectTopicBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ProjectTopicBuilderTest extends KernelTestCase
{
    /**
     * The topic namespace is derived from DEFAULT_URI rather than configured on
     * its own, so it cannot drift from the host the instance actually answers
     * on — which is what a second host-shaped variable had allowed.
     */
    public function test_topic_is_namespaced_by_the_apps_default_uri(): void
    {
        self::bootKernel();

        $builder = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $builder);

        // The parameter framework.router.default_uri (DEFAULT_URI) feeds.
        $defaultUri = self::getContainer()->getParameter('router.request_context.base_url');
        self::assertIsString($defaultUri);

        $projectId = Uuid::v7();

        self::assertSame(
            rtrim($defaultUri, '/').'/projects/'.$projectId.'/events',
            $builder->forProject($projectId),
        );
    }

    public function test_the_activity_topic_names_its_project_and_no_other_topic_does(): void
    {
        self::bootKernel();
        $builder = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $builder);
        $projectId = Uuid::v7();

        $topic = $builder->forActivity($projectId);

        self::assertStringEndsWith('/projects/'.$projectId.'/activity', $topic);
        self::assertEquals($projectId, $builder->projectIdFromActivityTopic($topic));
        self::assertNull($builder->projectIdFromActivityTopic($builder->forProject($projectId)));
        self::assertNull($builder->projectIdFromActivityTopic($builder->forBoard($projectId)));
        self::assertNull($builder->projectIdFromActivityTopic($builder->forWorkerRuns($projectId)));
        self::assertNull($builder->projectIdFromActivityTopic($topic.'/extra'));
        self::assertNull($builder->projectIdFromWorkerRunsTopic($topic));
        self::assertNull($builder->projectIdFromBoardTopic($topic));
    }

    public function test_the_document_topic_names_its_project_and_document_and_no_other_topic_does(): void
    {
        self::bootKernel();
        $builder = self::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $builder);
        $projectId = Uuid::v7();
        $documentId = Uuid::v7();

        $topic = $builder->forDocument($projectId, $documentId);

        self::assertStringEndsWith('/projects/'.$projectId.'/documents/'.$documentId, $topic);
        self::assertEquals(['projectId' => $projectId, 'documentId' => $documentId], $builder->idsFromDocumentTopic($topic));
        self::assertNull($builder->idsFromDocumentTopic($topic.'/extra'));
        self::assertNull($builder->idsFromDocumentTopic($topic.'/'));
        self::assertNull($builder->idsFromDocumentTopic($builder->forBoard($projectId)));
        self::assertNull($builder->idsFromDocumentTopic($builder->forProject($projectId)));
        self::assertNull($builder->idsFromDocumentTopic($builder->forActivity($projectId)));
        self::assertNull($builder->idsFromDocumentTopic(str_replace((string) $documentId, 'not-a-uuid', $topic)));
        self::assertNull($builder->idsFromDocumentTopic(str_replace((string) $projectId, 'not-a-uuid', $topic)));
        self::assertNull($builder->idsFromDocumentTopic('https://elsewhere.test/projects/'.$projectId.'/documents/'.$documentId));
        self::assertNull($builder->projectIdFromBoardTopic($topic));
        self::assertNull($builder->projectIdFromActivityTopic($topic));
    }
}
