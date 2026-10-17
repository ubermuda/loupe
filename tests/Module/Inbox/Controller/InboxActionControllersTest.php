<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunOutputChunk;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitReason;
use App\Module\Inbox\Entity\InboxCardWaitTrigger;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxActionRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\Inbox\InboxScenario;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AcceptedTerms;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** The run and cancel routes of an inbox action, and the thread that posts to them on the inbox page and the card page. */
final class InboxActionControllersTest extends WebTestCase
{
    use InboxScenario;
    use WorkflowProjects;

    private KernelBrowser $client;
    private Project $project;
    private Card $card;
    private Bridge $bridge;
    private int $number = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->project = $this->workflowProject('inbox-thread');
        $owner = $this->project->owner;
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $this->bindLifecycle($this->project);
        $this->card = $this->service(CreateCardHandler::class)(new CreateCardCommand(
            project: $this->project,
            title: 'Add a tag filter',
            body: 'Body',
            type: 'feature',
            column: $this->column($this->project, 'in-progress'),
            reporter: Actor::Human,
        ));
        $this->bridge = new Bridge($owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->persist($this->bridge);
        $this->em()->flush();
        $this->setInboxFlag(true);
        $this->client->loginUser($owner);
    }

    public function test_the_inbox_page_shows_the_message_the_reply_and_the_split_button(): void
    {
        $item = $this->runItem('Needs the **API key**. Add it to the bridge config.');

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $message = $crawler->filter('#inbox-item-'.$item->number.' [data-inbox-wait="current"]');
        self::assertCount(1, $message);
        self::assertSame('Implement worker', $message->filter('.lp-comment-author')->text());
        self::assertStringContainsString('stopped', $message->filter('.lp-form-hint')->text());
        self::assertSame('API key', $message->filter('.lp-review-doc__prose strong')->text());
        self::assertSame('Resume the run', trim($crawler->filter('[data-inbox-reply-heading]')->text()));
        $main = $crawler->filter('.inbox-split__main');
        self::assertSame('Resume the run', trim($main->text()));
        self::assertSame($this->actionPath($item, 'resume-run'), parse_url((string) $main->attr('formaction'), \PHP_URL_PATH));
        self::assertSame('Add a note (optional)', $crawler->filter('textarea')->attr('placeholder'));
        $menu = $crawler->filter('.inbox-split .lp-menu__panel');
        self::assertNotNull($menu->attr('hidden'));
        self::assertSame('menu', $menu->attr('role'));
        self::assertSame('false', $crawler->filter('.inbox-split__toggle')->attr('aria-expanded'));
        self::assertSame('panel', $menu->attr('data-split-button-target'));
        self::assertSame($menu->attr('id'), $crawler->filter('.inbox-split__toggle')->attr('aria-controls'));
        self::assertStringContainsString('split-button', (string) $crawler->filter('.inbox-split')->attr('data-controller'));
        // The menu holds the other actions, never the default, and Dismiss apart.
        self::assertCount(0, $menu->filter('[data-inbox-action="resume-run"]'));
        self::assertSame('/projects/'.$this->project->id.'/board/cards/'.$this->card->id, $menu->filter('a[data-inbox-action="open"]')->attr('href'));
        // Dismiss sits apart, after a divider.
        self::assertCount(1, $menu->filter('.lp-menu__divider'));
        $dismiss = $menu->filter('.lp-menu__divider ~ [data-inbox-action="dismiss"]');
        self::assertSame('inbox_decline_'.$item->id, $dismiss->attr('form'));
        self::assertSame('Dismiss', trim($dismiss->filter('.lp-menu__label')->text()));
        self::assertSame('Closes this item without an action.', $dismiss->filter('.lp-menu__line')->text());
    }

    public function test_a_resume_queues_the_command_and_the_thread_shows_the_you_card(): void
    {
        $item = $this->runItem();

        $this->post($this->actionPath($item, 'resume-run'), $item, ['note' => 'Carry on.']);

        self::assertStringStartsWith($this->pageUrl().'?ask=', (string) $this->client->getResponse()->headers->get('Location'));
        $action = $this->onlyAction($item);
        self::assertSame(InboxActionKind::ResumeRun, $action->kind);
        self::assertSame('Carry on.', $action->note);
        self::assertSame(BridgeCommandState::Pending, $this->em()->find(BridgeCommand::class, $action->bridgeCommandId)?->state);

        $crawler = $this->client->followRedirect();
        $you = $crawler->filter('#inbox-item-'.$item->number.' [data-inbox-action-status="queued"]');
        self::assertCount(1, $you);
        self::assertSame('Carry on.', trim($you->filter('.lp-inbox-thread__note')->text()));
        self::assertStringContainsString('resumed the run at', $you->filter('.lp-comment-thread__header')->text());
        self::assertSame('Queued', trim($you->filter('.lp-status-chip')->text()));
        // While the action runs, the reply card is gone and Cancel takes its place.
        self::assertCount(0, $crawler->filter('#inbox-item-'.$item->number.' .inbox-split'));
        $cancel = $you->filter('form.lp-inbox-thread__cancel');
        self::assertSame($this->cancelPath($item, $action), parse_url((string) $cancel->attr('action'), \PHP_URL_PATH));
    }

    public function test_the_you_card_says_when_no_bridge_is_online(): void
    {
        $item = $this->runItem();
        $this->post($this->actionPath($item, 'resume-run'), $item, []);
        $this->bridge->lastSeenAt = new \DateTimeImmutable('-1 day');
        $this->em()->flush();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl().'?item='.$item->number);

        self::assertSame('No bridge is online.', trim($crawler->filter('[data-inbox-action-offline]')->text()));
    }

    public function test_a_cancel_withdraws_the_queued_action_and_brings_the_reply_card_back(): void
    {
        $item = $this->runItem();
        $this->post($this->actionPath($item, 'resume-run'), $item, ['note' => 'Carry on.']);
        $action = $this->onlyAction($item);

        $this->post($this->cancelPath($item, $action), $item, [], 'inbox_action_cancel_'.$action->id);

        self::assertStringStartsWith($this->pageUrl().'?ask=', (string) $this->client->getResponse()->headers->get('Location'));
        $this->em()->clear();
        $stored = $this->em()->find(InboxAction::class, $action->id);
        self::assertNotNull($stored?->cancelledAt);
        self::assertSame(BridgeCommandState::Cancelled, $this->em()->find(BridgeCommand::class, $action->bridgeCommandId)?->state);
        $crawler = $this->client->followRedirect();
        self::assertSame('Cancelled', trim($crawler->filter('[data-inbox-action-status="cancelled"] .lp-status-chip')->text()));
        self::assertCount(1, $crawler->filter('#inbox-item-'.$item->number.' .inbox-split'));
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_a_cancel_of_an_action_that_is_not_pending_is_refused_in_place(): void
    {
        $item = $this->runItem();
        $this->post($this->actionPath($item, 'resume-run'), $item, []);
        $action = $this->onlyAction($item);
        $command = $this->em()->find(BridgeCommand::class, $action->bridgeCommandId) ?? throw new \LogicException('Stored.');
        $command->state = BridgeCommandState::Done;
        $this->em()->flush();

        $this->post($this->cancelPath($item, $action), $item, [], 'inbox_action_cancel_'.$action->id);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->onlyAction($item)->cancelledAt);
    }

    public function test_a_retry_releases_the_pause(): void
    {
        $item = $this->pauseItem(PauseKind::Retries);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        self::assertSame('Retry the card', trim($crawler->filter('.inbox-split__main')->text()));

        $this->post($this->actionPath($item, 'retry-card'), $item, ['note' => 'Try again']);

        self::assertResponseRedirects();
        self::assertNull($this->service(CardPauses::class)->findActive($this->card->id ?? throw new \LogicException('Stored.')));
        self::assertSame(InboxActionKind::RetryCard, $this->onlyAction($item)->kind);
    }

    public function test_a_rule_pause_defaults_to_open_and_offers_no_retry(): void
    {
        $this->pauseItem(PauseKind::Rule);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $main = $crawler->filter('.inbox-split__main');
        self::assertSame('Open', trim($main->text()));
        self::assertSame('/projects/'.$this->project->id.'/board/cards/'.$this->card->id, $main->attr('href'));
        self::assertCount(0, $crawler->filter('[data-inbox-action="retry-card"]'));
    }

    public function test_a_dismiss_through_the_run_route_closes_the_item_with_the_note(): void
    {
        $item = $this->runItem();

        $this->post($this->actionPath($item, 'dismiss'), $item, ['note' => 'Not now']);

        self::assertResponseRedirects();
        $stored = $this->reload($item);
        self::assertSame([InboxItemState::Declined, 'Not now'], [$stored->state, $stored->closeNote]);
    }

    public function test_the_menu_dismiss_posts_to_the_decline_route_and_ignores_the_note(): void
    {
        $item = $this->runItem();
        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        $form = $crawler->filter('form#inbox_decline_'.$item->id);
        self::assertCount(1, $form);
        self::assertSame('/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/decline', parse_url((string) $form->attr('action'), \PHP_URL_PATH));
        // Dismiss ignores the note, so the form carries none.
        self::assertNull($crawler->filter('textarea')->attr('data-inbox-decline-target'));
        self::assertSame('', (string) $form->filter('input[type="hidden"][name$="[closeNote]"]')->attr('value'));
        self::assertNull($crawler->filter('button[data-inbox-action="dismiss"]')->attr('data-action'));
    }

    public function test_a_note_over_the_limit_is_refused_and_keeps_the_typed_note(): void
    {
        $item = $this->runItem();

        $this->post($this->actionPath($item, 'resume-run'), $item, ['note' => str_repeat('a', InboxAction::MAX_NOTE_LENGTH + 1)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->actionsOf($item));
        self::assertSelectorExists('#inbox-item-'.$item->number.' .inbox-split');
        self::assertSelectorExists('#inbox-item-'.$item->number.' .lp-field-errors');
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_an_action_that_does_not_fit_is_refused_in_place(): void
    {
        $item = $this->runItem(state: WorkerRunState::Running);

        $this->post($this->actionPath($item, 'resume-run'), $item, []);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-'.$item->number, 'This action no longer fits the item.');
        self::assertSame([], $this->actionsOf($item));
    }

    public function test_a_forged_token_changes_nothing(): void
    {
        $item = $this->runItem();
        $url = $this->actionPath($item, 'resume-run');

        $this->client->request(Request::METHOD_POST, $url, ['inbox_action_'.$item->id => ['note' => 'x', '_token' => 'forged']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->actionsOf($item));
    }

    public function test_a_stranger_cannot_run_or_cancel_an_action(): void
    {
        $item = $this->runItem();
        $this->post($this->actionPath($item, 'resume-run'), $item, []);
        $action = $this->onlyAction($item);
        $this->client->loginUser($this->signedUpUser($this->em(), 'inbox-stranger'));

        $this->post($this->actionPath($item, 'retry-card'), $item, []);
        self::assertResponseStatusCodeSame(403);
        $this->post($this->cancelPath($item, $action), $item, [], 'inbox_action_cancel_'.$action->id);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->onlyAction($item)->cancelledAt);
    }

    public function test_the_routes_answer_404_while_the_inbox_is_off_and_for_an_unknown_kind(): void
    {
        $item = $this->runItem();

        $this->post($this->actionPath($item, 'review'), $item, []);
        self::assertResponseStatusCodeSame(404);

        $this->setInboxFlag(false);
        $this->post($this->actionPath($item, 'resume-run'), $item, []);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->actionsOf($item));
    }

    public function test_an_item_of_another_project_is_not_found_under_this_one(): void
    {
        $item = $this->runItem();
        $other = $this->inboxProject($this->em(), $this->project->owner);

        $url = '/projects/'.$other->id.'/inbox/items/'.$item->id.'/actions/resume-run';
        $this->client->request(Request::METHOD_POST, $url, ['inbox_action_'.$item->id => ['_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_card_page_shows_the_thread_posts_back_to_the_card_and_offers_no_link_to_itself(): void
    {
        $item = $this->runItem('Needs the API key.', linkCard: true);

        $crawler = $this->client->request(Request::METHOD_GET, $this->cardUrl());

        self::assertResponseIsSuccessful();
        $thread = $crawler->filter('[data-inbox-linked="card"] [data-inbox-thread]');
        self::assertCount(1, $thread);
        self::assertSame('Resume the run', trim($thread->filter('.inbox-split__main')->text()));
        self::assertStringContainsString('returnTo=card', (string) $thread->filter('form.lp-inbox-thread__reply')->attr('action'));
        self::assertCount(0, $thread->filter('[data-inbox-action="open"]'));

        $url = $this->actionPath($item, 'resume-run').'?returnTo=card&returnId='.$this->card->id;
        $this->client->request(Request::METHOD_POST, $url, ['inbox_action_'.$item->id => ['note' => 'Go', '_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseRedirects($this->cardUrl().'?tab=conversation');
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('[data-inbox-linked="card"] [data-inbox-action-status="queued"]'));
    }

    public function test_the_card_page_starts_a_session_from_the_main_part(): void
    {
        $session = new Bridge($this->project->owner, Uuid::v4(), [$this->project->id?->toRfc4122() ?? ''], 'b4e39aa7', new \DateTimeImmutable());
        $session->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $this->em()->persist($session);
        $this->em()->flush();
        $item = $this->runItem(state: WorkerRunState::Running, linkCard: true);

        $crawler = $this->client->request(Request::METHOD_GET, $this->cardUrl());

        self::assertResponseIsSuccessful();
        $main = $crawler->filter('[data-inbox-linked="card"] .inbox-split__main');
        self::assertSame('start-session', $main->attr('data-inbox-action'));
        self::assertSame($this->actionPath($item, 'start-session'), parse_url((string) $main->attr('formaction'), \PHP_URL_PATH));

        $url = $this->actionPath($item, 'start-session').'?returnTo=card&returnId='.$this->card->id;
        $this->client->request(Request::METHOD_POST, $url, ['inbox_action_'.$item->id => ['note' => 'Pair on it', '_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseRedirects($this->cardUrl().'?tab=conversation');
        self::assertSame(InboxActionKind::StartSession, $this->onlyAction($item)->kind);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('The session of item '.$item->number.' is queued.', $crawler->text());
        self::assertCount(1, $crawler->filter('[data-inbox-linked="card"] [data-inbox-action-status="queued"]'));
        // The session answers the open wait, so the queued You card replaces the reply.
        self::assertNotNull($this->onlyAction($item)->waitId);
        self::assertCount(0, $crawler->filter('[data-inbox-linked="card"] .inbox-split'));
    }

    public function test_a_session_todo_shows_the_prompt_in_an_editable_field_and_sends_the_edit(): void
    {
        $session = new Bridge($this->project->owner, Uuid::v4(), [$this->project->id?->toRfc4122() ?? ''], 'b4e39aa7', new \DateTimeImmutable());
        $session->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $session->lastSeenAt = new \DateTimeImmutable();
        $this->em()->persist($session);
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Rebuild it', blocking: false);
        $item->suggestedActions = [['action' => 'start-session', 'reason' => 'Talk it through.', 'recommended' => true, 'cardId' => null, 'command' => null, 'proposedEntry' => null, 'prompt' => 'Use the <skill>.']];
        $item->cards->add(new InboxItemCard($item, $this->card));
        $this->em()->persist($item);
        $this->em()->flush();
        $this->askHolding($this->em(), $this->project, [$item]);
        $this->em()->flush();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $field = $crawler->filter('textarea[data-inbox-session-prompt]');
        self::assertCount(1, $field);
        self::assertSame('inbox_action_'.$item->id.'[sessionPrompt]', $field->attr('name'));
        self::assertSame('Use the <skill>.', trim($field->text()));

        $this->post($this->actionPath($item, 'start-session'), $item, ['note' => '', 'sessionPrompt' => 'Use the <skill>. Part 1 only.']);

        self::assertSame('Use the <skill>. Part 1 only.', $this->onlyAction($item)->sessionPrompt);
    }

    public function test_a_session_prompt_field_the_owner_cleared_starts_the_session_with_no_prompt(): void
    {
        $session = new Bridge($this->project->owner, Uuid::v4(), [$this->project->id?->toRfc4122() ?? ''], 'b4e39aa7', new \DateTimeImmutable());
        $session->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $session->lastSeenAt = new \DateTimeImmutable();
        $this->em()->persist($session);
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Rebuild it', blocking: false);
        $item->suggestedActions = [['action' => 'start-session', 'reason' => 'Talk it through.', 'recommended' => true, 'cardId' => null, 'command' => null, 'proposedEntry' => null, 'prompt' => 'Use the skill.']];
        $item->cards->add(new InboxItemCard($item, $this->card));
        $this->em()->persist($item);
        $this->em()->flush();
        $this->askHolding($this->em(), $this->project, [$item]);
        $this->em()->flush();

        $this->post($this->actionPath($item, 'start-session'), $item, ['note' => '', 'sessionPrompt' => '']);

        self::assertNull($this->onlyAction($item)->sessionPrompt);
    }

    public function test_a_question_keeps_its_answer_form_with_the_heading_your_answer(): void
    {
        $question = $this->question($this->em(), $this->project, 40);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('Your answer', trim($crawler->filter('#inbox-item-'.$question->number.' legend.lp-decision__prompt')->text()));
        self::assertCount(0, $crawler->filter('#inbox-item-'.$question->number.' [data-inbox-thread]'));
    }

    public function test_a_question_offers_its_suggested_session_below_the_answer_form_and_starts_it(): void
    {
        $this->sessionBridge();
        $question = $this->sessionQuestion([['action' => 'start-session', 'reason' => 'Pair on the answer.', 'recommended' => true, 'cardId' => null, 'command' => null, 'proposedEntry' => null, 'prompt' => 'Use the skill.']]);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $item = $crawler->filter('#inbox-item-'.$question->number);
        self::assertCount(1, $item->filter('form.lp-inbox-answer'));
        self::assertSame('start-session', $item->filter('[data-inbox-thread] .inbox-split__main')->attr('data-inbox-action'));
        self::assertSame('Pair on the answer.', trim($item->filter('[data-inbox-reply-heading]')->text()));
        self::assertSame('Use the skill.', trim($item->filter('textarea[data-inbox-session-prompt]')->text()));
        self::assertCount(0, $item->filter('[data-inbox-thread] textarea[name$="[note]"]'));

        $this->post($this->actionPath($question, 'start-session'), $question, ['sessionPrompt' => 'Use the skill.']);

        self::assertSame('Use the skill.', $this->onlyAction($question)->sessionPrompt);
    }

    public function test_a_question_with_a_card_and_no_suggestion_shows_no_reply_card(): void
    {
        $this->sessionBridge();
        $question = $this->sessionQuestion(null);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#inbox-item-'.$question->number.' form.lp-inbox-answer'));
        self::assertCount(0, $crawler->filter('#inbox-item-'.$question->number.' [data-inbox-thread]'));
    }

    public function test_a_run_command_posts_the_confirmed_branch_and_values_and_queues_the_request(): void
    {
        $item = $this->commandTodo();

        $this->post($this->actionPath($item, 'run-command'), $item, ['note' => '', 'branch' => 'main', 'params' => ['out' => 'other/folder'], 'runDigest' => self::shownDigest()]);

        self::assertStringStartsWith($this->pageUrl().'?ask=', (string) $this->client->getResponse()->headers->get('Location'));
        $action = $this->onlyAction($item);
        self::assertSame(InboxActionKind::RunCommand, $action->kind);
        $request = $this->em()->find(WorkRequest::class, $action->workRequestId);
        self::assertInstanceOf(WorkRequest::class, $request);
        self::assertSame(['design-export', (string) $this->bridge->id, 'main', ['out' => 'other/folder'], ['bin/console', 'app:export', '{out}'], WorkRequestState::Open], [$request->kind, (string) $request->targetBridgeId, $request->branch, $request->params, $request->run, $request->state]);
    }

    public function test_a_run_command_whose_entry_changed_since_the_dialog_is_refused_in_place_and_queues_nothing(): void
    {
        $item = $this->commandTodo();
        $this->bridge->commandEntries = [['name' => 'design-export', 'description' => null, 'params' => [['name' => 'out', 'pattern' => '[a-z/]+', 'description' => null]], 'run' => ['bin/console', 'app:export', '--force', '{out}'], 'timeout' => '10m']];
        $this->em()->flush();

        $this->post($this->actionPath($item, 'run-command'), $item, ['note' => '', 'runDigest' => self::shownDigest()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-'.$item->number, 'The command changed on the bridge since you opened this dialog.');
        self::assertSame([], $this->actionsOf($item));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM work_requests'));
    }

    public function test_a_run_command_without_confirmed_values_runs_the_values_of_the_suggestion(): void
    {
        $item = $this->commandTodo();

        $this->post($this->actionPath($item, 'run-command'), $item, ['note' => '', 'runDigest' => self::shownDigest()]);

        $request = $this->em()->find(WorkRequest::class, $this->onlyAction($item)->workRequestId);
        self::assertSame(['epic/42', ['out' => 'var/out']], [$request?->branch, $request?->params]);
    }

    public function test_a_run_command_with_a_bad_value_is_refused_in_place_and_queues_nothing(): void
    {
        $item = $this->commandTodo();

        $this->post($this->actionPath($item, 'run-command'), $item, ['note' => '', 'params' => ['out' => '--exec=1'], 'runDigest' => self::shownDigest()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-'.$item->number, 'A value does not fit its pattern.');
        self::assertSame([], $this->actionsOf($item));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM work_requests'));
    }

    public function test_a_command_todo_shows_the_split_button_and_the_confirm_dialog(): void
    {
        $item = $this->commandTodo();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $main = $crawler->filter('.inbox-split__main');
        self::assertSame('Run design-export on epic/42', trim($main->text()));
        self::assertSame('button', $main->attr('type'));
        self::assertNull($main->attr('formaction'));
        self::assertStringContainsString('modal#open', (string) $main->attr('data-action'));
        self::assertCount(1, $crawler->filter('[data-inbox-done]'));
        $formId = 'inbox_action_'.$item->id;
        $dialog = $crawler->filter('[data-inbox-run-command-dialog]');
        self::assertCount(1, $dialog);
        self::assertSame('Run design-export?', trim($dialog->filter('.lp-dialog-title')->text()));
        self::assertStringContainsString('the bridge that sent the to-do', $dialog->filter('.lp-run-command__facts')->text());
        $options = $dialog->filter('select[name="'.$formId.'[branch]"] option');
        self::assertSame(['epic/42', 'main'], $options->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame('epic/42', $dialog->filter('select option[selected]')->attr('value'));
        self::assertSame('/work/loupe/.worktrees/card-42', $options->first()->attr('data-folder'));
        self::assertSame('var/out', $dialog->filter('input[name="'.$formId.'[params][out]"]')->attr('value'));
        $digest = $dialog->filter('input[name="'.$formId.'[runDigest]"]');
        self::assertSame([self::shownDigest(), $formId], [$digest->attr('value'), $digest->attr('form')]);
        self::assertSame('bin/console app:export var/out', trim($dialog->filter('[data-testid="run-command-argv"]')->text()));
        self::assertSame('Command, in /work/loupe/.worktrees/card-42', trim($dialog->filter('.lp-code-dark__label')->text()));
        self::assertStringContainsString('A success marks the to-do done.', $dialog->text());
        $run = $dialog->filter('[data-inbox-action="run-command-confirm"]');
        self::assertSame($this->actionPath($item, 'run-command'), parse_url((string) $run->attr('formaction'), \PHP_URL_PATH));
        self::assertSame($formId, $run->attr('form'));
        self::assertNull($run->attr('disabled'));
        self::assertCount(1, $crawler->filter('form#'.$formId));
    }

    public function test_a_failed_command_shows_its_last_lines_with_run_again_and_mark_done(): void
    {
        $item = $this->commandTodo();
        $this->commandRunOf($item, WorkRequestState::Done, WorkerRunState::Failed, 2, "Starting\nSQLSTATE[08006] could not connect");

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $block = $crawler->filter('[data-inbox-run-command="failed"]');
        self::assertCount(1, $block);
        self::assertStringContainsString('Failed, exit 2', $block->filter('.lp-status-chip')->text());
        self::assertStringContainsString('SQLSTATE[08006] could not connect', $block->filter('[data-inbox-run-command-output]')->text());
        self::assertSame('Run again', trim($block->filter('[data-inbox-action="run-command-again"]')->text()));
        self::assertNull($block->filter('[data-inbox-action="run-command-again"]')->attr('disabled'));
        self::assertSame('inbox_done_'.$item->id, $block->filter('[data-inbox-run-command-done]')->attr('form'));
        self::assertCount(1, $crawler->filter('[data-inbox-run-command-dialog]'));
    }

    public function test_a_running_command_streams_its_stored_log_into_a_live_block(): void
    {
        $item = $this->commandTodo();
        $run = $this->commandRunOf($item, WorkRequestState::Claimed, WorkerRunState::Running);
        self::assertInstanceOf(WorkerRun::class, $run);
        $this->storeLog($run, "Reading tokens\nExporting 12/38 ");

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        $block = $crawler->filter('[data-inbox-run-command="running"]');
        $output = $block->filter('[data-inbox-run-command-live]');
        self::assertCount(1, $output);
        self::assertSame('run-output', $output->attr('data-controller'));
        self::assertSame('/projects/'.$this->project->id.'/worker-runs/'.$run->id.'/output', $output->attr('data-run-output-url-value'));
        self::assertSame((string) $run->id, $output->attr('data-run-output-run-id-value'));
        self::assertSame((string) \strlen("Reading tokens\nExporting 12/38 "), $output->attr('data-run-output-length-value'));
        self::assertSame('Output', trim($output->filter('.lp-code-dark__label')->text()));
        self::assertSame('Live', trim($output->filter('.lp-run-output__live')->text()));
        self::assertSame("Reading tokens\nExporting 12/38 ", $output->filter('pre code')->html());
        self::assertCount(1, $output->filter('pre .lp-run-output__cursor'));
        self::assertCount(0, $block->filter('[data-inbox-run-command-log]'));
    }

    public function test_a_running_command_with_no_output_yet_shows_an_empty_live_block(): void
    {
        $item = $this->commandTodo();
        $this->commandRunOf($item, WorkRequestState::Claimed, WorkerRunState::Running);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        $output = $crawler->filter('[data-inbox-run-command-live]');
        self::assertCount(1, $output);
        self::assertSame('0', $output->attr('data-run-output-length-value'));
        self::assertSame('', $output->filter('pre code')->text());
    }

    public function test_a_succeeded_command_shows_its_time_its_last_lines_and_a_link_to_the_full_log(): void
    {
        $item = $this->commandTodo();
        $run = $this->commandRunOf(
            $item,
            WorkRequestState::Done,
            WorkerRunState::Succeeded,
            0,
            startedAt: new \DateTimeImmutable('2026-10-16 10:00:00'),
            endedAt: new \DateTimeImmutable('2026-10-16 10:00:14'),
        );
        self::assertInstanceOf(WorkerRun::class, $run);
        $this->storeLog($run, implode('', array_map(static fn (int $line): string => 'line '.$line."\n", range(1, 12))));

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        $block = $crawler->filter('[data-inbox-run-command="succeeded"]');
        self::assertStringContainsString('Succeeded in 14 s', $block->filter('.lp-status-chip')->text());
        $output = $block->filter('[data-inbox-run-command-output]');
        self::assertSame('Output, last lines', trim($output->filter('.lp-code-dark__label')->text()));
        self::assertSame('line 3', explode("\n", $output->filter('pre code')->html())[0]);
        self::assertCount(0, $block->filter('[data-controller="run-output"]'));
        $link = $block->filter('[data-inbox-run-command-log]');
        self::assertSame('Open the full log (12 lines)', trim($link->text()));
        self::assertSame('/projects/'.$this->project->id.'/worker-runs/'.$run->id.'/log', $link->attr('href'));
        self::assertSame('_blank', $link->attr('target'));
        self::assertStringContainsString('noopener', (string) $link->attr('rel'));
    }

    public function test_a_failed_command_puts_the_log_link_left_of_its_buttons(): void
    {
        $item = $this->commandTodo();
        $run = $this->commandRunOf($item, WorkRequestState::Done, WorkerRunState::Failed, 1);
        self::assertInstanceOf(WorkerRun::class, $run);
        $this->storeLog($run, "one\ntwo\n");

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        $row = $crawler->filter('[data-inbox-run-command="failed"] .lp-form-actions');
        self::assertCount(1, $row->filter('.lp-form-actions--between'));
        self::assertSame('Open the full log (2 lines)', trim($row->filter('[data-inbox-run-command-log]')->text()));
        self::assertSame('a', $row->children()->first()->nodeName());
        self::assertCount(1, $row->filter('[data-inbox-run-command-done]'));
        self::assertCount(1, $row->filter('[data-inbox-action="run-command-again"]'));
    }

    public function test_a_command_with_no_stored_log_offers_no_log_link(): void
    {
        $item = $this->commandTodo();
        $this->commandRunOf($item, WorkRequestState::Done, WorkerRunState::Failed, 1, 'boom');

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        $block = $crawler->filter('[data-inbox-run-command="failed"]');
        self::assertStringContainsString('boom', $block->filter('[data-inbox-run-command-output]')->text());
        self::assertCount(0, $block->filter('[data-inbox-run-command-log]'));
    }

    public function test_a_queued_command_shows_the_chip_and_a_cancel_button_and_no_output(): void
    {
        $item = $this->commandTodo();
        $this->commandRunOf($item, WorkRequestState::Open);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        $block = $crawler->filter('[data-inbox-run-command="queued"]');
        self::assertCount(1, $block);
        self::assertStringContainsString('Queued', $block->filter('.lp-status-chip')->text());
        self::assertCount(1, $block->filter('form.lp-inbox-thread__cancel'));
        self::assertCount(0, $block->filter('[data-inbox-action="run-command-again"]'));
        self::assertNotNull($crawler->filter('.inbox-split__main')->attr('disabled'));
        self::assertCount(0, $block->filter('[data-inbox-run-command-offline]'));
    }

    public function test_a_command_queued_for_a_quiet_bridge_says_no_bridge_is_online(): void
    {
        $item = $this->commandTodo();
        $this->commandRunOf($item, WorkRequestState::Open);
        $this->bridge->lastSeenAt = new \DateTimeImmutable('-1 day');
        $this->em()->flush();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        $block = $crawler->filter('[data-inbox-run-command="queued"]');
        self::assertSame('No bridge is online.', trim($block->filter('[data-inbox-run-command-offline]')->text()));
        self::assertCount(1, $block->filter('form.lp-inbox-thread__cancel'));
    }

    public function test_a_refused_command_shows_the_refusal_and_run_again(): void
    {
        $item = $this->commandTodo();
        $this->commandRunOf($item, WorkRequestState::Refused, reason: 'worktree-missing');

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        $block = $crawler->filter('[data-inbox-run-command="refused"]');
        self::assertSame('The bridge found no worktree for this branch.', trim($block->filter('[data-inbox-run-command-reason]')->text()));
        self::assertCount(1, $block->filter('[data-inbox-action="run-command-again"]'));
        self::assertCount(0, $block->filter('[data-inbox-run-command-done]'));
    }

    public function test_a_missing_entry_of_a_bridge_that_did_not_opt_in_shows_the_named_entry_with_copy_and_a_disabled_run_button(): void
    {
        $this->proposalTodo(optIn: false);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $proposed = $crawler->filter('[data-inbox-run-command-proposed]');
        self::assertCount(1, $proposed);
        self::assertStringContainsString('design-export: action: command', $proposed->filter('pre')->text());
        self::assertSame('Proposed entry, add it under work:', trim($proposed->filter('.lp-code-dark__label')->text()));
        self::assertSame('Copy', trim($proposed->filter('.lp-copy-button__chip')->text()));
        self::assertSame('design-export is not in the rules.yaml of '.$this->bridge->label.', and that bridge does not accept added entries.', trim($crawler->filter('[data-inbox-run-command-off]')->text()));
        self::assertStringContainsString('loupe bridge reload', $crawler->filter('.lp-comment-composer')->text());
        self::assertNotNull($crawler->filter('.inbox-split__main')->attr('disabled'));
        self::assertCount(0, $crawler->filter('[data-inbox-run-command-dialog]'));
        self::assertCount(0, $crawler->filter('[data-inbox-add-entry-dialog]'));
        self::assertCount(0, $crawler->filter('[data-inbox-action="add-entry"]'));
    }

    public function test_a_missing_entry_with_no_proposal_keeps_the_plain_reason(): void
    {
        $this->commandTodo();
        $this->bridge->commandEntries = [];
        $this->em()->flush();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertSame('The rule file of the bridge has no such command.', trim($crawler->filter('[data-inbox-run-command-off]')->text()));
        self::assertCount(0, $crawler->filter('[data-inbox-run-command-proposed]'));
    }

    public function test_a_missing_entry_of_a_bridge_that_opted_in_offers_add_to_rules_yaml_and_its_confirm_dialog(): void
    {
        $item = $this->proposalTodo(optIn: true);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $main = $crawler->filter('.inbox-split__main');
        self::assertSame('Add to rules.yaml', trim($main->text()));
        self::assertSame(['button', 'add-entry'], [$main->attr('type'), $main->attr('data-inbox-action')]);
        self::assertNull($main->attr('disabled'));
        self::assertStringContainsString('modal#open', (string) $main->attr('data-action'));
        self::assertSame('design-export is not in the rules.yaml of '.$this->bridge->label.'. The agent proposes this entry.', trim($crawler->filter('[data-inbox-run-command-off]')->text()));
        $proposed = $crawler->filter('[data-inbox-run-command-proposed]');
        self::assertSame('Proposed entry', trim($proposed->filter('.lp-code-dark__label')->text()));
        self::assertCount(0, $proposed->filter('.lp-copy-button__chip'));
        self::assertSame('modal', $crawler->filter('[data-inbox-thread]')->attr('data-controller'));
        self::assertCount(0, $crawler->filter('[data-inbox-run-command-dialog]'));

        $formId = 'inbox_action_'.$item->id;
        $dialog = $crawler->filter('[data-inbox-add-entry-dialog]');
        self::assertCount(1, $dialog);
        self::assertSame('Add design-export to rules.yaml?', trim($dialog->filter('.lp-dialog-title')->text()));
        self::assertStringContainsString($this->bridge->label.' adds it to its rules.yaml and reloads. Nothing runs yet.', $dialog->filter('.lp-dialog-desc')->text());
        self::assertStringContainsString('design-export: action: command', $dialog->filter('[data-testid="add-entry-yaml"]')->text());
        self::assertCount(0, $dialog->filter('.lp-copy-button__chip'));
        $confirm = $dialog->filter('[data-inbox-action="add-entry-confirm"]');
        self::assertSame('Add the entry', trim($confirm->text()));
        self::assertSame($this->actionPath($item, 'add-entry'), parse_url((string) $confirm->attr('formaction'), \PHP_URL_PATH));
        self::assertSame($formId, $confirm->attr('form'));
        self::assertCount(1, $crawler->filter('form#'.$formId));
    }

    public function test_the_menu_offers_add_to_rules_yaml_when_another_action_is_the_main_button(): void
    {
        $this->proposalTodo(optIn: true, recommendRun: false);

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertSame('Open', trim($crawler->filter('.inbox-split__main')->text()));
        $row = $crawler->filter('.inbox-split .lp-menu__panel [data-inbox-action="add-entry"]');
        self::assertCount(1, $row);
        self::assertSame('Add to rules.yaml', trim($row->filter('.lp-menu__label')->text()));
        self::assertStringContainsString('modal#open', (string) $row->attr('data-action'));
        self::assertCount(1, $crawler->filter('[data-inbox-add-entry-dialog]'));
    }

    public function test_adding_an_entry_queues_a_request_for_the_bridge_and_the_thread_shows_it(): void
    {
        $item = $this->proposalTodo(optIn: true);

        $this->post($this->actionPath($item, 'add-entry'), $item, ['note' => '']);

        self::assertStringStartsWith($this->pageUrl().'?ask=', (string) $this->client->getResponse()->headers->get('Location'));
        $action = $this->onlyAction($item);
        self::assertSame(InboxActionKind::AddEntry, $action->kind);
        $request = $this->em()->find(WorkRequest::class, $action->workRequestId);
        self::assertInstanceOf(WorkRequest::class, $request);
        self::assertSame(['add-entry', 'add-entry', 'inbox:add-entry', InboxItem::SUBJECT_TYPE, (string) $this->bridge->id, WorkRequestState::Open], [$request->kind, $request->capability, $request->ruleId, $request->subjectType, (string) $request->targetBridgeId, $request->state]);
        self::assertSame("design-export:\n  action: command\n", $request->prompt);

        $crawler = $this->client->followRedirect();
        $you = $crawler->filter('#inbox-item-'.$item->number.' [data-inbox-action-status="queued"]');
        self::assertCount(1, $you);
        self::assertStringContainsString('added an entry at', $you->filter('.lp-form-hint')->text());
        self::assertCount(1, $you->filter('form.lp-inbox-thread__cancel'));
        self::assertCount(0, $crawler->filter('[data-inbox-action="add-entry"]'));
        self::assertCount(0, $crawler->filter('[data-inbox-add-entry-dialog]'));
        self::assertSame($this->bridge->label.' is adding design-export to its rules.yaml.', trim($crawler->filter('[data-inbox-run-command-off]')->text()));
        self::assertSelectorTextContains('body', 'Entry queued');
    }

    public function test_a_done_add_reads_added_and_the_run_button_waits_for_the_next_heartbeat(): void
    {
        $item = $this->proposalTodo(optIn: true);
        $this->post($this->actionPath($item, 'add-entry'), $item, ['note' => '']);
        $request = $this->em()->find(WorkRequest::class, $this->onlyAction($item)->workRequestId) ?? throw new \LogicException('Stored.');
        $request->state = WorkRequestState::Done;
        $request->settledAt = new \DateTimeImmutable();
        $this->em()->flush();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        $status = $crawler->filter('#inbox-item-'.$item->number.' [data-inbox-action-status="added"]');
        self::assertCount(1, $status);
        self::assertStringContainsString('Added to rules.yaml', $status->filter('.lp-status-chip')->text());
        self::assertStringContainsString('lp-status-chip--ok', (string) $status->filter('.lp-status-chip')->attr('class'));

        $bridge = $this->service(BridgeRepository::class)->findOneBy(['id' => $this->bridge->id]) ?? throw new \LogicException('Stored.');
        $bridge->commandEntries = [['name' => 'design-export', 'description' => null, 'params' => [['name' => 'out', 'pattern' => '[a-z/]+', 'description' => null]], 'run' => ['bin/console', 'app:export', '{out}'], 'timeout' => '10m']];
        $this->em()->flush();
        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertSame('Run design-export on epic/42', trim($crawler->filter('.inbox-split__main')->text()));
        self::assertNull($crawler->filter('.inbox-split__main')->attr('disabled'));
        self::assertCount(1, $crawler->filter('[data-inbox-run-command-dialog]'));
    }

    public function test_a_refused_add_shows_the_reason_and_offers_the_add_again(): void
    {
        $item = $this->proposalTodo(optIn: true);
        $this->post($this->actionPath($item, 'add-entry'), $item, ['note' => '']);
        $request = $this->em()->find(WorkRequest::class, $this->onlyAction($item)->workRequestId) ?? throw new \LogicException('Stored.');
        $request->state = WorkRequestState::Refused;
        $request->reason = 'write-failed';
        $request->settledAt = new \DateTimeImmutable();
        $this->em()->flush();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertStringContainsString('Refused: write-failed', $crawler->filter('[data-inbox-action-status="refused"] .lp-status-chip')->text());
        self::assertSame('Add to rules.yaml', trim($crawler->filter('.inbox-split__main')->text()));
    }

    public function test_cancelling_a_queued_add_withdraws_its_request(): void
    {
        $item = $this->proposalTodo(optIn: true);
        $this->post($this->actionPath($item, 'add-entry'), $item, ['note' => '']);
        $action = $this->onlyAction($item);

        $this->post($this->cancelPath($item, $action), $item, [], 'inbox_action_cancel_'.$action->id);

        self::assertNotNull($this->onlyAction($item)->cancelledAt);
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $action->workRequestId)?->state);
    }

    public function test_adding_an_entry_the_page_does_not_offer_is_refused_in_place(): void
    {
        $item = $this->proposalTodo(optIn: false);

        $this->post($this->actionPath($item, 'add-entry'), $item, ['note' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-'.$item->number, 'This action no longer fits the item.');
        self::assertSame([], $this->actionsOf($item));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM work_requests'));
    }

    public function test_a_stranger_cannot_add_an_entry(): void
    {
        $item = $this->proposalTodo(optIn: true);
        $this->client->loginUser($this->signedUpUser($this->em(), 'inbox-stranger'));

        $this->post($this->actionPath($item, 'add-entry'), $item, ['note' => '']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->actionsOf($item));
    }

    public function test_a_branch_without_a_worktree_opens_the_dialog_with_run_off(): void
    {
        $this->commandTodo();
        $this->bridge->worktrees = [['projectId' => (string) $this->project->id, 'branch' => 'main', 'path' => '/work/loupe']];
        $this->em()->flush();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertNull($crawler->filter('.inbox-split__main')->attr('disabled'));
        $dialog = $crawler->filter('[data-inbox-run-command-dialog]');
        self::assertNotNull($dialog->filter('[data-inbox-action="run-command-confirm"]')->attr('disabled'));
        self::assertSame('No worktree for epic/42 on '.$this->bridge->label, trim($dialog->filter('[data-inbox-run-command-no-worktree]')->text()));
        self::assertNull($dialog->filter('[data-inbox-run-command-no-worktree]')->attr('hidden'));
        self::assertSame(['epic/42', 'main'], $dialog->filter('select option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        $off = $dialog->filter('[data-inbox-run-command-branch-off]');
        self::assertSame('epic/42, no worktree on '.$this->bridge->label, trim($off->text()));
        self::assertNotNull($off->attr('disabled'));
        self::assertNotNull($off->attr('selected'));
        self::assertSame('', $off->attr('data-folder'));
    }

    private function commandRunOf(InboxItem $item, WorkRequestState $state, ?WorkerRunState $runState = null, ?int $exitCode = null, string $output = '', ?string $reason = null, ?\DateTimeImmutable $startedAt = null, ?\DateTimeImmutable $endedAt = null): ?WorkerRun
    {
        $request = new WorkRequest(
            project: $this->project,
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('Stored.'),
            cardNumber: $this->card->number,
            kind: 'design-export',
            capability: Bridge::CAPABILITY_RUN_COMMAND,
            ruleId: 'inbox:run-command',
            createdAt: new \DateTimeImmutable(),
        );
        $request->state = $state;
        $request->reason = $reason;
        $request->settledAt = WorkRequestState::Open === $state ? null : new \DateTimeImmutable();
        $this->em()->persist($request);
        $this->em()->flush();
        $action = new InboxAction($item, InboxActionKind::RunCommand, $this->project->owner, '', new \DateTimeImmutable());
        $action->workRequestId = $request->id;
        $this->em()->persist($action);
        $run = null;
        if (null !== $runState) {
            $this->em()->persist($run = new WorkerRun(
                project: $this->project,
                bridgeId: $this->bridge->id,
                subjectType: WorkSubject::CARD,
                subjectId: $this->card->id,
                cardNumber: $this->card->number,
                workKind: 'design-export',
                state: $runState,
                runKey: Uuid::v7(),
                startedAt: $startedAt,
                endedAt: $endedAt,
                exitCode: $exitCode,
                output: $output,
                workRequestId: $request->id,
            ));
        }
        $this->em()->flush();

        return $run;
    }

    private function storeLog(WorkerRun $run, string $text): void
    {
        $this->em()->persist(new WorkerRunOutputChunk($run, 0, \strlen($text), $text));
        $this->em()->flush();
    }

    /** A to-do whose bridge lacks the entry that the agent proposes, with or without the opt-in to add entries. */
    private function proposalTodo(bool $optIn, bool $recommendRun = true): InboxItem
    {
        $item = $this->commandTodo();
        $this->bridge->commandEntries = [];
        if ($optIn) {
            $this->bridge->capabilities = [...($this->bridge->capabilities ?? []), Bridge::CAPABILITY_ADD_ENTRY];
        }
        $item->suggestedActions = [
            ['action' => 'run-command', 'reason' => 'Export it.', 'recommended' => $recommendRun, 'cardId' => null, 'command' => ['entry' => 'design-export', 'branch' => 'epic/42', 'params' => ['out' => 'var/out']], 'proposedEntry' => 'action: command'],
            ['action' => 'open', 'reason' => 'See the card.', 'recommended' => !$recommendRun, 'cardId' => null, 'command' => null, 'proposedEntry' => null],
        ];
        $this->em()->flush();

        return $item;
    }

    private function commandTodo(): InboxItem
    {
        $this->bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_RUN_COMMAND];
        $this->bridge->projects = [(string) $this->project->id];
        $this->bridge->lastSeenAt = new \DateTimeImmutable();
        $this->bridge->commandEntries = [['name' => 'design-export', 'description' => null, 'params' => [['name' => 'out', 'pattern' => '[a-z/]+', 'description' => null]], 'run' => ['bin/console', 'app:export', '{out}'], 'timeout' => '10m']];
        $this->bridge->worktrees = [
            ['projectId' => (string) $this->project->id, 'branch' => 'main', 'path' => '/work/loupe'],
            ['projectId' => (string) $this->project->id, 'branch' => 'epic/42', 'path' => '/work/loupe/.worktrees/card-42'],
        ];
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Todo, title: 'Export the design system', blocking: false);
        $item->suggestedActions = [['action' => 'run-command', 'reason' => 'Export it.', 'recommended' => true, 'cardId' => null, 'command' => ['entry' => 'design-export', 'branch' => 'epic/42', 'params' => ['out' => 'var/out']], 'proposedEntry' => null]];
        $item->cards->add(new InboxItemCard($item, $this->card));
        $this->em()->persist($item);
        $this->em()->flush();
        $ask = $this->askHolding($this->em(), $this->project, [$item]);
        $ask->bridgeId = $this->bridge->id;
        $this->em()->flush();

        return $item;
    }

    private function runItem(string $report = 'Needs the API key.', WorkerRunState $state = WorkerRunState::Blocked, bool $linkCard = false): InboxItem
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: $this->bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('Stored.'),
            cardNumber: $this->card->number,
            workKind: 'implement',
            state: $state,
            runKey: Uuid::v7(),
            sessionId: Uuid::v4(),
            output: $report,
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return $this->itemWithWait(static fn (InboxCardWatch $watch): InboxCardWait => new InboxCardWait($watch, InboxCardWaitTrigger::RunBlocked, InboxCardWaitType::WorkerRun, InboxCardWaitReason::Blocked, runId: $run->id), $linkCard);
    }

    private function pauseItem(PauseKind $kind): InboxItem
    {
        $pause = $this->service(PauseCardHandler::class)(new PauseCardCommand($this->card, 'on-hold', 'review-rule', $kind))
            ?? throw new \LogicException('The card had no pause.');

        return $this->itemWithWait(static fn (InboxCardWatch $watch): InboxCardWait => new InboxCardWait($watch, InboxCardWaitTrigger::CardPaused, InboxCardWaitType::CardPause, InboxCardWaitReason::forPause($kind), pauseId: $pause->id));
    }

    /** @param \Closure(InboxCardWatch): InboxCardWait $wait */
    private function itemWithWait(\Closure $wait, bool $linkCard = false): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true);
        $this->em()->persist($item);
        $watch = new InboxCardWatch($item, $this->card->id ?? throw new \LogicException('Stored.'), $this->card->number);
        $this->em()->persist($watch);
        $watch->waits->add($wait($watch));
        if ($linkCard) {
            $item->cards->add(new InboxItemCard($item, $this->card));
        }
        $this->em()->flush();
        $this->askHolding($this->em(), $this->project, [$item]);

        return $item;
    }

    /** The digest of the run list of the design-export entry that the dialog shows. */
    private static function shownDigest(): string
    {
        $run = ['bin/console', 'app:export', '{out}'];

        return hash('sha256', json_encode([$run, $run], \JSON_THROW_ON_ERROR));
    }

    private function sessionBridge(): void
    {
        $session = new Bridge($this->project->owner, Uuid::v4(), [$this->project->id?->toRfc4122() ?? ''], 'b4e39aa7', new \DateTimeImmutable());
        $session->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_SESSION];
        $session->lastSeenAt = new \DateTimeImmutable();
        $this->em()->persist($session);
        $this->em()->flush();
    }

    /** @param list<array{action: string, reason: string, recommended: bool, cardId: ?string, command: null, proposedEntry: null, prompt: ?string}>|null $suggestions */
    private function sessionQuestion(?array $suggestions): InboxItem
    {
        $item = new InboxItem(project: $this->project, number: ++$this->number, kind: InboxItemKind::Question, title: 'Which tag order?', blocking: true, options: ['By name', 'By use'], freeText: true);
        $item->suggestedActions = $suggestions;
        $item->cards->add(new InboxItemCard($item, $this->card));
        $this->em()->persist($item);
        $this->em()->flush();
        $this->askHolding($this->em(), $this->project, [$item]);
        $this->em()->flush();

        return $item;
    }

    /** @param array<string, string|array<string, string>> $fields */
    private function post(string $url, InboxItem $item, array $fields, ?string $formName = null): Crawler
    {
        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel, which a same-origin Referer lets stand in for a signed token.
        return $this->client->request(Request::METHOD_POST, $url, [($formName ?? 'inbox_action_'.$item->id) => [...$fields, '_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
    }

    private function actionPath(InboxItem $item, string $kind): string
    {
        return '/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/actions/'.$kind;
    }

    private function cancelPath(InboxItem $item, InboxAction $action): string
    {
        return '/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/actions/'.$action->id.'/cancel';
    }

    /** @return list<InboxAction> */
    private function actionsOf(InboxItem $item): array
    {
        $this->em()->clear();

        $stored = $this->em()->find(InboxItem::class, $item->id) ?? throw new \LogicException('Stored.');

        return $this->service(InboxActionRepository::class)->findByItem($stored);
    }

    private function onlyAction(InboxItem $item): InboxAction
    {
        $actions = $this->actionsOf($item);
        self::assertCount(1, $actions);

        return $actions[0];
    }

    private function reload(InboxItem $item): InboxItem
    {
        $this->em()->clear();
        $stored = $this->em()->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);

        return $stored;
    }

    private function pageUrl(): string
    {
        return '/projects/'.$this->project->id.'/inbox';
    }

    private function cardUrl(): string
    {
        return '/projects/'.$this->project->id.'/board/cards/'.$this->card->id;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = static::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
