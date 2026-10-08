<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\DecisionAnswer;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\DecisionAnswerRepository;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SaveDecisionAnswerControllerTest extends WebTestCase
{
    private const string MARKDOWN = <<<'MD'
        Where should this land?

        <!-- decision: deploy-target -->

        - ( ) Ship to staging first
        - ( ) Ship straight to production

        <!-- /decision -->
        MD;

    private const array TURBO = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'];

    public function test_the_review_page_renders_a_fence_as_radios_the_reviewer_can_answer(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->reviewPath($document));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-decision-id="deploy-target"]');
        self::assertCount(2, $client->getCrawler()->filter('[data-decision-option]'));
        // The form the Stimulus controller fills and submits, outside the prose
        // whose textContent must equal DocumentVersion::plainText().
        foreach (['form', 'decisionId', 'versionNumber', 'options', 'note', 'clear'] as $target) {
            self::assertSelectorExists('[data-decision-target="'.$target.'"]');
        }
        self::assertSelectorExists('[data-decision-target="form"][action$="/decisions/answer"]');
    }

    public function test_a_recommended_option_shows_a_named_badge(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seedMarkdown($client, str_replace(
            'Ship to staging first',
            'Ship to staging first (recommended: moderate)',
            self::MARKDOWN,
        ));

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->reviewPath($document));

        self::assertSelectorExists('.lp-decision__badge[data-decision-recommended="moderate"][role="note"][aria-label="Recommended, moderate confidence"]');
    }

    /**
     * `form_end` renders whatever the template did not, as a row with its label.
     * That is how "Version number" once reached this hidden form as visible,
     * untranslated text.
     */
    public function test_the_hidden_decision_form_renders_no_label(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->reviewPath($document));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#save_decision_answer_form_versionNumber'));
        self::assertCount(0, $crawler->filter('label[for^="save_decision_answer_form_"]'));
    }

    public function test_answering_records_the_choice_and_shows_it_on_the_next_visit(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, ['optionIndexes' => [1]]);

        self::assertResponseRedirects($this->reviewPath($document));
        $stored = $this->selections()->findByDocumentAndDecisionId($document, 'deploy-target');
        self::assertCount(1, $stored);
        self::assertSame(1, $stored[0]->optionIndex);

        $client->request(Request::METHOD_GET, $this->reviewPath($document));
        self::assertSelectorExists('#decision_option_deploy-target_1[checked]');
        self::assertSelectorNotExists('#decision_option_deploy-target_0[checked]');
    }

    public function test_a_note_is_saved_with_the_answer(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, ['optionIndexes' => [0], 'note' => '  Staging is quiet this week.  '], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertSame('Saved.', self::statusMessage((string) $client->getResponse()->getContent()));
        $answer = $this->answerRow($document);
        self::assertNotNull($answer);
        self::assertSame('Staging is quiet this week.', $answer->note);
        self::assertSame($owner->id?->toRfc4122(), $answer->answeredBy?->id?->toRfc4122());
    }

    /**
     * The page reads the note from an attribute, so the pane text still equals
     * the anchor basis. An earlier version shows the same note.
     */
    public function test_the_review_page_carries_the_note_outside_the_pane_text(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, ['optionIndexes' => [0], 'note' => "Staging first.\nThen \"prod\"."], self::TURBO);
        $this->revise($document, self::MARKDOWN."\n\nMore.\n");

        foreach (['', '/versions/1'] as $suffix) {
            $crawler = $client->request(Request::METHOD_GET, $this->reviewPath($document).$suffix);

            self::assertResponseIsSuccessful();
            $block = $crawler->filter('[data-decision-id="deploy-target"]');
            self::assertSame("Staging first.\nThen \"prod\".", $block->attr('data-decision-note'));
            $pane = $crawler->filter('[data-comment-anchor-target="doc"]');
            self::assertStringNotContainsString('Staging first.', $pane->text(null, false));
            $version = $this->versionNumbered($document, '' === $suffix ? 2 : 1);
            self::assertSame($version->plainText(), $pane->text(null, false));
        }
    }

    public function test_a_block_with_no_note_carries_no_note_attribute(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->reviewPath($document));

        self::assertSelectorExists('[data-decision-id="deploy-target"]');
        self::assertSelectorNotExists('[data-decision-note]');
    }

    public function test_a_note_longer_than_the_limit_is_refused_with_a_reason(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, ['optionIndexes' => [0], 'note' => str_repeat('a', DecisionAnswer::MAX_NOTE_LENGTH + 1)], self::TURBO);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('A note can have a maximum of 2000 characters.', self::statusMessage((string) $client->getResponse()->getContent()));
        self::assertNull($this->answerRow($document));
    }

    public function test_clearing_removes_the_answer_and_says_so(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, ['optionIndexes' => [0], 'note' => 'A note.'], self::TURBO);
        $this->answer($client, $document, ['clear' => '1'], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertSame('Cleared.', self::statusMessage((string) $client->getResponse()->getContent()));
        self::assertSame([], $this->selections()->findByDocumentAndDecisionId($document, 'deploy-target'));
        self::assertNull($this->answerRow($document));
    }

    public function test_clearing_keeps_the_note(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, ['optionIndexes' => [0], 'note' => 'A note.'], self::TURBO);
        $this->answer($client, $document, ['clear' => '1', 'note' => 'A note.'], self::TURBO);

        self::assertResponseIsSuccessful();
        self::assertSame('Cleared.', self::statusMessage((string) $client->getResponse()->getContent()));
        self::assertSame([], $this->selections()->findByDocumentAndDecisionId($document, 'deploy-target'));
        self::assertSame('A note.', $this->answerRow($document)?->note);
    }

    /** The last write wins, so a tab that still shows an older version saves too. */
    public function test_an_answer_from_an_older_version_is_saved_on_the_latest(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        [$token] = $this->renderForm($client, $document);
        $this->revise($document, str_replace(
            "- ( ) Ship to staging first\n- ( ) Ship straight to production",
            "- ( ) Ship straight to production\n- ( ) Ship to staging first",
            self::MARKDOWN,
        ));

        $this->submit($client, $document, $token, ['versionNumber' => '1', 'optionIndexes' => [0]], self::TURBO);

        self::assertResponseIsSuccessful();
        $stored = $this->selections()->findByDocumentAndDecisionId($document, 'deploy-target');
        self::assertSame([[1, 'Ship to staging first', 2]], array_map(
            static fn ($selection): array => [$selection->optionIndex, $selection->optionLabel, $selection->versionNumber],
            $stored,
        ));
    }

    public function test_invalid_input_is_refused_and_keeps_the_saved_answer(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        [$token] = $this->renderForm($client, $document);
        $this->submit($client, $document, $token, ['optionIndexes' => [1]], self::TURBO);
        self::assertResponseIsSuccessful();

        foreach ([[''], ['invalid'], [-1], [99], [0, 1]] as $invalid) {
            $this->submit($client, $document, $token, ['optionIndexes' => $invalid], self::TURBO);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->submit($client, $document, $token, ['versionNumber' => '9', 'optionIndexes' => [0]], self::TURBO);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('That version of the document does not exist.', self::statusMessage((string) $client->getResponse()->getContent()));

        $stored = $this->selections()->findByDocumentAndDecisionId($document, 'deploy-target');
        self::assertCount(1, $stored);
        self::assertSame(1, $stored[0]->optionIndex);
    }

    /**
     * An earlier version is a record of what was discussed then, so it shows the
     * answer but cannot take a new one. Rendering it blank would read as
     * unanswered, which is a different claim.
     */
    public function test_an_earlier_version_shows_the_answer_locked(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, ['optionIndexes' => [1]]);
        $this->revise($document, self::MARKDOWN."\n\nMore.\n");

        $client->request(Request::METHOD_GET, $this->reviewPath($document).'/versions/1');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#decision_option_deploy-target_1[checked][disabled]');
        self::assertSelectorNotExists('[data-decision-target="form"]');
    }

    public function test_a_decision_the_document_does_not_offer_is_rejected(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, ['decisionId' => 'invented', 'optionIndexes' => [0]], self::TURBO);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('target="decision-status"', (string) $client->getResponse()->getContent());
        self::assertSame([], $this->selections()->findBy(['document' => $document]));
    }

    /**
     * The stream touches only the toolbar: the block is already in the state the
     * reviewer left it, and replacing the prose would tear out the comment
     * highlights anchored into it.
     */
    public function test_a_turbo_answer_streams_back_only_the_toolbar(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, ['optionIndexes' => [0]], self::TURBO);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(' action="update" target="decision-status">', $body);
        self::assertStringContainsString(' action="update" target="decision-summary-count">', $body);
        self::assertStringContainsString(' action="update" target="decision-summary-list">', $body);
        self::assertStringNotContainsString('lp-review-doc__prose', $body);
        self::assertStringNotContainsString('data-comment-anchor-target', $body);
        self::assertSame(6, substr_count($body, '<turbo-stream'), 'the status line, and the running total and list of each of the two copies');
    }

    /** A save can answer after a visit, so the client renders a stream only on the page that sent it. */
    public function test_the_stream_and_the_page_name_the_same_document_version(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);
        $this->revise($document, self::MARKDOWN."\n\nMore.\n");
        $page = $document->id.'/2';

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->reviewPath($document));
        self::assertSelectorExists('[data-controller~="decision"][data-decision-page="'.$page.'"]');
        $client->request(Request::METHOD_GET, $this->reviewPath($document).'/versions/1');
        self::assertSelectorExists('[data-controller~="decision"][data-decision-page="'.$document->id.'/1"]');
        $client->request(Request::METHOD_GET, $this->reviewPath($document).'/diff/1/2');
        self::assertSelectorExists('[data-controller~="decision"]');
        self::assertSelectorNotExists('[data-decision-page]');

        $this->answer($client, $document, ['versionNumber' => '2', 'optionIndexes' => [0]], self::TURBO);
        $body = (string) $client->getResponse()->getContent();
        self::assertSame(6, substr_count($body, '<turbo-stream '));
        self::assertSame(6, preg_match_all('~<turbo-stream data-decision-page="'.preg_quote($page, '~').'" ~', $body));
    }

    public function test_a_note_with_no_option_counts_as_answered_in_the_stream_and_on_reload(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, ['note' => 'Neither target yet.'], self::TURBO);

        $body = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('~target="decision-summary-count">\s*<template>1 of 1 answered</template>~', $body);
        self::assertMatchesRegularExpression('~target="review-menu-decisions-count">\s*<template>1/1</template>~', $body);
        self::assertStringContainsString('Note: Neither target yet.', $body);
        self::assertStringNotContainsString('Not chosen yet', $body);

        $client->request(Request::METHOD_GET, $this->reviewPath($document));
        self::assertSelectorTextSame('#decision-summary-count', '1 of 1 answered');
        self::assertSelectorTextSame('#review-menu-decisions-count', '1/1');
        self::assertSelectorTextSame('#decision-summary-list .lp-decision-summary__note', 'Note: Neither target yet.');
        self::assertSelectorExists('#decision-summary-list .lp-decision-summary__tag--answered');
        self::assertSelectorNotExists('#decision-summary-list .lp-decision-summary__answer');
        self::assertSelectorNotExists('#decision-summary-list .lp-decision-summary__pending');
        self::assertSelectorExists('#review-menu-decisions-list .lp-review-menu__mark--approved');
    }

    /** The count is what tells the reviewer how much is left, so it must follow the write. */
    public function test_an_answer_streams_back_the_running_total(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, ['optionIndexes' => [0]], self::TURBO);

        self::assertMatchesRegularExpression(
            '~target="decision-summary-count">\s*<template>1 of 1 answered</template>~',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function test_the_panel_row_shows_the_pick_with_the_note_under_it(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, ['optionIndexes' => [1], 'note' => 'Only this once.'], self::TURBO);

        $client->request(Request::METHOD_GET, $this->reviewPath($document));
        self::assertSelectorTextSame('#decision-summary-list .lp-decision-summary__answer', 'Ship straight to production');
        self::assertSelectorTextSame('#decision-summary-list .lp-decision-summary__note', 'Note: Only this once.');
    }

    public function test_a_row_takes_its_tag_from_the_heading_above_the_block(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seedMarkdown($client, "## D4: Where should this land?\n\n".self::MARKDOWN);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->reviewPath($document));

        self::assertSelectorTextSame('#decision-summary-list .lp-decision-summary__tag', 'D4');
        self::assertSelectorNotExists('#decision-summary-list .lp-decision-summary__tag--answered');
        self::assertSelectorTextSame('#decision-summary-count', '0 of 1 answered');
    }

    /**
     * A diff renders the document, decision blocks included, so a revision that
     * reworded an option is readable. It cannot be answered there, so the block
     * is disabled and the form that would carry the answer is not built.
     */
    public function test_a_diff_shows_the_decision_block_but_cannot_answer_it(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);
        $this->revise($document, self::MARKDOWN."\n\nA closing note.\n");

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->reviewPath($document).'/diff/1/2');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-decision-target="form"]');
        self::assertSelectorNotExists('#decision-status');
        self::assertSelectorExists('fieldset.lp-decision[disabled]');
        self::assertSelectorNotExists('fieldset.lp-decision:not([disabled])');

        // The same document still answers them on the review page, so the
        // absences above come from the diff rather than a broken fixture.
        $client->request(Request::METHOD_GET, $this->reviewPath($document));
        self::assertSelectorExists('[data-decision-target="form"]');
        self::assertSelectorNotExists('fieldset.lp-decision[disabled]');
    }

    public function test_the_review_page_listens_on_its_document_topic_and_a_diff_does_not(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);
        $this->revise($document, self::MARKDOWN."\n\nA closing note.\n");
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $documentTopic = $topics->forDocument(
            $document->project->id ?? throw new \LogicException('The project has no id.'),
            $document->id ?? throw new \LogicException('The document has no id.'),
        );

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->reviewPath($document));
        self::assertResponseIsSuccessful();
        self::assertContains($documentTopic, $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]')->each(static fn (Crawler $input): ?string => $input->attr('value')));

        $crawler = $client->request(Request::METHOD_GET, $this->reviewPath($document).'/diff/1/2');
        self::assertResponseIsSuccessful();
        self::assertNotContains($documentTopic, $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]')->each(static fn (Crawler $input): ?string => $input->attr('value')));
    }

    public function test_the_review_page_names_the_summary_of_its_version_and_a_diff_does_not(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);
        $this->revise($document, self::MARKDOWN."\n\nA closing note.\n");

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->reviewPath($document));
        self::assertSelectorExists('[data-decision-summary-url-value="/projects/'.$document->project->id.'/documents/'.$document->id.'/decisions/summary?versionNumber=2"][data-decision-changed-by-value="Changed by %name%."]');

        $client->request(Request::METHOD_GET, $this->reviewPath($document).'/diff/1/2');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-decision-summary-url-value]');
    }

    /** Only a legitimate owner reaches the token check, so the non-owner test cannot cover it. */
    public function test_an_answer_without_a_valid_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->renderForm($client, $document);
        $this->submit($client, $document, 'not-a-real-token', ['optionIndexes' => [1]]);

        self::assertResponseRedirects($this->reviewPath($document));
        $client->followRedirect();
        self::assertSelectorExists('.lp-flash--error');
        self::assertSame([], $this->selections()->findBy(['document' => $document]), 'a forged token must not record an answer');
    }

    /** Without Turbo the status line is never streamed, so a refusal shows as a flash. */
    public function test_a_rejected_answer_without_turbo_says_so_on_the_page(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, ['decisionId' => 'invented', 'optionIndexes' => [0]]);

        self::assertResponseRedirects($this->reviewPath($document));
        $client->followRedirect();
        self::assertSelectorExists('.lp-flash--error');
    }

    public function test_a_non_owner_cannot_answer(): void
    {
        $client = static::createClient();
        [, $document] = $this->seed($client);

        $em = $this->em();
        $stranger = new User(fullName: 'Stranger', email: 'stranger-'.uniqid().'@example.com', password: 'hashed');
        $stranger->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($stranger, static::getContainer());
        $em->persist($stranger);
        $em->flush();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_POST, $this->answerPath($document), [
            'save_decision_answer_form' => ['decisionId' => 'deploy-target', 'versionNumber' => '1', 'optionIndexes' => ['0']],
        ]);

        // Authorization runs on kernel.controller, before the form is touched.
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame([], $this->selections()->findBy(['document' => $document]));
    }

    /** @return array{User, Document} */
    private function seed(KernelBrowser $client): array
    {
        return $this->seedMarkdown($client, self::MARKDOWN);
    }

    /** @return array{User, Document} */
    private function seedMarkdown(KernelBrowser $client, string $markdown): array
    {
        $client->disableReboot();
        $em = $this->em();

        $owner = new User(fullName: 'Decider', email: 'decider-'.uniqid().'@example.com', password: 'hashed');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $create = static::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);

        return [$owner, $create(new CreateDocumentCommand($project, 'Deploy plan', $markdown))];
    }

    private function revise(Document $document, string $markdown): void
    {
        // The request cycle detached the seeded instance; revising needs a managed one.
        $managed = $this->em()->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $managed);
        $revise = static::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($managed, $markdown, 'Revised.'));
    }

    /** The text of the status line, lifted out of the stream that carries it. */
    private static function statusMessage(string $body): string
    {
        preg_match('~<span class="lp-decision-status__message[^"]*">(.*?)</span>~s', $body, $matches);
        if (!isset($matches[1])) {
            self::fail('the stream carries no status message');
        }

        return $matches[1];
    }

    /**
     * Answers as the page does. The form has no submit button, so the CSRF token
     * is lifted off the rendered page.
     *
     * @param array<string, mixed>  $fields
     * @param array<string, string> $server
     */
    private function answer(KernelBrowser $client, Document $document, array $fields, array $server = []): void
    {
        [$token] = $this->renderForm($client, $document);
        $this->submit($client, $document, $token, $fields, $server);
    }

    /** @return array{string, string} the CSRF token and the displayed version */
    private function renderForm(KernelBrowser $client, Document $document): array
    {
        $crawler = $client->request(Request::METHOD_GET, $this->reviewPath($document));

        return [
            (string) $crawler->filter('input[name="save_decision_answer_form[_token]"]')->attr('value'),
            (string) $crawler->filter('input[name="save_decision_answer_form[versionNumber]"]')->attr('value'),
        ];
    }

    /**
     * @param array<string, mixed>  $fields
     * @param array<string, string> $server
     */
    private function submit(KernelBrowser $client, Document $document, string $token, array $fields, array $server = []): void
    {
        $client->request(
            Request::METHOD_POST,
            $this->answerPath($document),
            ['save_decision_answer_form' => $fields + ['_token' => $token, 'decisionId' => 'deploy-target', 'versionNumber' => '1']],
            [],
            $server,
        );
    }

    private function selections(): DecisionSelectionRepository
    {
        $this->em()->clear();
        $selections = static::getContainer()->get(DecisionSelectionRepository::class);
        self::assertInstanceOf(DecisionSelectionRepository::class, $selections);

        return $selections;
    }

    private function answerRow(Document $document): ?DecisionAnswer
    {
        $this->em()->clear();
        $answers = static::getContainer()->get(DecisionAnswerRepository::class);
        self::assertInstanceOf(DecisionAnswerRepository::class, $answers);

        return $answers->findOneBy(['document' => $document->id, 'decisionId' => 'deploy-target']);
    }

    private function versionNumbered(Document $document, int $versionNumber): DocumentVersion
    {
        $versions = static::getContainer()->get(DocumentVersionRepository::class);
        self::assertInstanceOf(DocumentVersionRepository::class, $versions);
        $managed = $this->em()->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $managed);

        return $versions->findByNumber($managed, $versionNumber) ?? self::fail('no version '.$versionNumber);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function reviewPath(Document $document): string
    {
        return '/projects/'.$document->project->id.'/documents/'.$document->id.'/review';
    }

    private function answerPath(Document $document): string
    {
        return '/projects/'.$document->project->id.'/documents/'.$document->id.'/decisions/answer';
    }
}
