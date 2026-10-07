<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Inbox\Command\UpdateInboxSettingsCommand;
use App\Module\Inbox\Command\UpdateInboxSettingsHandler;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Service\InboxWaitSwitches;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @phpstan-type InboxSettingsSummary array{documentInReview: bool, runBlocked: bool, runGaveUp: bool, runWaitingForPerson: bool, pullRequestReady: bool, pullRequestFixStopped: bool, cardPaused: bool}
 */
#[McpTool(name: self::NAME, description: 'Change which waits open an inbox item for the owner, the same switches as the inbox settings page. Each switch is on or off. A switch you leave out keeps the value it has. After the change, Loupe checks every card of the project again, so a wait that already exists opens or closes its item. The result gives the value of every switch after the change.')]
final readonly class InboxSettingsUpdateTool implements FlagGatedToolInterface
{
    public const string NAME = 'inbox_settings_update';

    public function __construct(
        private InboxFlagGate $gate,
        private InboxSubjectResolver $subjects,
        private AuthorizationCheckerInterface $authorization,
        private InboxWaitSwitches $switches,
        private UpdateInboxSettingsHandler $updateSettings,
    ) {
    }

    #[\Override]
    public function gatedToolName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function requiredFlag(): string
    {
        return InboxInstallFlags::FLAG_INBOX_ENABLED;
    }

    /**
     * @param bool|null $documentInReview      whether a document in review on a card opens an item
     * @param bool|null $runBlocked            whether a blocked run opens an item
     * @param bool|null $runGaveUp             whether a run that gave up opens an item
     * @param bool|null $runWaitingForPerson   whether a run waiting for a person opens an item
     * @param bool|null $pullRequestReady      whether a pull request ready for review opens an item
     * @param bool|null $pullRequestFixStopped whether a stopped pull request fix loop opens an item
     * @param bool|null $cardPaused            whether a card the workflow paused opens an item
     *
     * @return InboxSettingsSummary
     */
    public function __invoke(?bool $documentInReview = null, ?bool $runBlocked = null, ?bool $runGaveUp = null, ?bool $runWaitingForPerson = null, ?bool $pullRequestReady = null, ?bool $pullRequestFixStopped = null, ?bool $cardPaused = null): array
    {
        $this->gate->requireEnabled();

        try {
            $project = $this->subjects->requireProject();
            if (!$this->authorization->isGranted(McpBoundProjectVoter::PROJECT_WRITE, $project)) {
                throw new ToolCallException('This connection cannot change this project.');
            }
            ($this->updateSettings)(new UpdateInboxSettingsCommand(
                project: $project,
                documentInReview: $documentInReview,
                runBlocked: $runBlocked,
                runGaveUp: $runGaveUp,
                runWaitingForPerson: $runWaitingForPerson,
                pullRequestReady: $pullRequestReady,
                pullRequestFixStopped: $pullRequestFixStopped,
                cardPaused: $cardPaused,
            ));
            $saved = $this->switches->for($project);

            return [
                'documentInReview' => $saved->documentInReview,
                'runBlocked' => $saved->runBlocked,
                'runGaveUp' => $saved->runGaveUp,
                'runWaitingForPerson' => $saved->runWaitingForPerson,
                'pullRequestReady' => $saved->pullRequestReady,
                'pullRequestFixStopped' => $saved->pullRequestFixStopped,
                'cardPaused' => $saved->cardPaused,
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The inbox settings could not be changed. The error has been logged.', previous: $e);
        }
    }
}
