<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Form\AnswerInboxItemRequest;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class InboxItemFormsControllerTest extends WebTestCase
{
    use InboxScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->owner = $this->signedUpUser($em, 'inbox-forms');
        $this->project = $this->inboxProject($em, $this->owner);
        $this->setInboxFlag(true);
        $this->client->loginUser($this->owner);
    }

    public function test_the_page_form_answers_a_question(): void
    {
        $item = $this->question($this->em, $this->project, 4, ['JSON', 'CSV'], freeText: true);
        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        $name = 'inbox_answer_'.$item->id;

        // The option controls post nothing; a browser copies the pick into the hidden field.
        $this->client->submit($crawler->filter('form[name="'.$name.'"]')->form([
            $name.'[selectedOptions]' => '1',
            $name.'[answerText]' => 'CSV, because the importer reads it.',
        ]));

        self::assertResponseRedirects($this->pageUrl());
        $stored = $this->reload($item);
        self::assertSame(InboxItemState::Answered, $stored->state);
        self::assertSame([1], $stored->selectedOptions);
        self::assertSame('CSV, because the importer reads it.', $stored->answerText);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.lp-flash', 'Item 4 is answered.');
    }

    public function test_a_refused_answer_re_renders_the_page_with_422_and_the_error(): void
    {
        $item = $this->question($this->em, $this->project, 1);

        $this->post($item, 'answer', ['selectedOptions' => '0,1']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-1', 'This question takes one option only.');
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_an_answer_past_the_length_limit_is_invalid(): void
    {
        $item = $this->question($this->em, $this->project, 1, freeText: true);

        $this->post($item, 'answer', ['answerText' => str_repeat('a', AnswerInboxItemRequest::MAX_ANSWER_LENGTH + 1)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_a_final_answer_refuses_a_change(): void
    {
        $item = $this->answered($this->em, $this->question($this->em, $this->project, 1));
        $this->askHolding($this->em, $this->project, [$item], closedAt: new \DateTimeImmutable());

        $this->post($item, 'answer', ['selectedOptions' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-1', 'This response is final');
        self::assertSame([0], $this->reload($item)->selectedOptions);
    }

    public function test_marking_a_to_do_done(): void
    {
        $item = $this->todo($this->em, $this->project, 2);

        $this->post($item, 'done', []);

        self::assertResponseRedirects($this->pageUrl());
        $stored = $this->reload($item);
        self::assertSame(InboxItemState::Done, $stored->state);
        self::assertNotNull($stored->closedAt);
    }

    public function test_a_done_on_a_final_to_do_is_refused_in_place(): void
    {
        $item = $this->answered($this->em, $this->todo($this->em, $this->project, 2), InboxItemState::Declined);
        $this->askHolding($this->em, $this->project, [$item], closedAt: new \DateTimeImmutable());

        $this->post($item, 'done', []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(InboxItemState::Declined, $this->reload($item)->state);
    }

    public function test_declining_with_a_note(): void
    {
        $item = $this->question($this->em, $this->project, 3);

        $this->post($item, 'decline', ['closeNote' => 'Not sure what you mean.']);

        self::assertResponseRedirects($this->pageUrl());
        $stored = $this->reload($item);
        self::assertSame(InboxItemState::Declined, $stored->state);
        self::assertSame('Not sure what you mean.', $stored->closeNote);
    }

    public function test_a_decline_of_an_item_the_agent_withdrew_is_refused(): void
    {
        $item = $this->answered($this->em, $this->todo($this->em, $this->project, 3), InboxItemState::Withdrawn);

        $this->post($item, 'decline', ['closeNote' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(InboxItemState::Withdrawn, $this->reload($item)->state);
    }

    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function actions(): iterable
    {
        yield 'answer' => ['answer', 'inbox_answer_', ['selectedOptions' => '0']];
        yield 'done' => ['done', 'inbox_done_', []];
        yield 'decline' => ['decline', 'inbox_decline_', ['closeNote' => '']];
    }

    /** @param array<string, string> $fields */
    #[DataProvider('actions')]
    public function test_a_forged_token_changes_nothing(string $action, string $prefix, array $fields): void
    {
        $item = 'done' === $action ? $this->todo($this->em, $this->project, 1) : $this->question($this->em, $this->project, 1);
        $url = $this->actionUrl($item, $action);

        $this->client->request(Request::METHOD_POST, $url, [$prefix.$item->id => [...$fields, '_token' => 'forged']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    /** @param array<string, string> $fields */
    #[DataProvider('actions')]
    public function test_a_stranger_cannot_respond(string $action, string $prefix, array $fields): void
    {
        $item = 'done' === $action ? $this->todo($this->em, $this->project, 1) : $this->question($this->em, $this->project, 1);
        $this->client->loginUser($this->signedUpUser($this->em, 'inbox-stranger'));

        $this->post($item, $action, $fields);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    /** @param array<string, string> $fields */
    #[DataProvider('actions')]
    public function test_a_response_answers_404_while_the_inbox_is_off(string $action, string $prefix, array $fields): void
    {
        $item = 'done' === $action ? $this->todo($this->em, $this->project, 1) : $this->question($this->em, $this->project, 1);
        $this->setInboxFlag(false);

        $this->post($item, $action, $fields);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_an_item_of_another_project_is_not_found_under_this_one(): void
    {
        $other = $this->inboxProject($this->em, $this->owner);
        $item = $this->todo($this->em, $other, 1);

        $this->client->request(Request::METHOD_POST, '/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/done', ['inbox_done_'.$item->id => ['_token' => 'csrf-token']]);

        self::assertResponseStatusCodeSame(404);
    }

    /** @param array<string, string> $fields */
    private function post(InboxItem $item, string $action, array $fields): void
    {
        $prefix = ['answer' => 'inbox_answer_', 'done' => 'inbox_done_', 'decline' => 'inbox_decline_'][$action];
        $url = $this->actionUrl($item, $action);

        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel, which a same-origin Referer lets stand in for a signed token.
        $this->client->request(Request::METHOD_POST, $url, [$prefix.$item->id => [...$fields, '_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
    }

    private function actionUrl(InboxItem $item, string $action): string
    {
        return '/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/'.$action;
    }

    private function pageUrl(): string
    {
        return '/projects/'.$this->project->id.'/inbox';
    }

    private function reload(InboxItem $item): InboxItem
    {
        $this->em->clear();
        $stored = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);

        return $stored;
    }
}
