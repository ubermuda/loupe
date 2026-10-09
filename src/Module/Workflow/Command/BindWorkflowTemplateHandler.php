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

        // A refusal leaves the closure as a value: a throw inside it closes the EntityManager.
        $result = $this->em->wrapInTransaction(
            /** @return WorkflowBinding|non-empty-array<string, string> */
            function () use ($command, $project, $projectId, $template, $source): WorkflowBinding|array {
                // Column writers take the same lock, so the columns read below stay as they are until the commit.
                $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
                if (null !== $this->workflowBindings->findOneByProjectId($projectId)) {
                    return ['project' => 'workflow.bind.error.already_bound'];
                }

                $projectColumns = [];
                foreach ($this->boardColumns->findForProjectFresh($project) as $column) {
                    $projectColumns[(string) $column->id?->toRfc4122()] = $column;
                }

                $errors = [];
                $links = [];
                $usedColumnIds = [];
                foreach ($template->slots as $slot) {
                    $field = self::field($slot->key);
                    $columnId = $command->slotColumns[$slot->key] ?? null;
                    $column = null === $columnId ? null : ($projectColumns[$columnId->toRfc4122()] ?? null);
                    $error = match (true) {
                        null === $columnId => 'workflow.bind.error.slot_unlinked',
                        null === $column => 'workflow.bind.error.foreign_column',
                        $column->backlog || $column->terminal => 'workflow.bind.error.flag_column',
                        isset($usedColumnIds[$columnId->toRfc4122()]) => 'workflow.bind.error.column_reused',
                        default => null,
                    };
                    if (null !== $error) {
                        $errors[$field] = $error;
                        continue;
                    }
                    $usedColumnIds[$columnId->toRfc4122()] = true;
                    $links[] = new WorkflowSlotLink($project, $slot->key, $column->id);
                }
                foreach (array_keys($command->slotColumns) as $slotKey) {
                    if (null === $template->slot((string) $slotKey)) {
                        $errors[self::field((string) $slotKey)] = 'workflow.bind.error.unknown_slot';
                    }
                }
                if ([] !== $errors) {
                    return $errors;
                }

                $binding = new WorkflowBinding($project, $template->key, $template->version, $source);
                $this->em->persist($binding);
                foreach ($links as $link) {
                    $this->em->persist($link);
                }
                $this->em->flush();

                return $binding;
            },
        );

        if (\is_array($result)) {
            throw new DomainErrors($result);
        }
        $binding = $result;

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
