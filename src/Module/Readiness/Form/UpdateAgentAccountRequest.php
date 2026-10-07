<?php

declare(strict_types=1);

namespace App\Module\Readiness\Form;

use App\Module\Project\Entity\Project;
use App\Utils\GitHubLogin;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateAgentAccountRequest
{
    public function __construct(
        #[Assert\Length(max: GitHubLogin::MAX_LENGTH, maxMessage: 'readiness.form.update_agent_account_form.login.too_long')]
        #[Assert\Regex(pattern: GitHubLogin::PATTERN, message: 'readiness.form.update_agent_account_form.login.invalid')]
        public ?string $login = null,
    ) {
    }

    public static function fromProject(Project $project): self
    {
        return new self(login: $project->agentGitHubLogin);
    }
}
