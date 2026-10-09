<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Twig;

use App\Module\Board\Service\CardBadge;
use App\Module\Board\Service\CardState;
use App\Module\Board\Service\CardStateCode;
use App\Module\Board\Service\CardStateReason;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\CardRunWarning;
use App\Tests\Module\Board\Controller\BoardScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class CardTileStateMarkTest extends KernelTestCase
{
    use BoardScenario;

    /** @return iterable<string, array{CardStateReason, string, string, string}> */
    public static function kinds(): iterable
    {
        yield 'stuck' => [new CardStateReason(CardStateCode::ReadyNotMerged, ['%number%' => 12]), 'stuck', 'Stuck', 'Pull request #12 is ready to merge, and no merge rule matches it.'];
        yield 'needs you' => [new CardStateReason(CardStateCode::OpenQuestion, ['%number%' => 3, '%title%' => 'Which option?']), 'needs-you', 'Needs you', 'Question #3 waits for your answer: Which option?'];
        yield 'working' => [new CardStateReason(CardStateCode::RunOpen), 'working', 'Working', 'A worker runs on this card.'];
        yield 'waiting' => [new CardStateReason(CardStateCode::HeldByBlocker, ['%number%' => 7, '%title%' => 'The blocker']), 'waiting', 'Waiting', 'Card #7 blocks this card: The blocker'];
    }

    #[DataProvider('kinds')]
    public function test_a_tile_draws_one_mark_with_its_kind_name_reason_and_link(CardStateReason $reason, string $kind, string $label, string $sentence): void
    {
        $tile = $this->tile(CardState::of([$reason]));

        $mark = $tile->filter('.lp-board-card__identity .lp-state-mark');
        self::assertCount(1, $tile->filter('.lp-state-mark'));
        self::assertCount(1, $mark);
        self::assertStringContainsString('lp-state-mark--'.$kind, (string) $mark->attr('class'));
        self::assertSame($label, $mark->filter('[role="img"]')->attr('aria-label'));
        self::assertSame('0', $mark->filter('[role="img"]')->attr('tabindex'));

        $tooltip = $mark->filter('.lp-tooltip--interactive');
        self::assertSame($mark->filter('[role="img"]')->attr('aria-describedby'), $tooltip->attr('id'));
        self::assertStringContainsString($sentence, $tooltip->text());
        self::assertSame($label, trim($tooltip->filter('.lp-tooltip__title')->text()));
        $link = $tooltip->filter('a');
        self::assertSame('Open card', trim($link->text()));
        self::assertSame('card-drawer-frame', $link->attr('data-turbo-frame'));
        self::assertSame('click->card-drawer#prepare', $link->attr('data-action'));
        self::assertSame('false', $link->attr('draggable'));
        self::assertStringEndsWith('/board/cards/'.$tile->attr('data-card-id'), (string) $link->attr('href'));
    }

    public function test_the_since_line_shows_only_when_the_reason_has_a_time(): void
    {
        $withTime = $this->tile(CardState::of([new CardStateReason(CardStateCode::RunOpen, [], new \DateTimeImmutable('2026-10-02 09:45:00'))]));
        self::assertStringContainsString('Since 2026-10-02 09:45', $withTime->filter('.lp-tooltip')->text());

        $without = $this->tile(CardState::of([new CardStateReason(CardStateCode::RunOpen)]));
        self::assertStringNotContainsString('Since', $without->filter('.lp-tooltip')->text());
    }

    public function test_a_card_without_a_state_shows_no_mark(): void
    {
        self::assertCount(0, $this->tile(null)->filter('.lp-state-mark'));
    }

    public function test_the_tile_drops_the_problem_chips_and_the_run_warning_and_keeps_the_unmanaged_chip(): void
    {
        $state = CardState::of([new CardStateReason(CardStateCode::ChecksFailed, ['%number%' => 5])]);
        $warning = new CardRunWarning('11111111-1111-4111-8111-111111111111', WorkerRunState::GaveUp, 'It gave up.', new \DateTimeImmutable('2026-10-02 09:00:00'));

        $tile = $this->tile($state, badges: [CardBadge::ChecksFailed, CardBadge::Conflict, CardBadge::Paused, CardBadge::Unmanaged], runWarning: $warning);

        self::assertCount(1, $tile->filter('.lp-state-mark'));
        self::assertCount(0, $tile->filter('.lp-board-card__warning, [data-card-run-warning]'));
        self::assertSame('unmanaged', $tile->filter('[data-card-badges]')->attr('data-card-badges'));
        self::assertSame(['Unmanaged'], $tile->filter('.lp-board-card__badges .lp-status-chip')->each(static fn (Crawler $chip): string => trim($chip->text())));
        self::assertStringNotContainsString('Checks failed', $tile->text());
    }

    public function test_a_tile_with_only_problem_badges_shows_no_badge_row(): void
    {
        $tile = $this->tile(null, badges: [CardBadge::Paused]);

        self::assertCount(0, $tile->filter('[data-card-badges]'));
    }

    /** @param list<CardBadge> $badges */
    private function tile(?CardState $state, array $badges = [], ?CardRunWarning $runWarning = null): Crawler
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'tile-'.bin2hex(random_bytes(4)).'@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'The card', 'in-progress');

        $html = static::getContainer()->get(Environment::class)->render('@Board/_card.html.twig', [
            'card' => $card,
            'pendingComments' => 0,
            'documentCount' => 0,
            'state' => $state,
            'badges' => $badges,
            'runWarning' => $runWarning,
        ]);

        return new Crawler($html)->filter('article.lp-board-card');
    }
}
