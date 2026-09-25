<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Security\CardFeedbackVoter;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Tests\Module\Board\CardMovedOutbox;
use App\Tests\Support\MercureCookies;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

final class CardCrudControllerTest extends WebTestCase
{
    use BoardScenario;
    use MercureCookies;

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_feedback_actions_refuse_a_foreign_owner_or_project(bool $foreignProject): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'feedback-scope-owner@example.com');
        $stranger = $this->user($em, 'feedback-scope-stranger@example.com');
        $project = $this->project($em, $owner);
        $otherProject = $this->project($em, $owner, 'Other project');
        $card = $this->card($em, $project, 'Private feedback');
        $comment = new SiteReviewComment($foreignProject ? $otherProject : $project, 0, 'Private capture', 'https://example.com');
        $link = new CardSiteReviewComment($card, $comment);
        $em->persist($comment);
        $em->persist($link);
        $em->flush();
        $token = new UsernamePasswordToken($foreignProject ? $owner : $stranger, 'main', ['ROLE_USER']);
        foreach ([CardFeedbackVoter::RESOLVE, CardFeedbackVoter::REOPEN] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, new CardFeedbackVoter()->vote($token, $link, [$attribute]));
        }
        $em->clear();
        $client->loginUser($foreignProject ? $owner : $stranger);
        $client->request(Request::METHOD_POST, '/board/feedback/'.$link->id.'/feedback/resolve', ['_csrf_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $stored = $em->find(SiteReviewComment::class, $comment->id);
        self::assertInstanceOf(SiteReviewComment::class, $stored);
        self::assertSame('pending', $stored->status->value);
    }

    public function test_card_feedback_status_actions_update_the_original_and_preserve_the_tab(): void
    {
        $surface = 'feedback';
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-feedback-status@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Resolve the capture');
        $comment = new SiteReviewComment($project, 0, 'Original capture', 'https://example.com');
        $link = new CardSiteReviewComment($card, $comment);
        $em->persist($comment);
        $em->persist($link);
        $em->flush();
        $em->clear();
        $client->loginUser($owner);
        $url = '/projects/'.$project->id.'/board/cards/'.$card->id.'?tab='.$surface;
        foreach (['resolve' => 'resolved', 'reopen' => 'pending'] as $action => $status) {
            $crawler = $client->request(Request::METHOD_GET, $url);
            self::assertResponseIsSuccessful();
            // Feedback holds the capture; Conversation holds the inbox requests.
            self::assertCount(1, $crawler->filter('#card-panel-feedback [data-site-feedback="'.$comment->id.'"]'));
            self::assertCount(0, $crawler->filter('#card-panel-conversation [data-site-feedback]'));
            $actionUrl = '/board/feedback/'.$link->id.'/'.$surface.'/'.$action;
            $form = $crawler->filter('form[action="'.$actionUrl.'"]')->form();
            $client->request(Request::METHOD_POST, $actionUrl, ['_csrf_token' => 'forged']);
            self::assertResponseStatusCodeSame(403);
            $client->submit($form);
            self::assertResponseRedirects($url);
            $client->followRedirect();
            self::assertSelectorExists('[data-panel-tabs-active-value="'.$surface.'"]');
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            $saved = $em->find(SiteReviewComment::class, $comment->id);
            self::assertInstanceOf(SiteReviewComment::class, $saved);
            self::assertSame($status, $saved->status->value);
            self::assertSame('Original capture', $saved->body);
            self::assertSame('https://example.com', $saved->url);
        }
    }

    public function test_a_feedback_url_that_is_not_http_renders_without_a_link(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-feedback-javascript@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Sneaky capture');
        $comment = new SiteReviewComment($project, 0, 'sneaky', 'javascript:alert(1)');
        $em->persist($comment);
        $em->persist(new CardSiteReviewComment($card, $comment));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id.'?tab=feedback');

        self::assertResponseIsSuccessful();
        $entry = $crawler->filter('#card-panel-feedback [data-site-feedback="'.$comment->id.'"]');
        self::assertCount(1, $entry);
        self::assertStringContainsString('sneaky', $entry->text());
        self::assertCount(0, $entry->filter('a[href^="javascript:"]'));
    }

    public function test_the_owner_creates_a_card_with_pull_request_links(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-create@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/new');
        self::assertResponseIsSuccessful();
        // The default column is chosen, and the labels are translated.
        self::assertSame('Backlog', trim($crawler->filter('select[name="create_card_form[column]"] option[selected]')->text()));
        // No feedback means the board opened the form, so Cancel goes back to it.
        self::assertSame('/projects/'.$project->id.'/board', $crawler->filter('.lp-form a.lp-btn--ghost')->attr('href'));

        $client->submitForm('Create card', [
            'create_card_form[title]' => 'Ship the board',
            'create_card_form[body]' => "It needs **columns**.\n",
            'create_card_form[type]' => CardType::Tooling->value,
            'create_card_form[column]' => (string) $this->column($project, 'next')->id,
            'create_card_form[pullRequestUrls]' => "https://github.com/loupe/loupe/pull/12\n\nnot-a-known-forge\n",
        ]);

        self::assertResponseRedirects();
        $em->clear();
        $cards = static::getContainer()->get(CardRepository::class);
        $created = $cards->findOneBy(['title' => 'Ship the board']);
        self::assertInstanceOf(Card::class, $created);
        self::assertSame('next', $created->column->slug);
        self::assertSame(CardType::Tooling, $created->type);
        // A form is a person writing the card down, whatever an agent does later.
        self::assertSame(CardReporter::Human, $created->reporter);
        self::assertCount(2, $created->pullRequests);
    }

    public function test_a_column_add_action_preselects_that_column(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-create-in-column@example.com');
        $project = $this->project($em, $owner);
        $next = $this->column($project, 'next');
        $em->clear();

        $client->loginUser($owner);
        $board = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertResponseIsSuccessful();
        self::assertCount(4, $board->filter('.lp-board__add-card'));

        $crawler = $client->request(
            Request::METHOD_GET,
            '/projects/'.$project->id.'/board/cards/new?column='.$next->id,
        );
        self::assertResponseIsSuccessful();
        self::assertSame('Next', trim($crawler->filter('select[name="create_card_form[column]"] option[selected]')->text()));
        // As a full page, the frame hands its navigation to the page, so the URL follows the redirect.
        self::assertSame('_top', $crawler->filter('turbo-frame#card-drawer-frame')->attr('target'));

        // The board opens the same form in its card drawer, so it renders in that frame.
        $crawler = $client->request(
            Request::METHOD_GET,
            '/projects/'.$project->id.'/board/cards/new?column='.$next->id,
            server: ['HTTP_TURBO_FRAME' => 'card-drawer-frame'],
        );
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#card-drawer-frame form[name="create_card_form"]'));
        self::assertCount(1, $crawler->filter('turbo-frame#card-drawer-frame a[data-action="card-drawer#close"]:contains("Cancel")'));
        self::assertNull($crawler->filter('turbo-frame#card-drawer-frame')->attr('target'));
    }

    public function test_a_blank_title_re_renders_the_create_form_with_422(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-create-invalid@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/new');
        $client->submitForm('Create card', ['create_card_form[title]' => '   ']);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_the_owner_edits_a_card_and_replaces_its_links(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-edit@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Before');
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/projects/'.$project->id.'/board/cards/'.$cardId.'/edit',
        );
        self::assertResponseIsSuccessful();
        self::assertSame('Before', $crawler->filter('#create_card_form_title')->attr('value'));
        // Edit swaps the drawer's content in place, and Cancel returns to the card inside it.
        self::assertCount(1, $crawler->filter('turbo-frame#card-drawer-frame form[name="create_card_form"]'));
        $cancel = $crawler->filter('turbo-frame#card-drawer-frame a:contains("Cancel")');
        self::assertSame('/projects/'.$project->id.'/board/cards/'.$cardId, $cancel->attr('href'));
        self::assertNull($cancel->attr('data-turbo-frame'));

        $client->submitForm('Save card', [
            'create_card_form[title]' => 'After',
            'create_card_form[body]' => 'Rewritten.',
            'create_card_form[type]' => CardType::Bug->value,
            'create_card_form[column]' => (string) $this->column($project, 'in-progress')->id,
            'create_card_form[pullRequestUrls]' => 'https://github.com/loupe/loupe/pull/99',
        ]);

        self::assertResponseRedirects();
        $em->clear();
        $fresh = $em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $fresh);
        self::assertSame('After', $fresh->title);
        self::assertSame('in-progress', $fresh->column->slug);
        self::assertCount(1, $fresh->pullRequests);
        $link = $fresh->pullRequests->first();
        self::assertInstanceOf(CardPullRequest::class, $link);
        self::assertSame('https://github.com/loupe/loupe/pull/99', $link->url);

        // The status changed, so the edit is also a move with an outbox row.
        $payload = CardMovedOutbox::onlyPayload(static::getContainer(), $project);
        self::assertSame(CardReporter::Human->value, $payload['actor'] ?? null);
    }

    public function test_an_emptied_url_box_clears_every_link(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-clear-links@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Linked');
        $card->replacePullRequests(new CardPullRequest(
            card: $card,
            url: 'https://github.com/loupe/loupe/pull/1',
        ));
        $em->flush();
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$cardId.'/edit');
        $client->submitForm('Save card', [
            'create_card_form[title]' => 'Linked',
            'create_card_form[pullRequestUrls]' => '',
        ]);

        self::assertResponseRedirects();
        $em->clear();
        $fresh = $em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $fresh);
        self::assertCount(0, $fresh->pullRequests);
    }

    public function test_the_owner_deletes_a_card_through_the_confirmation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-delete@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Doomed');
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/projects/'.$project->id.'/board/cards/'.$cardId,
        );
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#card-drawer-frame .lp-card-drawer'));
        // Overview, Details, Conversation and Feedback.
        self::assertCount(4, $crawler->filter('[role="tab"]'));
        // A missing translation renders its key, and the gate does not fail on that.
        self::assertDoesNotMatchRegularExpression('/\\bboard\\.card\\.[a-z_.]+/', $crawler->filter('main')->text());
        self::assertCount(1, $crawler->filter('a[data-action="card-drawer#close"]'));

        $client->submit($crawler->filter('form[action$="/delete"]')->form());

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        $em->clear();
        self::assertNull($em->find(Card::class, $cardId));
    }

    public function test_deleting_without_a_token_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-delete-untokened@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Still here');
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(
            Request::METHOD_POST,
            '/projects/'.$project->id.'/board/cards/'.$cardId.'/delete',
            ['_csrf_token' => 'invalid-token'],
        );

        self::assertResponseStatusCodeSame(403);
        $em->clear();
        self::assertInstanceOf(Card::class, $em->find(Card::class, $cardId));
    }

    public function test_the_card_page_moves_a_card_without_a_pointer(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-move-page@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Reachable by keyboard');
        $quiet = $this->card($em, $project, 'No runs yet');
        $cardId = $card->id;
        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            cardId: $card->id ?? throw new \LogicException('card id after flush'),
            cardNumber: $card->number,
            ruleName: 'plan the card',
            state: WorkerRunState::Failed,
            sessionId: Uuid::v4(),
            startedAt: new \DateTimeImmutable('-2 hours'),
            endedAt: new \DateTimeImmutable('-2 hours +3 minutes'),
            exitCode: 1,
        );
        $em->persist($run);
        $em->flush();
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$cardId);
        self::assertResponseIsSuccessful();
        // The overview names its linked work and shows the card's dates, never a raw key.
        self::assertSelectorTextContains('.lp-card-docs', 'Linked work');
        self::assertStringNotContainsString('board.', $crawler->filter('.lp-card-overview')->text());
        self::assertSame(['Status', 'Type', 'Reporter', 'Created', 'Updated'], $crawler->filter('.lp-card-fields dt')->each(static fn (Crawler $term): string => $term->text()));
        // Each agent run links to its own drawer on the run history page.
        $row = $crawler->filter('[data-card-runs] [data-card-run="'.$runId.'"]');
        self::assertSame('/projects/'.$project->id.'/worker-runs?search='.$runId, $row->attr('href'));
        self::assertStringContainsString('plan the card', $row->text());
        self::assertStringContainsString('Failed', $row->filter('.lp-status-chip')->text());
        self::assertSame('/projects/'.$project->id.'/board/cards/'.$cardId.'/edit', $crawler->filter('.lp-card-drawer__header-actions a')->first()->attr('href'));
        self::assertNull($crawler->filter('.lp-card-drawer__header-actions a')->first()->attr('data-turbo-frame'));

        $name = 'move_card_'.$cardId;
        // Turbo is off on this form. Its answer is a redirect to the board
        // rather than a stream, because this page holds no board to replace.
        self::assertCount(1, $crawler->filter('form[data-turbo="false"] select[name="'.$name.'[column]"]'));

        $client->submitForm('Move card', [
            $name.'[column]' => (string) $this->column($project, 'in-progress')->id,
        ]);

        self::assertResponseRedirects('/projects/'.$project->id.'/board');
        $em->clear();
        $moved = static::getContainer()->get(CardRepository::class)->find($cardId);
        self::assertInstanceOf(Card::class, $moved);
        self::assertSame('in-progress', $moved->column->slug);

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$quiet->id);
        self::assertCount(0, $crawler->filter('[data-card-runs] [data-card-run]'));
        self::assertSelectorTextContains('[data-card-runs]', 'No agent has run on this card yet.');
    }

    /** A queued run has no session, no start and no end yet, and the card still lists it. */
    public function test_the_card_page_lists_a_run_that_has_not_started(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-queued-run@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Waits for a worker');
        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            cardId: $card->id ?? throw new \LogicException('card id after flush'),
            cardNumber: $card->number,
            ruleName: 'queued rule',
            state: WorkerRunState::Queued,
            runKey: Uuid::v7(),
            receivedAt: new \DateTimeImmutable('2026-03-04 05:06:00'),
        );
        $em->persist($run);
        $em->flush();
        $runId = (string) $run->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-card-runs] [data-card-run="'.$runId.'"]');
        self::assertSame('Queued', $row->filter('.lp-status-chip')->text());
        self::assertSame('2026-03-04T05:06:00+00:00', $row->filter('time')->attr('datetime'));
        self::assertStringNotContainsString('·', $row->filter('.lp-card-run__meta')->text());
        // A live update reloads the section from the Bridge fragment, not the whole card.
        $refresh = $crawler->filter('[data-controller="worker-run-refresh"]');
        self::assertSame('/projects/'.$project->id.'/worker-runs/card/'.$card->id, $refresh->attr('data-worker-run-refresh-url-value'));
        self::assertCount(1, $refresh->filter('turbo-frame#card-worker-runs[data-worker-run-refresh-target="frame"] [data-card-runs]'));
        self::assertNull($refresh->filter('turbo-frame#card-worker-runs')->attr('src'));
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $runTopic = $topics->forWorkerRuns($project->id ?? throw new \LogicException('project id after flush'));
        self::assertContains($runTopic, $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]')->each(static fn (Crawler $input): ?string => $input->attr('value')));

        // In the board drawer the board page holds the topic, so the frame response leaves the cookie alone.
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id, server: ['HTTP_TURBO_FRAME' => 'card-drawer-frame']);
        self::assertResponseIsSuccessful();
        self::assertNotContains($runTopic, self::subscribedTopics($client->getResponse()) ?? []);
    }

    public function test_a_stranger_cannot_reach_a_card(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-owner@example.com');
        $stranger = $this->user($em, 'card-stranger@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Private');
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$cardId);
        self::assertResponseStatusCodeSame(403);

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$cardId.'/edit');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_card_from_another_project_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-crossed@example.com');
        $mine = $this->project($em, $owner, 'mine');
        $other = $this->project($em, $owner, 'other');
        $card = $this->card($em, $other, 'Elsewhere');
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$mine->id.'/board/cards/'.$cardId);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_creating_is_not_found_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $this->disableBoard();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'card-create-flag-off@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/new');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_link_with_an_unsafe_scheme_is_shown_but_never_href(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-unsafe-link@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Carries a hostile link');
        // A link is stored as given by whoever wrote the card, so the render is
        // the last place that can refuse the scheme.
        $card->replacePullRequests(
            new CardPullRequest(card: $card, url: 'javascript:alert(1)'),
            new CardPullRequest(card: $card, url: 'https://github.com/loupe/loupe/pull/4'),
        );
        $em->flush();
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$cardId);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Item', 'Status', 'Open'],
            $crawler->filter('.lp-card-pulls thead th')->each(static fn (Crawler $node): string => trim($node->text())),
        );
        self::assertStringContainsString('Not reported', $crawler->filter('.lp-card-pulls')->text());
        $hrefs = $crawler->filter('.lp-card-pulls__item a')->each(
            static fn (Crawler $node): string => (string) $node->attr('href'),
        );
        // Guard: the safe link proves the list rendered at all.
        self::assertSame(['https://github.com/loupe/loupe/pull/4'], $hrefs);
        // Shown, not hidden: the reader still sees what the card carries.
        self::assertStringContainsString('javascript:alert(1)', $crawler->filter('.lp-card-pulls')->text());
    }

    public function test_the_card_page_lists_its_linked_cards_as_each_card_reads_them(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-linked-cards@example.com');
        $project = $this->project($em, $owner);
        $blocker = $this->card($em, $project, 'Ship the schema');
        $blocked = $this->card($em, $project, 'Ship the page');
        $alone = $this->card($em, $project, 'Stands alone');
        $em->persist(new CardLink($blocker, $blocked, CardLinkKind::Blocks));
        $em->flush();
        $blockerId = $blocker->id;
        $blockedId = $blocked->id;
        $aloneId = $alone->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$blockerId);
        self::assertResponseIsSuccessful();
        $section = $crawler->filter('[data-linked-cards]');
        self::assertStringContainsString('Linked cards', $section->text());
        $row = $section->filter('[data-linked-card="'.$blockedId.'"]');
        self::assertStringContainsString('Blocks', $row->text());
        self::assertStringContainsString('Ship the page', $row->text());
        $open = $row->filter('a');
        self::assertSame('/projects/'.$project->id.'/board/cards/'.$blockedId, $open->attr('href'));
        self::assertSame('card-drawer-frame', $open->attr('data-turbo-frame'));

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$blockedId, server: ['HTTP_TURBO_FRAME' => 'card-drawer-frame']);
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-linked-cards] [data-linked-card="'.$blockerId.'"]');
        self::assertStringContainsString('Blocked by', $row->text());
        self::assertStringContainsString('Ship the schema', $row->text());

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$aloneId);
        self::assertResponseIsSuccessful();
        // Guard: the page rendered its overview, so the absent section means something.
        self::assertCount(1, $crawler->filter('.lp-card-overview'));
        self::assertCount(0, $crawler->filter('[data-linked-cards]'));
    }
}
