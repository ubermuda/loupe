<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\AgentReviewFailingSeverities;
use App\Module\Workflow\Template\Template;
use App\Module\Workflow\Template\TemplateMissing;
use App\Module\Workflow\Template\TemplateSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Reads the failing severities from the stored template copy. A project bound to no template fails a review on an important finding. */
#[AsAlias(AgentReviewFailingSeverities::class)]
final readonly class TemplateAgentReviewFailingSeverities implements AgentReviewFailingSeverities
{
    public function __construct(
        private TemplateSource $source,
    ) {
    }

    #[\Override]
    public function of(Uuid $projectId): array
    {
        try {
            return $this->source->forProject($projectId)->agentReviewFailingSeverities;
        } catch (TemplateMissing) {
            return Template::DEFAULT_AGENT_REVIEW_FAILING_SEVERITIES;
        }
    }
}
