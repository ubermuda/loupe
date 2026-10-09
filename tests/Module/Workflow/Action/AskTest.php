<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Workflow\Action\ActionOutcome;
use App\Module\Workflow\Action\Ask;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\ActionType;
use App\Module\Workflow\Template\AskOption;
use App\Module\Workflow\Template\Rule;
use App\Module\Workflow\Template\RuleOrigin;
use App\Tests\Module\Workflow\Fact\FactsMother;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AskTest extends KernelTestCase
{
    use ActionScenario;

    private FakeRuleAsks $asks;

    #[\Override]
    protected function setUp(): void
    {
        $this->asks = new FakeRuleAsks();
    }

    public function test_it_opens_the_question_with_the_options_in_template_order_and_keeps_the_item_id(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('ask-open');
        $card = $this->card($project, 'next');
        $state = $this->state($card, 'unplanned-child');

        $outcome = $this->action()->run($this->askRule(), $card->snapshot(), FactsMother::facts(), $state);

        self::assertEquals(ActionOutcome::done(), $outcome);
        self::assertCount(1, $this->asks->opened);
        $opened = $this->asks->opened[0];
        self::assertTrue($project->id?->equals($opened['projectId']));
        self::assertTrue($card->id?->equals($opened['cardId']));
        self::assertSame('unplanned-child', $opened['ruleId']);
        self::assertSame('q', $opened['question']);
        self::assertSame(['first', 'second'], $opened['options']);
        self::assertSame($opened['itemId'], $state->askItemId);
    }

    public function test_it_translates_the_text_with_the_default_locale_and_the_card_numbers(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('ask-translate'), 'next');
        $parent = $this->card($card->project, 'next');
        $card->parent = $parent;
        $translator = new class implements TranslatorInterface {
            /** @param array<string, mixed> $parameters */
            #[\Override]
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return \sprintf('%s|%s|%s|%s', $id, $locale, $parameters['%child%'], $parameters['%epic%']);
            }

            #[\Override]
            public function getLocale(): string
            {
                return 'en';
            }
        };

        new Ask($this->asks, $translator, 'fr')->run($this->askRule(), $card->snapshot(), FactsMother::facts(), $this->state($card, 'unplanned-child'));

        $number = $card->number;
        $parentNumber = $parent->number;
        self::assertSame(\sprintf('q|fr|%d|%d', $number, $parentNumber), $this->asks->opened[0]['question']);
        self::assertSame([\sprintf('first|fr|%d|%d', $number, $parentNumber), \sprintf('second|fr|%d|%d', $number, $parentNumber)], $this->asks->opened[0]['options']);
    }

    public function test_with_the_inbox_off_it_pauses_the_card_and_opens_nothing(): void
    {
        self::bootKernel();
        $card = $this->card($this->workflowProject('ask-off'), 'next');
        $state = $this->state($card, 'unplanned-child');
        $this->asks->on = false;

        $outcome = $this->action()->run($this->askRule(), $card->snapshot(), FactsMother::facts(), $state);

        self::assertEquals(ActionOutcome::pause(PauseKind::Rule, 'inbox-off'), $outcome);
        self::assertSame([], $this->asks->opened);
        self::assertNull($state->askItemId);
    }

    private function action(): Ask
    {
        return new Ask($this->asks, $this->service(TranslatorInterface::class), 'en');
    }

    private function askRule(): Rule
    {
        return new Rule('unplanned-child', null, new AllOf([]), new ActionCall(
            ActionType::Ask,
            ['question' => 'q'],
            options: [
                new AskOption('first', [new ActionCall(ActionType::Detach, [])]),
                new AskOption('second', [new ActionCall(ActionType::Detach, [])]),
            ],
        ), RuleOrigin::Template);
    }
}
