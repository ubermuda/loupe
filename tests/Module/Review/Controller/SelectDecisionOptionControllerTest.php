<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Query\GetReview;
use App\Module\Review\Repository\DecisionSelectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SelectDecisionOptionControllerTest extends WebTestCase
{
    private const string MARKDOWN = <<<'MD'
        Where should this land?

        <!-- decision: deploy-target -->

        - [ ] Ship to staging first
        - [ ] Ship straight to production

        <!-- /decision -->
        MD;

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
        self::assertSelectorExists('[data-decision-target="form"]');
        self::assertSelectorExists('[data-decision-target="decisionId"]');
    }

    public function test_answering_records_the_choice_and_shows_it_on_the_next_visit(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, 'deploy-target', '1');

        self::assertResponseRedirects($this->reviewPath($document));

        $selections = static::getContainer()->get(DecisionSelectionRepository::class);
        self::assertInstanceOf(DecisionSelectionRepository::class, $selections);
        $selection = $selections->findOneByDocumentAndDecisionId($document, 'deploy-target');
        self::assertNotNull($selection);
        self::assertSame(1, $selection->optionIndex);

        $client->request(Request::METHOD_GET, $this->reviewPath($document));
        self::assertSelectorExists('#decision_option_deploy-target_1[checked]');
        self::assertSelectorNotExists('#decision_option_deploy-target_0[checked]');
    }

    /**
     * An earlier version is a record of what was discussed then, so it shows the
     * answer but cannot take a new one — rendering it blank would read as
     * unanswered, which is a different claim.
     */
    public function test_an_earlier_version_shows_the_answer_locked(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, 'deploy-target', '1');

        // The request cycle detached the seeded instance; revising needs a managed one.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $managed = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $managed);

        $revise = static::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($managed, self::MARKDOWN."\n\nMore.\n", 'Added a closing note.'));

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
        $this->answer($client, $document, 'invented', '0', ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('target="decision-status"', (string) $client->getResponse()->getContent());

        $selections = static::getContainer()->get(DecisionSelectionRepository::class);
        self::assertInstanceOf(DecisionSelectionRepository::class, $selections);
        self::assertSame([], $selections->findBy(['document' => $document]));
    }

    /**
     * The stream replaces only the status line: the radio is already in the
     * state the reviewer left it, and replacing the prose would tear out the
     * comment highlights anchored into it.
     */
    public function test_a_turbo_answer_streams_back_only_the_status_line(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, 'deploy-target', '0', ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="decision-status">', $body);
        self::assertStringNotContainsString('lp-review-doc__prose', $body);
    }

    /**
     * Two options that render to the same label are an authoring smell, not an
     * error, so the block still converts — which makes the label alone unable to
     * say which one was clicked. The page and the payload must at least agree,
     * and both must land on the second.
     */
    public function test_choosing_the_later_of_two_identically_worded_options_sticks(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $owner = new User(username: 'twin-'.uniqid(), fullName: 'Twin', email: 'twin-'.uniqid().'@example.com', password: 'hashed');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $create = static::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);
        $document = $create(new CreateDocumentCommand(
            $project,
            'Twin options',
            "<!-- decision: deploy-target -->\n\n1. Ship it\n2. Ship it\n\n<!-- /decision -->\n",
        ));

        $client->loginUser($owner);
        $this->answer($client, $document, 'deploy-target', '1');

        $client->request(Request::METHOD_GET, $this->reviewPath($document));
        self::assertSelectorExists('#decision_option_deploy-target_1[checked]');
        self::assertSelectorNotExists('#decision_option_deploy-target_0[checked]');

        $getReview = static::getContainer()->get(GetReview::class);
        self::assertInstanceOf(GetReview::class, $getReview);
        $decisions = $getReview($document)['decisions'];
        self::assertSame(['Ship it', 'Ship it'], $decisions[0]['options']);
        self::assertSame(1, $decisions[0]['selected_index']);
    }

    /**
     * The real interleaving: the reviewer has the page open, a revision reorders
     * the options underneath them, and their click arrives describing a list
     * that no longer exists.
     *
     * Resolving position 1 against the new list would record "Ship to staging
     * first" — the option they were looking at when they clicked position 1 was
     * "Ship straight to production". Refusing costs them one click; accepting
     * puts words in their mouth.
     */
    public function test_an_answer_submitted_against_a_superseded_version_is_refused(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        [$token, $versionNumber] = $this->renderForm($client, $document);
        self::assertSame('1', $versionNumber);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $managed = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $managed);

        $revise = static::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand(
            $managed,
            "Where should this land?\n\n<!-- decision: deploy-target -->\n\n1. Ship straight to production\n2. Ship to staging first\n\n<!-- /decision -->\n",
            'Reordered the deploy options.',
        ));

        $this->submitAnswer($client, $document, 'deploy-target', '1', $token, $versionNumber);

        $selections = static::getContainer()->get(DecisionSelectionRepository::class);
        self::assertInstanceOf(DecisionSelectionRepository::class, $selections);
        self::assertSame([], $selections->findBy(['document' => $document]), 'a stale index must not be recorded');

        // And the reviewer is told why, rather than the click vanishing.
        self::assertResponseRedirects($this->reviewPath($document));
        $client->followRedirect();
        self::assertSelectorTextContains('.lp-flash--error', 'changed while you were reading');
    }

    /**
     * The same staleness on the Turbo path, where the status line rather than a
     * flash is the only surface the reviewer sees.
     */
    public function test_a_superseded_answer_reports_on_the_turbo_path_too(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        [$token, $versionNumber] = $this->renderForm($client, $document);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $managed = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $managed);

        $revise = static::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($managed, self::MARKDOWN."\n\nMore.\n", 'Added a note.'));

        $this->submitAnswer(
            $client,
            $document,
            'deploy-target',
            '1',
            $token,
            $versionNumber,
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('target="decision-status"', $body);
        self::assertStringContainsString('changed while you were reading', $body);

        // The browser leaves the clicked radio checked, so a refusal that only
        // replaced the status line would leave the page claiming a selection
        // the database does not hold.
        self::assertStringContainsString('target="decision_block_deploy-target"', $body);
        self::assertStringNotContainsString('checked', $body);
    }

    /**
     * The case a "re-render the block" fix gets wrong: with nothing stored, the
     * restored block must clear the radio rather than fall back to some earlier
     * answer. This reviewer has never answered, so nothing may come back checked.
     */
    public function test_a_refused_first_answer_streams_the_block_back_with_nothing_checked(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        [, $versionNumber] = $this->renderForm($client, $document);
        $this->submitAnswer(
            $client,
            $document,
            'deploy-target',
            '1',
            'not-a-real-token',
            $versionNumber,
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('target="decision_block_deploy-target"', $body);
        self::assertStringContainsString('data-decision-option="deploy-target:1"', $body);
        self::assertStringNotContainsString('checked', $body);

        $selections = static::getContainer()->get(DecisionSelectionRepository::class);
        self::assertInstanceOf(DecisionSelectionRepository::class, $selections);
        self::assertSame([], $selections->findBy(['document' => $document]));
    }

    /**
     * With an answer already stored, a later refused submission must put THAT
     * answer back — not the clicked one, and not nothing.
     */
    public function test_a_refused_change_streams_the_block_back_showing_the_stored_answer(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, 'deploy-target', '0');

        [, $versionNumber] = $this->renderForm($client, $document);
        $this->submitAnswer(
            $client,
            $document,
            'deploy-target',
            '1',
            'not-a-real-token',
            $versionNumber,
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-decision-option="deploy-target:0" checked', $body);
        self::assertStringNotContainsString('data-decision-option="deploy-target:1" checked', $body);
    }

    /**
     * The prose carries every comment anchor, so a stream that replaced it would
     * disturb them for no reason. Only the block is a target.
     */
    public function test_a_refusal_never_streams_back_the_document_pane(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        [, $versionNumber] = $this->renderForm($client, $document);
        $this->submitAnswer(
            $client,
            $document,
            'deploy-target',
            '1',
            'not-a-real-token',
            $versionNumber,
            ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html'],
        );

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('lp-review-doc__prose', $body);
        self::assertStringNotContainsString('data-comment-anchor-target', $body);
        self::assertSame(2, substr_count($body, '<turbo-stream'), 'exactly the block and the status line');
    }

    /**
     * A diff replaces the document pane with Markdown source lines, so the
     * radios the form serves are not on the page at all — leaving a status line
     * with nothing to report and a form with no control to fill it.
     *
     * Omitted structurally rather than left to render harmlessly: the diff
     * controller passes neither `hasDecisions` nor `selectDecisionForm`, and
     * `strict_variables` is on, so without the guard this is a 500 rather than a
     * cosmetic gap.
     */
    public function test_the_decision_controls_are_absent_from_a_diff(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $managed = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $managed);

        $revise = static::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($managed, self::MARKDOWN."\n\nA closing note.\n", 'Added a note.'));

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->reviewPath($document).'/diff/1/2');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-decision-target="form"]');
        self::assertSelectorNotExists('#decision-status');
        self::assertSelectorNotExists('[data-decision-option]');

        // And the same document still shows them on the review page, so the
        // absence above is the diff's doing rather than a broken fixture.
        $client->request(Request::METHOD_GET, $this->reviewPath($document));
        self::assertSelectorExists('[data-decision-target="form"]');
        self::assertSelectorExists('[data-decision-option]');
    }

    /**
     * Authorization runs before the form, so test_a_non_owner_cannot_answer
     * cannot cover this: only a legitimate owner reaches the token check.
     */
    public function test_an_answer_without_a_valid_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        // Everything except the token is exactly what a good submission carries,
        // so the token is the only difference from the test that records an answer.
        [, $versionNumber] = $this->renderForm($client, $document);
        $this->submitAnswer($client, $document, 'deploy-target', '1', 'not-a-real-token', $versionNumber);

        // Rejected as an invalid form, not as a 403 or a routing miss — the same
        // request with a real token records the answer in the test above, so the
        // token is the only difference between the two outcomes.
        self::assertResponseRedirects($this->reviewPath($document));
        $client->followRedirect();
        self::assertSelectorExists('.lp-flash--error');

        $selections = static::getContainer()->get(DecisionSelectionRepository::class);
        self::assertInstanceOf(DecisionSelectionRepository::class, $selections);
        self::assertSame([], $selections->findBy(['document' => $document]), 'a forged token must not record an answer');
    }

    /**
     * Without Turbo the status line is never streamed, so a rejected answer is
     * only visible as a flash — the branch that turns a silent no-op into
     * something the reviewer can see.
     */
    public function test_a_rejected_answer_without_turbo_says_so_on_the_page(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $this->answer($client, $document, 'invented', '0');

        self::assertResponseRedirects($this->reviewPath($document));
        $client->followRedirect();
        self::assertSelectorExists('.lp-flash--error');
    }

    public function test_a_non_owner_cannot_answer(): void
    {
        $client = static::createClient();
        [, $document] = $this->seed($client);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $stranger = new User(username: 'stranger-'.uniqid(), fullName: 'Stranger', email: 'stranger-'.uniqid().'@example.com', password: 'hashed');
        $stranger->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($stranger);
        $em->flush();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_POST, $this->decisionPath($document), [
            'select_decision_option_form' => ['decisionId' => 'deploy-target', 'optionIndex' => '0'],
        ]);

        // Authorization runs on kernel.controller, before the form is touched,
        // so a stranger is refused without ever needing a valid token.
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @return array{User, Document}
     */
    private function seed(KernelBrowser $client): array
    {
        $client->disableReboot();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $owner = new User(username: 'decider-'.uniqid(), fullName: 'Decider', email: 'decider-'.uniqid().'@example.com', password: 'hashed');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $create = static::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);

        return [$owner, $create(new CreateDocumentCommand($project, 'Deploy plan', self::MARKDOWN))];
    }

    /**
     * Answers as the page does: the form carries no submit button (the Stimulus
     * controller fills and submits it), so the CSRF token has to be lifted off
     * the rendered page rather than picked up by submitForm().
     *
     * @param array<string, string> $server
     */
    private function answer(KernelBrowser $client, Document $document, string $decisionId, string $optionIndex, array $server = []): void
    {
        [$token, $versionNumber] = $this->renderForm($client, $document);
        $this->submitAnswer($client, $document, $decisionId, $optionIndex, $token, $versionNumber, $server);
    }

    /**
     * Renders the page and returns the two values the form carries from it, so a
     * test can hold them across a revision and submit what the reviewer saw.
     *
     * @return array{string, string} the CSRF token and the displayed version
     */
    private function renderForm(KernelBrowser $client, Document $document): array
    {
        $client->request(Request::METHOD_GET, $this->reviewPath($document));
        $crawler = $client->getCrawler();

        return [
            (string) $crawler->filter('input[name="select_decision_option_form[_token]"]')->attr('value'),
            (string) $crawler->filter('input[name="select_decision_option_form[versionNumber]"]')->attr('value'),
        ];
    }

    /**
     * @param array<string, string> $server
     */
    private function submitAnswer(
        KernelBrowser $client,
        Document $document,
        string $decisionId,
        string $optionIndex,
        string $token,
        string $versionNumber,
        array $server = [],
    ): void {
        $client->request(
            Request::METHOD_POST,
            $this->decisionPath($document),
            ['select_decision_option_form' => [
                'decisionId' => $decisionId,
                'optionIndex' => $optionIndex,
                'versionNumber' => $versionNumber,
                '_token' => $token,
            ]],
            [],
            $server,
        );
    }

    private function reviewPath(Document $document): string
    {
        return '/projects/'.$document->project->id.'/documents/'.$document->id.'/review';
    }

    private function decisionPath(Document $document): string
    {
        return '/projects/'.$document->project->id.'/documents/'.$document->id.'/decisions';
    }
}
