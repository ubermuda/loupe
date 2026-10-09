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
 * @phpstan-type AutomationSettingsSummary array{enabled: bool, commentOnFixQueued: bool, commentOnStaleApproval: bool, syncBehind: bool, mergePullRequests: bool, changeBase: bool, epicDraftSwitch: bool, closeEpicPullRequests: bool, openEpicPullRequests: bool, epicBranchPattern: ?string, stuckDelayMinutes: int}
 */
#[McpTool(name: self::NAME, description: 'Change the automation settings of the project board, the same settings as the Automation page of the project settings. Each setting is on or off, except epicBranchPattern, the branch the breakdown pushes for an epic, and stuckDelayMinutes, the minutes a ready pull request may wait for a merge before its card shows Stuck. A setting you leave out keeps the value it has. An empty epicBranchPattern turns epic branches off. The settings that act on GitHub need the GitHub App of the project to have the matching permission. The result gives the value of every setting after the change.')]
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
     * @param bool|null   $enabled                whether the workflow of the board moves the cards and asks for their work
     * @param bool|null   $commentOnFixQueued     whether Loupe comments on a pull request each time a fix run is queued for it
     * @param bool|null   $commentOnStaleApproval whether Loupe comments on a pull request when new commits follow its approval
     * @param bool|null   $syncBehind             whether Loupe updates the branch of an approved pull request that is behind its base
     * @param bool|null   $mergePullRequests      whether Loupe merges a pull request when the workflow asks
     * @param bool|null   $changeBase             whether Loupe changes the base of a pull request when the workflow asks
     * @param bool|null   $epicDraftSwitch        whether Loupe switches an epic pull request between draft and ready when the workflow asks
     * @param bool|null   $closeEpicPullRequests  whether Loupe closes the pull requests of an epic when the workflow asks
     * @param bool|null   $openEpicPullRequests   whether Loupe opens the pull request of an epic when the workflow asks
     * @param string|null $epicBranchPattern      the branch the breakdown pushes for an epic, such as epic/{number}, with {number} exactly once; an empty string turns epic branches off
     * @param int|null    $stuckDelayMinutes      the minutes a ready pull request may wait for a merge before its card shows Stuck, from 1 to 1440
     *
     * @return AutomationSettingsSummary
     */
    public function __invoke(?bool $enabled = null, ?bool $commentOnFixQueued = null, ?bool $commentOnStaleApproval = null, ?bool $syncBehind = null, ?bool $mergePullRequests = null, ?bool $changeBase = null, ?bool $epicDraftSwitch = null, ?bool $closeEpicPullRequests = null, ?bool $openEpicPullRequests = null, ?string $epicBranchPattern = null, ?int $stuckDelayMinutes = null): array
    {
        try {
            $project = $this->subjects->requireProject();
            $current = $this->automation->settingsOf($project);

            ($this->saveSettings)(new SaveBoardAutomationSettingsCommand(
                project: $project,
                enabled: $enabled ?? $current->enabled,
                commentOnFixQueued: $commentOnFixQueued ?? $current->commentOnFixQueued,
                commentOnStaleApproval: $commentOnStaleApproval ?? $current->commentOnStaleApproval,
                syncBehind: $syncBehind ?? $current->syncBehind,
                mergePullRequests: $mergePullRequests ?? $current->mergePullRequests,
                changeBase: $changeBase ?? $current->changeBase,
                epicDraftSwitch: $epicDraftSwitch ?? $current->epicDraftSwitch,
                closeEpicPullRequests: $closeEpicPullRequests ?? $current->closeEpicPullRequests,
                openEpicPullRequests: $openEpicPullRequests ?? $current->openEpicPullRequests,
                epicBranchPattern: $epicBranchPattern ?? $current->epicBranchPattern,
                stuckDelayMinutes: $stuckDelayMinutes ?? $current->stuckDelayMinutes,
            ));
            $saved = $this->automation->settingsOf($project);

            return [
                'enabled' => $saved->enabled,
                'commentOnFixQueued' => $saved->commentOnFixQueued,
                'commentOnStaleApproval' => $saved->commentOnStaleApproval,
                'syncBehind' => $saved->syncBehind,
                'mergePullRequests' => $saved->mergePullRequests,
                'changeBase' => $saved->changeBase,
                'epicDraftSwitch' => $saved->epicDraftSwitch,
                'closeEpicPullRequests' => $saved->closeEpicPullRequests,
                'openEpicPullRequests' => $saved->openEpicPullRequests,
                'epicBranchPattern' => $saved->epicBranchPattern,
                'stuckDelayMinutes' => $saved->stuckDelayMinutes,
            ];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The automation settings could not be changed. The error has been logged.', previous: $e);
        }
    }
}
