<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\CreateBucketRuleCommand;
use App\Module\Insights\Command\CreateBucketRuleHandler;
use App\Module\Insights\Form\BucketRuleFormType;
use App\Module\Insights\Form\BucketRuleRequest;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/time-buckets',
    name: 'app_project_analytics_bucket_rule_create',
    methods: ['POST'],
)]
class CreateBucketRuleController extends AppController
{
    public function __construct(
        private readonly CreateBucketRuleHandler $createRule,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $data = new BucketRuleRequest();
        $form = $this->createForm(BucketRuleFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->createRule)(new CreateBucketRuleCommand($project, $data->pattern ?? '', $data->bucket ?? ''));
                $this->addFlash('success', $this->translator->trans('insights.bucket_rules.flash.created'));

                return $this->redirectToRoute('app_project_analytics_time_buckets', ['id' => (string) $project->id]);
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->forward(ShowBucketRulesController::class, [
            'id' => (string) $project->id,
            'project' => $project,
            ShowBucketRulesController::RULE_FORM => $form->createView(),
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
