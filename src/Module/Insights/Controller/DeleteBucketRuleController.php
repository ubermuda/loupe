<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\DeleteBucketRuleCommand;
use App\Module\Insights\Command\DeleteBucketRuleHandler;
use App\Module\Insights\Entity\InsightsBucketRule;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('insights-bucket-rule-delete')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/time-buckets/{ruleId}/delete',
    name: 'app_project_analytics_bucket_rule_delete',
    requirements: ['ruleId' => Requirement::UUID],
    methods: ['POST'],
)]
class DeleteBucketRuleController extends AppController
{
    public function __construct(
        private readonly DeleteBucketRuleHandler $deleteRule,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(ruleId, project)')] InsightsBucketRule $rule,
    ): Response {
        ($this->deleteRule)(new DeleteBucketRuleCommand($rule));
        $this->addFlash('success', $this->translator->trans('insights.bucket_rules.flash.deleted'));

        return $this->redirectToRoute('app_project_analytics_time_buckets', ['id' => (string) $project->id]);
    }
}
