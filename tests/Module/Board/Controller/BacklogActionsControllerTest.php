<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Form\BulkMoveBacklogCardsFormType;
use App\Module\Board\Form\MoveBacklogCardFormType;
use App\Module\Board\Form\RankBacklogCardFormType;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\TurboBundle;

/** The rank, move and bulk move endpoints of the Backlog page. */
final class BacklogActionsControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_page_renders_the_row_forms_the_endpoints_read(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-forms@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Waiting');
        $rankName = RankBacklogCardFormType::nameFor($card);
        $moveName = MoveBacklogCardFormType::nameFor($card);
        $url = '/projects/'.$project->id.'/board/backlog';
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url.'?type=feature');
        self::assertResponseIsSuccessful();

        $row = $crawler->filter('#backlog-row-'.$card->id);
        self::assertCount(1, $crawler->filter('[data-controller="backlog-rank"] #backlog-row-'.$card->id.' [name="'.$rankName.'[beforeCardId]"]'));
        self::assertCount(1, $row->filter('input[name="'.$moveName.'[_token]"]'));
        self::assertSame(
            ['next', 'in-progress', 'done'],
            $row->filter('button[name="'.$moveName.'[column]"]')->each(fn ($button): string => $this->slugOf($project, (string) $button->attr('value'))),
        );
        self::assertStringEndsWith('/move?page=1&type=feature', (string) $row->filter('form.lp-backlog-menu')->attr('action'));
        self::assertCount(1, $row->filter('input[name="'.BulkMoveBacklogCardsFormType::NAME.'[ids][]"][form="backlog-bulk-form"]'));
        self::assertSame('Move to Next', trim($crawler->filter('#backlog-bulk-form button.lp-btn--primary')->text()));
    }

    public function test_no_other_sort_draws_the_rank_handle(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-no-handle@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Waiting');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/backlog?sort=newest');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-backlog-card-id]'));
        self::assertCount(0, $crawler->filter('[data-controller="backlog-rank"]'));
        self::assertCount(0, $crawler->filter('.lp-backlog-grip'));
    }

    public function test_a_rank_moves_the_card_above_its_neighbour_and_answers_an_empty_stream(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-rank@example.com');
        $project = $this->project($em, $owner);
        $first = $this->card($em, $project, 'First', 'backlog', 0);
        $this->card($em, $project, 'Second', 'backlog', 1);
        $third = $this->card($em, $project, 'Third', 'backlog', 2);
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->cardUrl($third, 'rank'), RankBacklogCardFormType::nameFor($third), ['beforeCardId' => (string) $first->id], stream: true);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(TurboBundle::STREAM_MEDIA_TYPE, (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(['Third', 'First', 'Second'], $this->titlesIn($project, 'backlog'));
    }

    public function test_a_move_removes_the_row_and_confirms_it(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-move@example.com');
        $project = $this->project($em, $owner);
        $mover = $this->card($em, $project, 'Mover', 'backlog', 0);
        $this->card($em, $project, 'Stays', 'backlog', 1);
        $next = (string) $this->column($project, 'next')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->cardUrl($mover, 'move'), MoveBacklogCardFormType::nameFor($mover), ['column' => $next], stream: true);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('action="remove" target="backlog-row-'.$mover->id.'"', $body);
        self::assertStringContainsString('1 card moved to Next.', $body);
        self::assertStringContainsString('target="backlog-filter-count"', $body);
        self::assertStringNotContainsString('target="backlog-results"', $body);
        self::assertSame(['Mover'], $this->titlesIn($project, 'next'));
    }

    public function test_a_move_that_empties_the_page_redraws_the_results(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-move-last@example.com');
        $project = $this->project($em, $owner);
        $mover = $this->card($em, $project, 'Last one');
        $next = (string) $this->column($project, 'next')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->cardUrl($mover, 'move'), MoveBacklogCardFormType::nameFor($mover), ['column' => $next], stream: true);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('target="backlog-results"', $body);
        self::assertStringContainsString('The Backlog is empty', $body);
    }

    public function test_a_bulk_move_of_a_whole_page_redraws_it_with_the_next_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-bulk-page@example.com');
        $project = $this->project($em, $owner);
        $ids = [];
        for ($index = 0; $index < 27; ++$index) {
            $card = $this->card($em, $project, 'Card '.$index, 'backlog', $index);
            if ($index < 25) {
                $ids[] = (string) $card->id;
            }
        }
        $next = (string) $this->column($project, 'next')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->bulkUrl($project), BulkMoveBacklogCardsFormType::NAME, ['ids' => $ids, 'column' => $next], stream: true);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('target="backlog-results"', $body);
        self::assertStringContainsString('Card 26', $body);
        self::assertStringNotContainsString('action="remove"', $body);
    }

    public function test_a_move_that_leaves_rows_on_the_page_only_removes_its_row(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-move-page-two@example.com');
        $project = $this->project($em, $owner);
        $cards = [];
        for ($index = 0; $index < 27; ++$index) {
            $cards[] = $this->card($em, $project, 'Card '.$index, 'backlog', $index);
        }
        $next = (string) $this->column($project, 'next')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->cardUrl($cards[25], 'move').'?page=2', MoveBacklogCardFormType::nameFor($cards[25]), ['column' => $next], stream: true);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('action="remove" target="backlog-row-'.$cards[25]->id.'"', $body);
        self::assertStringNotContainsString('target="backlog-results"', $body);
    }

    public function test_a_refused_move_answers_a_stream_with_the_reason(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-move-reason@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Epic'), CardType::Epic);
        $this->childOf($em, $epic, $this->card($em, $project, 'Open child', 'next'));
        $done = (string) $this->column($project, 'done')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->cardUrl($epic, 'move'), MoveBacklogCardFormType::nameFor($epic), ['column' => $done], stream: true);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith(TurboBundle::STREAM_MEDIA_TYPE, (string) $client->getResponse()->headers->get('Content-Type'));
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('target="backlog-confirmation"', $body);
        self::assertStringContainsString('#2', $body);
        self::assertSame(['Epic'], $this->titlesIn($project, 'backlog'));
    }

    public function test_a_move_without_turbo_redirects_back_with_the_filters(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-move-plain@example.com');
        $project = $this->project($em, $owner);
        $mover = $this->card($em, $project, 'Mover');
        $next = (string) $this->column($project, 'next')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->cardUrl($mover, 'move').'?type=feature', MoveBacklogCardFormType::nameFor($mover), ['column' => $next]);

        self::assertResponseRedirects('/projects/'.$project->id.'/board/backlog?page=1&type=feature');
        self::assertSame(['Mover'], $this->titlesIn($project, 'next'));
    }

    public function test_a_move_of_a_card_outside_the_backlog_is_refused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-move-refused@example.com');
        $project = $this->project($em, $owner);
        $onBoard = $this->card($em, $project, 'On the board', 'next');
        $done = (string) $this->column($project, 'done')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->cardUrl($onBoard, 'move'), MoveBacklogCardFormType::nameFor($onBoard), ['column' => $done], stream: true);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['On the board'], $this->titlesIn($project, 'next'));
    }

    public function test_a_move_into_the_backlog_is_not_a_choice(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-move-into@example.com');
        $project = $this->project($em, $owner);
        $mover = $this->card($em, $project, 'Mover');
        $backlog = (string) $this->column($project, 'backlog')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->cardUrl($mover, 'move'), MoveBacklogCardFormType::nameFor($mover), ['column' => $backlog]);

        self::assertResponseRedirects();
        self::assertSame(['Mover'], $this->titlesIn($project, 'backlog'));
        self::assertNotEmpty($this->flashErrors($client));
    }

    public function test_a_bulk_move_moves_every_ticked_card(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-bulk@example.com');
        $project = $this->project($em, $owner);
        $first = $this->card($em, $project, 'First', 'backlog', 0);
        $this->card($em, $project, 'Stays', 'backlog', 1);
        $third = $this->card($em, $project, 'Third', 'backlog', 2);
        $next = (string) $this->column($project, 'next')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->bulkUrl($project), BulkMoveBacklogCardsFormType::NAME, ['ids' => [(string) $third->id, (string) $first->id], 'column' => $next], stream: true);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('target="backlog-row-'.$first->id.'"', $body);
        self::assertStringContainsString('target="backlog-row-'.$third->id.'"', $body);
        self::assertStringContainsString('2 cards moved to Next.', $body);
        self::assertSame(['First', 'Third'], $this->titlesIn($project, 'next'));
        self::assertSame(['Stays'], $this->titlesIn($project, 'backlog'));
    }

    public function test_a_bulk_move_refuses_a_card_of_another_project(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-bulk-foreign@example.com');
        $project = $this->project($em, $owner);
        $other = $this->project($em, $owner, 'other-app');
        $mine = $this->card($em, $project, 'Mine');
        $theirs = $this->card($em, $other, 'Theirs');
        $next = (string) $this->column($project, 'next')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->bulkUrl($project), BulkMoveBacklogCardsFormType::NAME, ['ids' => [(string) $mine->id, (string) $theirs->id], 'column' => $next], stream: true);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Mine'], $this->titlesIn($project, 'backlog'));
        self::assertSame(['Theirs'], $this->titlesIn($other, 'backlog'));
    }

    public function test_a_refused_bulk_move_rolls_back_and_redirects_with_the_reason(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-bulk-refused@example.com');
        $project = $this->project($em, $owner);
        $plain = $this->card($em, $project, 'Plain', 'backlog', 0);
        $epic = $this->typed($em, $this->card($em, $project, 'Epic', 'backlog', 1), CardType::Epic);
        $this->childOf($em, $epic, $this->card($em, $project, 'Open child', 'next'));
        $done = (string) $this->column($project, 'done')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->bulkUrl($project), BulkMoveBacklogCardsFormType::NAME, ['ids' => [(string) $plain->id, (string) $epic->id], 'column' => $done]);

        self::assertResponseRedirects('/projects/'.$project->id.'/board/backlog?page=1');
        self::assertSame(['Plain', 'Epic'], $this->titlesIn($project, 'backlog'));
        self::assertStringContainsString('#3', (string) $this->flashErrors($client)[0]);
    }

    public function test_a_bulk_move_refuses_more_than_one_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-bulk-many@example.com');
        $project = $this->project($em, $owner);
        $ids = [];
        for ($index = 0; $index < 26; ++$index) {
            $ids[] = (string) $this->card($em, $project, 'Card '.$index, 'backlog', $index)->id;
        }
        $next = (string) $this->column($project, 'next')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->bulkUrl($project), BulkMoveBacklogCardsFormType::NAME, ['ids' => $ids, 'column' => $next], stream: true);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->titlesIn($project, 'next'));
    }

    public function test_each_action_refuses_a_stranger(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-actions-owner@example.com');
        $stranger = $this->user($em, 'backlog-actions-stranger@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Private');
        $next = (string) $this->column($project, 'next')->id;
        $em->clear();

        $client->loginUser($stranger);
        $this->post($client, $this->cardUrl($card, 'rank'), RankBacklogCardFormType::nameFor($card), ['beforeCardId' => ''], stream: true);
        self::assertResponseStatusCodeSame(403);
        $this->post($client, $this->cardUrl($card, 'move'), MoveBacklogCardFormType::nameFor($card), ['column' => $next], stream: true);
        self::assertResponseStatusCodeSame(403);
        $this->post($client, $this->bulkUrl($project), BulkMoveBacklogCardsFormType::NAME, ['ids' => [(string) $card->id], 'column' => $next], stream: true);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(['Private'], $this->titlesIn($project, 'backlog'));
    }

    public function test_each_action_refuses_a_submission_without_its_token(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-actions-csrf@example.com');
        $project = $this->project($em, $owner);
        $first = $this->card($em, $project, 'First', 'backlog', 0);
        $second = $this->card($em, $project, 'Second', 'backlog', 1);
        $next = (string) $this->column($project, 'next')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->cardUrl($second, 'rank'), RankBacklogCardFormType::nameFor($second), ['beforeCardId' => (string) $first->id], stream: true, token: false);
        self::assertResponseStatusCodeSame(422);
        $this->post($client, $this->cardUrl($first, 'move'), MoveBacklogCardFormType::nameFor($first), ['column' => $next], stream: true, token: false);
        self::assertResponseStatusCodeSame(422);
        $this->post($client, $this->bulkUrl($project), BulkMoveBacklogCardsFormType::NAME, ['ids' => [(string) $first->id], 'column' => $next], stream: true, token: false);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(['First', 'Second'], $this->titlesIn($project, 'backlog'));
    }

    public function test_each_action_is_not_found_while_the_board_is_off(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->disableBoard();

        $owner = $this->user($em, 'backlog-actions-off@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Waiting');
        $next = (string) $this->column($project, 'next')->id;
        $em->clear();

        $client->loginUser($owner);
        $this->post($client, $this->cardUrl($card, 'move'), MoveBacklogCardFormType::nameFor($card), ['column' => $next], stream: true);
        self::assertResponseStatusCodeSame(404);
        $this->post($client, $this->bulkUrl($project), BulkMoveBacklogCardsFormType::NAME, ['ids' => [(string) $card->id], 'column' => $next], stream: true);
        self::assertResponseStatusCodeSame(404);
    }

    /** @param array<string, string|list<string>> $fields */
    private function post(KernelBrowser $client, string $url, string $name, array $fields, bool $stream = false, bool $token = true): void
    {
        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel, which a
        // same-origin Referer lets stand in for the signed token.
        $server = ['HTTP_REFERER' => 'http://localhost'.$url];
        if ($stream) {
            $server['HTTP_ACCEPT'] = TurboBundle::STREAM_MEDIA_TYPE;
        }
        if ($token) {
            $fields['_token'] = 'csrf-token';
        }

        $client->request(Request::METHOD_POST, $url, [$name => $fields], [], $server);
    }

    private function cardUrl(Card $card, string $action): string
    {
        return '/projects/'.$card->project->id.'/board/backlog/cards/'.$card->id.'/'.$action;
    }

    private function bulkUrl(Project $project): string
    {
        return '/projects/'.$project->id.'/board/backlog/bulk-move';
    }

    /** @return list<string> */
    private function titlesIn(Project $project, string $slug): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        /* @var list<string> */
        return $em->getConnection()->fetchFirstColumn(
            'SELECT c.title FROM board_cards c JOIN board_columns k ON k.id = c.column_id WHERE c.project_id = ? AND k.slug = ? ORDER BY c.position, c.created_at, c.id',
            [(string) $project->id, $slug],
        );
    }

    private function slugOf(Project $project, string $columnId): string
    {
        foreach (['backlog', 'next', 'in-progress', 'done'] as $slug) {
            if (Uuid::fromString($columnId)->equals($this->column($project, $slug)->id)) {
                return $slug;
            }
        }

        return '';
    }

    /** @return array<mixed> */
    private function flashErrors(KernelBrowser $client): array
    {
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return $session->getFlashBag()->peek('error');
    }
}
