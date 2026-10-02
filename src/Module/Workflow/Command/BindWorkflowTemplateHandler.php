<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateParser;
use App\Module\Workflow\Template\UnknownTemplate;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Binds a project to a shipped template once, and links each slot of the template to a column of the project. */
final readonly class BindWorkflowTemplateHandler
{
    public function __construct(
        private ShippedTemplates $shippedTemplates,
        private TemplateParser $parser,
        private BoardColumnRepository $boardColumns,
        private WorkflowBindingRepository $workflowBindings,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(BindWorkflowTemplateCommand $command): WorkflowBinding
    {
        $project = $command->project;
        $projectId = $project->id ?? throw new \LogicException('A project is bound after it is flushed.');

        try {
            $source = $this->shippedTemplates->source($command->templateKey);
        } catch (UnknownTemplate) {
            throw new DomainErrors(['templateKey' => 'workflow.bind.error.unknown_template']);
        }
        $template = $this->parser->parse($source);

        $errors = [];
        $columns = [];
        $usedColumnIds = [];
        foreach ($template->slots as $slot) {
            $field = self::field($slot->key);
            $columnId = $command->slotColumns[$slot->key] ?? null;
            if (null === $columnId) {
                $errors[$field] = 'workflow.bind.error.slot_unlinked';
                continue;
            }
            $column = $this->boardColumns->findOneByIdAndProjectId($columnId->toRfc4122(), $projectId->toRfc4122());
            if (null === $column) {
                $errors[$field] = 'workflow.bind.error.foreign_column';
                continue;
            }
            if (isset($usedColumnIds[$columnId->toRfc4122()])) {
                $errors[$field] = 'workflow.bind.error.column_reused';
                continue;
            }
            $usedColumnIds[$columnId->toRfc4122()] = true;
            $columns[$slot->key] = $column;
        }
        foreach (array_keys($command->slotColumns) as $slotKey) {
            if (null === $template->slot((string) $slotKey)) {
                $errors[self::field((string) $slotKey)] = 'workflow.bind.error.unknown_slot';
            }
        }
        if ([] !== $errors) {
            throw new DomainErrors($errors);
        }

        // The refusal leaves the closure as a value: a throw inside it closes the EntityManager.
        $binding = $this->em->wrapInTransaction(function () use ($project, $projectId, $template, $source, $columns): ?WorkflowBinding {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            if (null !== $this->workflowBindings->findOneByProjectId($projectId)) {
                return null;
            }

            $binding = new WorkflowBinding($project, $template->key, $template->version, $source);
            $this->em->persist($binding);
            foreach ($columns as $slotKey => $column) {
                $this->em->persist(new WorkflowSlotLink($project, $slotKey, $column));
            }
            $this->em->flush();

            return $binding;
        });

        if (null === $binding) {
            throw new DomainErrors(['project' => 'workflow.bind.error.already_bound']);
        }

        $this->auditor->record(
            'workflow.template_bound',
            AuditOutcome::Success,
            ['bindingId' => (string) $binding->id, 'projectId' => (string) $projectId, 'templateKey' => $binding->templateKey, 'templateVersion' => $binding->templateVersion],
            new AuditSubject('workflow_binding', (string) $binding->id),
        );

        return $binding;
    }

    private static function field(string $slotKey): string
    {
        return \sprintf('slotColumns[%s]', $slotKey);
    }
}
