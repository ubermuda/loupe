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
        self::assertSelectorExists('#decision-deploy-target-1[checked]');
        self::assertSelectorNotExists('#decision-deploy-target-0[checked]');
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
        self::assertSelectorExists('#decision-deploy-target-1[checked][disabled]');
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
     * Authorization runs before the form, so test_a_non_owner_cannot_answer
     * cannot cover this: only a legitimate owner reaches the token check.
     */
    public function test_an_answer_without_a_valid_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed($client);

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, $this->decisionPath($document), [
            'select_decision_option_form' => [
                'decisionId' => 'deploy-target',
                'optionIndex' => '1',
                '_token' => 'not-a-real-token',
            ],
        ]);

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
        $client->request(Request::METHOD_GET, $this->reviewPath($document));
        $token = $client->getCrawler()->filter('input[name="select_decision_option_form[_token]"]')->attr('value');

        $client->request(
            Request::METHOD_POST,
            $this->decisionPath($document),
            ['select_decision_option_form' => [
                'decisionId' => $decisionId,
                'optionIndex' => $optionIndex,
                '_token' => $token ?? '',
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
