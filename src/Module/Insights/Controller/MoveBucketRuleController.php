<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\MoveBucketRuleCommand;
use App\Module\Insights\Command\MoveBucketRuleHandler;
use App\Module\Insights\Entity\BucketRuleDirection;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('insights-bucket-rule-move')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/time-buckets/{ruleId}/move/{direction}',
    name: 'app_project_analytics_bucket_rule_move',
    requirements: ['ruleId' => Requirement::UUID, 'direction' => 'up|down'],
    methods: ['POST'],
)]
class MoveBucketRuleController extends AppController
{
    public function __construct(
        private readonly MoveBucketRuleHandler $moveRule,
    ) {
    }

    public function __invoke(
        Project $project,
        BucketRuleDirection $direction,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(ruleId, project)')] InsightsBucketRule $rule,
    ): Response {
        ($this->moveRule)(new MoveBucketRuleCommand($rule, $direction));

        return $this->redirectToRoute('app_project_analytics_time_buckets', ['id' => (string) $project->id]);
    }
}
