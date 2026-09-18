<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Form\AttachSiteReviewCommentFormType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Security\CardFeedbackVoter;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Tests\Module\Board\CardMovedOutbox;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class CardCrudControllerTest extends WebTestCase
{
    use BoardScenario;

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_feedback_replies_refuse_a_foreign_owner_or_project(bool $foreignProject): void
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
        foreach ([CardFeedbackVoter::REPLY, CardFeedbackVoter::RESOLVE, CardFeedbackVoter::REOPEN] as $attribute) {
            self::assertSame(VoterInterface::ACCESS_DENIED, new CardFeedbackVoter()->vote($token, $link, [$attribute]));
        }
        $em->clear();
        $client->loginUser($foreignProject ? $owner : $stranger);
        $name = 'site_reply_feedback_'.$comment->id;
        $client->request(Request::METHOD_POST, '/board/feedback/'.$link->id.'/feedback/reply', [
            $name => ['body' => 'Must not be saved.', 'submissionId' => '01995498-93aa-7000-8000-000000000001', '_token' => 'forged'],
        ]);
        self::assertResponseStatusCodeSame(403);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM site_review_replies'));
    }

    #[TestWith(['conversation'])]
    #[TestWith(['feedback'])]
    public function test_card_feedback_status_actions_update_the_original_and_preserve_the_tab(string $surface): void
    {
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

    #[TestWith(['conversation'])]
    #[TestWith(['feedback'])]
    public function test_card_feedback_replies_share_the_site_record_and_keep_the_selected_tab(string $surface): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-feedback-reply@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Reply to the capture');
        $comment = new SiteReviewComment($project, 0, 'Move the control', 'https://example.com');
        $em->persist($comment);
        $em->persist(new CardSiteReviewComment($card, $comment));
        $em->flush();
        $em->clear();
        $client->loginUser($owner);
        $url = '/projects/'.$project->id.'/board/cards/'.$card->id;
        $crawler = $client->request(Request::METHOD_GET, $url.'?tab='.$surface);
        self::assertResponseIsSuccessful();
        $name = 'site_reply_'.$surface.'_'.$comment->id;
        $form = $crawler->filter('form[name="'.$name.'"]')->form([$name.'[body]' => 'The shared reply.']);
        $client->submit($form);
        self::assertResponseRedirects($url.'?tab='.$surface);
        $client->followRedirect();
        self::assertSelectorTextContains('#card-panel-conversation [data-site-review-reply]', 'The shared reply.');
        self::assertSelectorTextContains('#card-panel-feedback [data-site-review-reply]', 'The shared reply.');
        $client->submit($form);
        self::assertResponseRedirects($url.'?tab='.$surface);
        $form[$name.'[body]'] = 'Keep this conflicting draft.';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-panel-tabs-active-value="'.$surface.'"]');
        self::assertSelectorTextContains('textarea[name="'.$name.'[body]"]', 'Keep this conflicting draft.');
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');
        self::assertSelectorCount(1, '[data-site-review-reply]');
        self::assertSelectorTextContains('[data-site-review-reply]', 'The shared reply.');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM site_review_replies WHERE comment_id = ?', [(string) $comment->id]));
    }

    public function test_the_owner_attaches_unlinked_feedback_to_an_open_card(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-attach-feedback@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Fix the feedback');
        $comment = new SiteReviewComment($project, 0, 'Move the control', 'https://example.com');
        $em->persist($comment);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Create card and attach',
            $crawler->filter('.lp-feedback-detail__panel a.lp-btn')->text(),
        );

        $client->submitForm('Attach to card', [
            AttachSiteReviewCommentFormType::nameFor($comment).'[card]' => (string) $card->id,
        ]);

        self::assertResponseRedirects('/projects/'.$project->id.'/site-review#feedback-'.$comment->id);
        $em->clear();
        $links = static::getContainer()->get(CardSiteReviewCommentRepository::class);
        $link = $links->findOneBy(['comment' => $comment->id]);
        self::assertNotNull($link);
        self::assertSame((string) $card->id, (string) $link->card->id);
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

    public function test_the_owner_creates_a_card_from_feedback_and_attaches_it(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'card-create-from-feedback@example.com');
        $project = $this->project($em, $owner);
        $comment = new SiteReviewComment($project, 0, 'Strengthen the button contrast', 'https://example.com');
        $em->persist($comment);
        $em->flush();
        $commentId = $comment->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/projects/'.$project->id.'/board/cards/new?feedback='.$commentId,
        );
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Strengthen the button contrast', $crawler->filter('.lp-card-feedback-context')->text());

        $client->submitForm('Create card', [
            'create_card_form[title]' => 'Improve button contrast',
        ]);

        self::assertResponseRedirects();
        $em->clear();
        $created = static::getContainer()->get(CardRepository::class)->findOneBy(['title' => 'Improve button contrast']);
        self::assertInstanceOf(Card::class, $created);
        $link = static::getContainer()->get(CardSiteReviewCommentRepository::class)->findOneBy(['comment' => $commentId]);
        self::assertNotNull($link);
        self::assertSame((string) $created->id, (string) $link->card->id);
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
        self::assertCount(3, $crawler->filter('[role="tab"]'));
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
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$cardId);
        self::assertResponseIsSuccessful();
        // The overview names its linked work and shows the card's dates, never a raw key.
        self::assertSelectorTextContains('.lp-card-docs', 'Linked work');
        self::assertStringNotContainsString('board.', $crawler->filter('.lp-card-overview-grid')->text());
        self::assertSame(['Status', 'Reporter', 'Type', 'Created', 'Updated'], $crawler->filter('.lp-card-fact-list dt')->each(static fn (Crawler $term): string => $term->text()));
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
}
