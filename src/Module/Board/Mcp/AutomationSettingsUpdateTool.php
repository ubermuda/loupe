<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Exception\DomainErrors;
use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Service\BoardAutomation;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * @phpstan-type AutomationSettingsSummary array{enabled: bool}
 */
#[McpTool(name: self::NAME, description: 'Turn the workflow of the project board on or off, the same switch as the Automation page of the project settings. While it is off, the workflow moves no card and asks for no work. A setting you leave out keeps the value it has. The result gives the value of every setting after the change.')]
final readonly class AutomationSettingsUpdateTool
{
    public const string NAME = 'automation_settings_update';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private BoardAutomation $automation,
        private SaveBoardAutomationSettingsHandler $saveSettings,
        private BoardToolErrorMessages $errorMessages,
    ) {
    }

    /**
     * @param bool|null $enabled whether the workflow of the board moves the cards and asks for their work
     *
     * @return AutomationSettingsSummary
     */
    public function __invoke(?bool $enabled = null): array
    {
        try {
            $project = $this->subjects->requireProject();
            $current = $this->automation->settingsOf($project);

            ($this->saveSettings)(new SaveBoardAutomationSettingsCommand(
                project: $project,
                enabled: $enabled ?? $current->enabled,
            ));
            $saved = $this->automation->settingsOf($project);

            return ['enabled' => $saved->enabled];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The automation settings could not be changed. The error has been logged.', previous: $e);
        }
    }
}
