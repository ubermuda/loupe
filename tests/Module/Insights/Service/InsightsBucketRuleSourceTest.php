<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Service;

use App\Module\Bridge\Service\BucketRule;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Insights\Service\InsightsBucketRuleSource;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InsightsBucketRuleSourceTest extends KernelTestCase
{
    use InsightsScenario;

    public function test_it_answers_the_rules_of_the_project_in_order_of_position(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->scenarioProject('rule-source');
        $em->persist(new InsightsBucketRule($project, 'Bash:just *', 'just', 3));
        $em->persist(new InsightsBucketRule($project, 'Bash:git *', 'git', 1));
        $em->persist(new InsightsBucketRule($this->scenarioProject('rule-source-other'), 'Bash:npm *', 'npm', 0));
        $em->flush();

        $source = self::getContainer()->get(InsightsBucketRuleSource::class);
        self::assertInstanceOf(InsightsBucketRuleSource::class, $source);

        self::assertEquals([new BucketRule('Bash:git *', 'git'), new BucketRule('Bash:just *', 'just')], $source->rulesFor($project));
    }
}
