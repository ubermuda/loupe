<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Inbox\Command\ShowInboxHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Module\Inbox\Form\AnswerInboxItemRequest;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class InboxItemFormsControllerTest extends WebTestCase
{
    use InboxScenario;
    use BoardColumnFixtures;

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

    public function test_a_pull_request_review_preserves_validation_and_shows_the_completed_result(): void
    {
        $this->seedColumns($this->project);
        $card = new Card($this->project, $this->column($this->project, 'backlog'), 'Review card', 'Body', number: 1);
        $target = new CardPullRequest($card, 'https://github.com/example/project/pull/12');
        $item = new InboxItem($this->project, 1, InboxItemKind::Review, 'Review the pull request', true);
        $review = new InboxReview($item, $target);
        foreach ([$card, $target, $item, $review] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->askHolding($this->em, $this->project, [$item]);
        $name = 'inbox_review_'.$item->id;
        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#inbox-item-1', 'It does not post a review to the code host.');
        $crawler = $this->client->submit($crawler->filter('form[name="'.$name.'"]')->form([
            $name.'[verdict]' => 'changes-requested',
            $name.'[note]' => '  ',
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-1', 'Explain the changes you request in a review note.');
        self::assertSelectorExists('input[name="'.$name.'[verdict]"][value="changes-requested"]:checked');
        $this->client->submit($crawler->filter('form[name="'.$name.'"]')->form([$name.'[note]' => 'Add a retry limit.']));
        // The ask closed with its last blocking item, so the request is completed now.
        self::assertResponseRedirects($this->completedUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-inbox-review-verdict]', 'Changes requested');
        self::assertSelectorTextContains('#inbox-item-1', 'Add a retry limit.');
        self::assertSelectorNotExists('form[name="'.$name.'"]');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $saved = $em->find(InboxReview::class, $review->id);
        self::assertInstanceOf(InboxReview::class, $saved);
        self::assertSame(InboxReviewVerdict::ChangesRequested, $saved->verdict);
        self::assertSame('Add a retry limit.', $saved->note);
        self::assertSame(InboxItemState::Done, $saved->item->state);
    }

    public function test_a_completed_document_review_shows_its_original_answer_and_withdrawal(): void
    {
        $document = new Document($this->owner, $this->project, 'Review design');
        $document->addVersion('# Design', '<h1>Design</h1>');
        $item = new InboxItem($this->project, 1, InboxItemKind::Review, 'Review the design', true);
        $review = new InboxReview($item, $document);
        foreach ([$document, $item, $review] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->askHolding($this->em, $this->project, [$item]);
        $submit = static::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $submit);
        $verdict = $submit(new SubmitReviewCommand($this->owner, $document, 'approved', 1, 'Keep this original answer.'));
        $undo = static::getContainer()->get(UndoVerdictHandler::class);
        self::assertInstanceOf(UndoVerdictHandler::class, $undo);
        $undo(new UndoVerdictCommand($document, $this->owner, (string) $verdict->id));

        $this->client->request(Request::METHOD_GET, $this->completedUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-inbox-review-verdict]', 'Approved');
        self::assertSelectorTextContains('#inbox-item-1', 'Keep this original answer.');
        self::assertSelectorTextContains('[data-inbox-review-withdrawal]', 'Riley Chen withdrew this verdict');
        self::assertSelectorTextContains('[data-inbox-review-withdrawal]', 'This request keeps its original answer.');
        self::assertSelectorExists('#inbox-item-1 a[href="/projects/'.$this->project->id.'/documents/'.$document->id.'/review"]');
        self::assertSelectorNotExists('#inbox-item-1 form:not([name^="inbox_reply_"])');
        self::assertSelectorExists('#inbox-item-1 form[name^="inbox_reply_"]');
    }

    public function test_an_inline_document_review_rejects_a_stale_version_then_records_the_current_one(): void
    {
        $document = new Document($this->owner, $this->project, 'Inline design');
        $document->addVersion('# First', '<h1>First</h1>');
        $item = new InboxItem($this->project, 1, InboxItemKind::Review, 'Review the design', true);
        $review = new InboxReview($item, $document);
        foreach ([$document, $item, $review] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->askHolding($this->em, $this->project, [$item]);
        $name = 'inbox_document_review_'.$item->id;
        $page = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        self::assertResponseIsSuccessful();
        $form = $page->filter('form[name="'.$name.'"]')->form([
            $name.'[verdict]' => 'changes-requested',
            $name.'[note]' => 'Keep this draft feedback.',
        ]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $current = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $current);
        $current->addVersion('# Second', '<h1>Second</h1>');
        $em->flush();
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-1', 'The document has a newer version.');
        self::assertSelectorTextContains('textarea[name="'.$name.'[note]"]', 'Keep this draft feedback.');

        $page = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        $this->client->submit($page->filter('form[name="'.$name.'"]')->form([
            $name.'[verdict]' => 'changes-requested',
            $name.'[note]' => 'Clarify the second version.',
        ]));
        self::assertResponseRedirects($this->completedUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-inbox-review-verdict]', 'Changes requested');
        self::assertSelectorTextContains('#inbox-item-1', 'Version 2');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $saved = $em->find(InboxReview::class, $review->id);
        self::assertInstanceOf(InboxReview::class, $saved);
        self::assertSame('Clarify the second version.', $saved->documentReview?->note);
        self::assertSame(2, $saved->documentReview->version->versionNumber);
        self::assertSame(InboxItemState::Done, $saved->item->state);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-inbox-review-draft]', 'Keep this draft feedback.');
        self::assertSelectorTextContains('[data-inbox-response]', 'Clarify the second version.');
        self::assertSelectorNotExists('form[name="'.$name.'"]');
    }

    public function test_inbox_ownership_does_not_bypass_document_review_permission(): void
    {
        $otherOwner = $this->signedUpUser($this->em, 'document-owner');
        $document = new Document($otherOwner, $this->project, 'Restricted design');
        $document->addVersion('# Design', '<h1>Design</h1>');
        $item = new InboxItem($this->project, 1, InboxItemKind::Review, 'Review the design', true);
        $review = new InboxReview($item, $document);
        foreach ([$document, $item, $review] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->client->request(Request::METHOD_GET, $this->pageUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[name="inbox_document_review_'.$item->id.'"]');
        $this->post($item, 'review-document', ['verdict' => 'approved', 'versionNumber' => '1']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_a_reply_keeps_a_completed_answer_and_a_failed_draft(): void
    {
        $item = $this->answered($this->em, $this->question($this->em, $this->project, 1));
        $this->askHolding($this->em, $this->project, [$item], closedAt: new \DateTimeImmutable());
        $originalAnswer = $item->answerText;
        $name = 'inbox_reply_'.$item->id;
        $page = $this->client->request(Request::METHOD_GET, $this->completedUrl());
        $form = $page->filter('form[name="'.$name.'"]')->form([$name.'[body]' => 'One more detail.']);
        $this->client->submit($form);
        self::assertResponseRedirects($this->completedUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-inbox-reply]', 'One more detail.');
        $this->client->submit($form);
        self::assertResponseRedirects($this->completedUrl());
        $this->client->followRedirect();
        self::assertSelectorCount(1, '[data-inbox-reply]');

        $form[$name.'[body]'] = 'Keep this changed draft.';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="'.$name.'"]', 'This reply form is no longer current.');
        self::assertSelectorTextContains('textarea[name="'.$name.'[body]"]', 'Keep this changed draft.');
        self::assertSelectorTextContains('[data-inbox-reply]', 'One more detail.');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $stored = $em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);
        self::assertSame(InboxItemState::Answered, $stored->state);
        self::assertSame($originalAnswer, $stored->answerText);
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM inbox_replies WHERE item_id = ?', [(string) $item->id]));
    }

    public function test_the_page_form_answers_a_question(): void
    {
        $item = $this->question($this->em, $this->project, 4, ['JSON', 'CSV'], freeText: true);
        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());
        $name = 'inbox_answer_'.$item->id;

        // The form reads the pick from the hidden field, which the answer controller fills in a browser.
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

        $crawler = $this->post($item, 'answer', ['selectedOptions' => '0,1']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-1', 'This question takes one option only.');
        $describedBy = (string) $crawler->filter('#inbox-item-1 fieldset')->attr('aria-describedby');
        self::assertSelectorTextContains('#'.$describedBy, 'This question takes one option only.');
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
        // The page carries a static "final" line too, so the assertion targets the refusal itself.
        self::assertSelectorTextContains('#inbox-item-1 [data-inbox-refusal]', 'This response is final');
        self::assertSame([0], $this->reload($item)->selectedOptions);
    }

    public function test_a_refused_form_and_a_saved_one_keep_the_closed_asks_page(): void
    {
        $last = null;
        for ($number = 1; $number <= ShowInboxHandler::CLOSED_ASKS_PER_PAGE + 1; ++$number) {
            $last = $this->answered($this->em, $this->question($this->em, $this->project, $number));
            $this->askHolding($this->em, $this->project, [$last], closedAt: new \DateTimeImmutable('-'.$number.' minutes'));
        }
        self::assertInstanceOf(InboxItem::class, $last);

        // The oldest closed ask sits on page two of the completed queue.
        $crawler = $this->post($last, 'reply', ['body' => '', 'submissionId' => (string) Uuid::v4()], page: 2, queue: 'completed');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('.lp-pagination [aria-current="page"]', '2');
        self::assertCount(1, $crawler->filter('[data-inbox-section="closed-asks"] [data-inbox-ask-id]'));

        $this->post($last, 'reply', ['body' => 'One more detail.', 'submissionId' => (string) Uuid::v4()], page: 2, queue: 'completed');

        self::assertResponseRedirects($this->completedUrl().'&page=2');
    }

    public function test_a_refused_form_and_a_saved_one_keep_the_search(): void
    {
        $question = $this->question($this->em, $this->project, 1, title: 'Which export format?');
        $indexer = static::getContainer()->get(InboxSearchIndexer::class);
        self::assertInstanceOf(InboxSearchIndexer::class, $indexer);
        $indexer->index($question);

        $crawler = $this->post($question, 'answer', ['selectedOptions' => '0,1'], query: 'export');

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('[data-inbox-section="search-results"] [data-inbox-item="1"]'));

        $this->post($question, 'answer', ['selectedOptions' => '0'], query: 'export');

        self::assertResponseRedirects($this->pageUrl().'?q=export');
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
        $this->askHolding($this->em, $this->project, [$item]);

        $this->post($item, 'decline', ['closeNote' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-3 [data-inbox-refusal]', 'The agent closed this item');
        self::assertSame(InboxItemState::Withdrawn, $this->reload($item)->state);
    }

    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function actions(): iterable
    {
        yield 'answer' => ['answer', 'inbox_answer_', ['selectedOptions' => '0']];
        yield 'done' => ['done', 'inbox_done_', []];
        yield 'decline' => ['decline', 'inbox_decline_', ['closeNote' => '']];
        yield 'review-pull-request' => ['review-pull-request', 'inbox_review_', ['verdict' => 'approved', 'expectedUrl' => 'https://github.com/example/project/pull/12']];
        yield 'review-document' => ['review-document', 'inbox_document_review_', ['verdict' => 'approved', 'versionNumber' => '1']];
        yield 'reply' => ['reply', 'inbox_reply_', ['body' => 'A reply.', 'submissionId' => '01995498-93aa-7000-8000-000000000001']];
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
    private function post(InboxItem $item, string $action, array $fields, ?int $page = null, ?string $query = null, ?string $queue = null): Crawler
    {
        $prefix = ['answer' => 'inbox_answer_', 'done' => 'inbox_done_', 'decline' => 'inbox_decline_', 'review-pull-request' => 'inbox_review_', 'review-document' => 'inbox_document_review_', 'reply' => 'inbox_reply_'][$action];
        $parameters = array_filter(['queue' => $queue, 'page' => $page, 'q' => $query], static fn (int|string|null $value): bool => null !== $value);
        $url = $this->actionUrl($item, $action).([] === $parameters ? '' : '?'.http_build_query($parameters));

        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel, which a same-origin Referer lets stand in for a signed token.
        return $this->client->request(Request::METHOD_POST, $url, [$prefix.$item->id => [...$fields, '_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
    }

    private function actionUrl(InboxItem $item, string $action): string
    {
        return '/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/'.$action;
    }

    private function pageUrl(): string
    {
        return '/projects/'.$this->project->id.'/inbox';
    }

    private function completedUrl(): string
    {
        return $this->pageUrl().'?queue=completed';
    }

    private function reload(InboxItem $item): InboxItem
    {
        $this->em->clear();
        $stored = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);

        return $stored;
    }
}
