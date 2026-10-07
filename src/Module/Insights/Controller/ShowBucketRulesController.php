<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Insights\Command\ListBucketRulesCommand;
use App\Module\Insights\Command\ListBucketRulesHandler;
use App\Module\Insights\Form\BucketRuleFormType;
use App\Module\Insights\Form\BucketRuleRequest;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/time-buckets',
    name: 'app_project_analytics_time_buckets',
    methods: ['GET'],
)]
class ShowBucketRulesController extends AppController
{
    public const string RULE_FORM = 'ruleForm';

    public function __construct(
        private readonly ListBucketRulesHandler $listRules,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $view = ($this->listRules)(new ListBucketRulesCommand($project));

        return $this->render('@Insights/show_bucket_rules.html.twig', [
            'project' => $view->project,
            'rules' => $view->rules,
            'atLimit' => $view->atLimit,
            'ruleForm' => $this->getInjectedFormView($request, self::RULE_FORM)
                ?? $this->createForm(BucketRuleFormType::class, new BucketRuleRequest())->createView(),
        ]);
    }
}
