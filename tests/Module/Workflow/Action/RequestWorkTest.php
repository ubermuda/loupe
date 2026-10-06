<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Workflow\Action\ActionOutcome;
use App\Module\Workflow\Action\RequestWork;
use App\Module\Workflow\Contract\ChecksState;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Service\CardPullRequests;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\RuleOrigin;
use App\Module\Workflow\Template\TemplateParser;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RequestWorkTest extends KernelTestCase
{
    use ActionScenario;

    public function test_it_opens_a_work_request_of_the_kind_and_capability_for_the_rule(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-open'), 'next');
        $rule = $this->rule(ActionType::Request, ['kind' => 'product-design', 'capability' => 'interactive'], 'start-product-design');

        $outcome = $this->action()->run($rule, $card, FactsMother::facts(), $this->state($card, $rule->id));

        self::assertEquals(ActionOutcome::done(), $outcome);
        $live = $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.'));
        self::assertCount(1, $live);
        self::assertSame(['product-design', 'interactive', 'start-product-design', $card->number], [$live[0]->kind, $live[0]->capability, $live[0]->ruleId, $live[0]->cardNumber]);
    }

    public function test_an_app_rule_that_names_a_prompt_sends_the_text_of_the_prompt(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-prompt'), 'next');
        $rule = $this->rule(ActionType::Request, ['kind' => 'groom', TemplateParser::PROMPT => 'groom-card'], 'app-groom', RuleOrigin::App);

        $outcome = $this->action()->run($rule, $card, FactsMother::facts(), $this->state($card, $rule->id));

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertSame("Groom the card.\n", $this->liveRequest($card)->prompt);
    }

    public function test_a_template_rule_sends_no_prompt(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-no-prompt'), 'next');
        $rule = $this->rule(ActionType::Request, ['kind' => 'groom', TemplateParser::PROMPT => 'groom-card']);

        $this->action()->run($rule, $card, FactsMother::facts(), $this->state($card));

        self::assertNull($this->liveRequest($card)->prompt);
    }

    public function test_an_app_rule_with_no_prompt_sends_none(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-app-no-prompt'), 'next');
        $rule = $this->rule(ActionType::Request, ['kind' => 'groom'], 'app-groom', RuleOrigin::App);

        $this->action()->run($rule, $card, FactsMother::facts(), $this->state($card, $rule->id));

        self::assertNull($this->liveRequest($card)->prompt);
    }

    public function test_a_live_request_of_the_kind_is_already_live_and_opens_no_second_one(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-live'), 'next');
        $rule = $this->rule(ActionType::Request, ['kind' => 'fix']);

        $this->action()->run($rule, $card, FactsMother::facts(), $this->state($card));
        $outcome = $this->action()->run($rule, $card, FactsMother::facts(), $this->state($card));

        self::assertEquals(ActionOutcome::alreadyLive(), $outcome);
        self::assertNotEquals(ActionOutcome::done(), $outcome);
        self::assertCount(1, $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.')));
    }

    public function test_an_invalid_kind_is_refused(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-invalid'), 'next');

        $outcome = $this->action()->run($this->rule(ActionType::Request, ['kind' => 'Not A Kind']), $card, FactsMother::facts(), $this->state($card));

        self::assertEquals(ActionOutcome::refused('invalid-work-request'), $outcome);
    }

    public function test_a_rule_that_fired_its_limit_pauses_the_card_and_opens_nothing(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-limit'), 'next');
        $rule = $this->rule(ActionType::Request, ['kind' => 'fix', 'limit' => 3]);
        $state = $this->state($card);
        $state->fires = 2;

        self::assertEquals(ActionOutcome::done(), $this->action()->run($rule, $card, FactsMother::facts(), $state));

        $this->em()->getConnection()->executeStatement('DELETE FROM work_requests');
        $state->fires = 3;
        $outcome = $this->action()->run($rule, $card, FactsMother::facts(), $state);

        self::assertEquals(ActionOutcome::pause(CardPauseKind::WorkLimit, 'work-limit-reached'), $outcome);
        self::assertSame([], $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.')));
    }

    /** @return iterable<string, array{bool, ChecksState, bool, string}> */
    public static function fixReasons(): iterable
    {
        yield 'conflict first' => [true, ChecksState::Failed, true, 'conflict'];
        yield 'failed checks' => [false, ChecksState::Failed, true, 'checks-failed'];
        yield 'changes requested' => [false, ChecksState::Passed, true, 'changes-requested'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fixReasons')]
    public function test_a_fix_request_records_a_fix_requested_card_event(bool $conflicting, ChecksState $checks, bool $changesRequested, string $reason): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-fix-event'), 'in-progress');
        $pullRequest = $this->pullRequest($card);
        $rule = $this->rule(ActionType::Request, ['kind' => 'fix', 'limit' => 3]);
        $facts = FactsMother::facts(pullRequest: FactsMother::pullRequest(checks: $checks, conflicting: $conflicting, changesRequested: $changesRequested));

        $this->action()->run($rule, $card, $facts, $this->state($card));
        $this->em()->flush();

        self::assertSame([['reason' => $reason, 'pullRequest' => $pullRequest->number]], $this->fixEvents($card));
    }

    public function test_a_live_fix_request_and_another_kind_record_no_fix_event(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-fix-none'), 'in-progress');
        $this->pullRequest($card);
        $facts = FactsMother::facts(pullRequest: FactsMother::pullRequest(checks: ChecksState::Failed));

        $this->action()->run($this->rule(ActionType::Request, ['kind' => 'implement']), $card, $facts, $this->state($card));
        $this->action()->run($this->rule(ActionType::Request, ['kind' => 'fix']), $card, $facts, $this->state($card));
        $this->em()->flush();
        $this->action()->run($this->rule(ActionType::Request, ['kind' => 'fix']), $card, $facts, $this->state($card));
        $this->em()->flush();

        self::assertCount(1, $this->fixEvents($card));
    }

    public function test_a_request_carries_the_primary_pull_request_and_the_reason_as_its_context(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-context'), 'in-progress');
        $this->pullRequest($card, PullRequestState::Closed, headSha: 'fff0000');
        $primary = $this->pullRequest($card, headSha: 'ABC1234');
        $facts = FactsMother::facts(pullRequest: FactsMother::pullRequest(checks: ChecksState::Failed, changesRequested: true));

        $this->action()->run($this->rule(ActionType::Request, ['kind' => 'fix']), $card, $facts, $this->state($card));

        self::assertEquals(
            new WorkRequestContext($primary->number, 'https://github.com/acme/widgets/pull/'.$primary->number, 'abc1234', 'checks-failed'),
            $this->liveRequest($card)->context,
        );
    }

    public function test_a_card_with_no_pull_request_opens_a_request_with_an_empty_context(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-context-empty'), 'next');

        $this->action()->run($this->rule(ActionType::Request, ['kind' => 'implement']), $card, FactsMother::facts(), $this->state($card));

        self::assertEquals(new WorkRequestContext(), $this->liveRequest($card)->context);
    }

    public function test_a_link_url_or_a_head_sha_of_another_shape_is_left_out(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-context-shape'), 'in-progress');
        $pullRequest = $this->pullRequest($card, headSha: 'not-a-sha', url: 'http://github.com/acme/widgets/pull/1');

        $this->action()->run($this->rule(ActionType::Request, ['kind' => 'sync']), $card, FactsMother::facts(pullRequest: FactsMother::pullRequest()), $this->state($card));

        self::assertEquals(new WorkRequestContext($pullRequest->number), $this->liveRequest($card)->context);
    }

    public function test_a_document_tag_names_the_one_linked_document_with_that_tag(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-document'), 'tech-design');
        $documentId = Uuid::v7()->toRfc4122();
        $facts = FactsMother::facts(card: FactsMother::card(documents: [
            new DocumentFacts(['product'], 'approved', Uuid::v7()->toRfc4122()),
            new DocumentFacts(['design', 'tech'], 'changes-requested', $documentId),
        ]));

        $outcome = $this->action()->run($this->rule(ActionType::Request, ['kind' => 'tech-design-revise', 'document.tag' => 'design']), $card, $facts, $this->state($card));

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertSame($documentId, $this->liveRequest($card)->context->documentId);
    }

    public function test_a_document_status_picks_the_one_document_with_that_tag_in_that_status(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-document-status'), 'tech-design');
        $documentId = Uuid::v7()->toRfc4122();
        $facts = FactsMother::facts(card: FactsMother::card(documents: [
            new DocumentFacts(['design'], 'approved', Uuid::v7()->toRfc4122()),
            new DocumentFacts(['design'], 'changes-requested', $documentId),
        ]));
        $rule = $this->rule(ActionType::Request, ['kind' => 'tech-design-revise', 'document.tag' => 'design', 'document.status' => 'changes-requested']);

        self::assertEquals(ActionOutcome::done(), $this->action()->run($rule, $card, $facts, $this->state($card)));
        self::assertSame($documentId, $this->liveRequest($card)->context->documentId);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function unresolvedDocuments(): iterable
    {
        yield 'no document with the tag' => [['product'], 'document-not-found'];
        yield 'two documents with the tag' => [['design', 'design'], 'document-ambiguous'];
    }

    /** @param list<string> $tags the tag of each linked document */
    #[\PHPUnit\Framework\Attributes\DataProvider('unresolvedDocuments')]
    public function test_a_document_tag_that_names_no_single_document_is_refused_and_opens_nothing(array $tags, string $code): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('request-document-'.$code), 'tech-design');
        $documents = array_map(static fn (string $tag): DocumentFacts => new DocumentFacts([$tag], 'changes-requested', Uuid::v7()->toRfc4122()), $tags);
        $facts = FactsMother::facts(card: FactsMother::card(documents: $documents));

        $outcome = $this->action()->run($this->rule(ActionType::Request, ['kind' => 'tech-design-revise', 'document.tag' => 'design']), $card, $facts, $this->state($card));

        self::assertEquals(ActionOutcome::refused($code), $outcome);
        self::assertSame([], $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.')));
    }

    private function liveRequest(Card $card): WorkRequest
    {
        $live = $this->service(WorkRequestRepository::class)->findLiveForCard($card->id ?? throw new \LogicException('A flushed card has an id.'));
        self::assertCount(1, $live);

        return $live[0];
    }

    /** @return list<array<mixed>> the detail of each fix-requested event of the card */
    private function fixEvents(Card $card): array
    {
        $rows = $this->service(CardEventRepository::class)->findKindsOfCards($card->project, [$card->id ?? throw new \LogicException('A flushed card has an id.')], [CardEventKind::FixRequested]);

        return array_values(array_map(static fn (array $row): array => $row['detail'], array_filter($rows, static fn (array $row): bool => CardEventKind::FixRequested === $row['kind'])));
    }

    private function action(): RequestWork
    {
        return new RequestWork($this->opener(), $this->service(CardPullRequests::class), $this->service(CardEventRepository::class));
    }
}
