<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Service;

use App\Module\SiteReview\Service\SiteReviewTopicBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SiteReviewTopicBuilderTest extends KernelTestCase
{
    /**
     * The topic namespace is derived from DEFAULT_URI rather than configured on
     * its own, so it cannot drift from the host the instance actually answers
     * on — which is what a second host-shaped variable had allowed.
     */
    public function test_topic_is_namespaced_by_the_apps_default_uri(): void
    {
        self::bootKernel();

        $builder = self::getContainer()->get(SiteReviewTopicBuilder::class);
        self::assertInstanceOf(SiteReviewTopicBuilder::class, $builder);

        // The parameter framework.router.default_uri (DEFAULT_URI) feeds.
        $defaultUri = self::getContainer()->getParameter('router.request_context.base_url');
        self::assertIsString($defaultUri);

        $projectId = Uuid::v7();

        self::assertSame(
            rtrim($defaultUri, '/').'/projects/'.$projectId.'/site-reviews',
            $builder->forProject($projectId),
        );
    }
}
