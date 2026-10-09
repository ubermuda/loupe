<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Service\CardState;
use App\Module\Board\Service\CardStateCode;
use App\Module\Board\Service\CardStateKind;
use App\Module\Board\Service\CardStateReason;
use PHPUnit\Framework\TestCase;

final class CardStateTest extends TestCase
{
    public function test_every_code_belongs_to_the_kind_whose_case_order_matches_the_precedence(): void
    {
        $kinds = array_map(static fn (CardStateCode $code): CardStateKind => $code->kind(), CardStateCode::cases());

        $ranks = array_map(static fn (CardStateKind $kind): int => (int) array_search($kind, CardStateKind::cases(), true), $kinds);
        $sorted = $ranks;
        sort($sorted);
        self::assertSame($sorted, $ranks);
        self::assertCount(\count(CardStateKind::cases()), array_unique($ranks));
    }

    public function test_the_first_reason_by_precedence_wins_whatever_the_input_order(): void
    {
        $state = CardState::of([
            new CardStateReason(CardStateCode::HeldByBlocker),
            new CardStateReason(CardStateCode::RunOpen),
            new CardStateReason(CardStateCode::OpenQuestion),
            new CardStateReason(CardStateCode::Conflicting),
        ]);

        self::assertSame(CardStateKind::Stuck, $state->kind);
        self::assertSame(CardStateCode::Conflicting, $state->reason->code);
        self::assertSame(
            [CardStateCode::OpenQuestion, CardStateCode::RunOpen, CardStateCode::HeldByBlocker],
            array_map(static fn (CardStateReason $reason): CardStateCode => $reason->code, $state->others),
        );
    }

    public function test_within_one_kind_the_case_order_decides(): void
    {
        $state = CardState::of([
            new CardStateReason(CardStateCode::ReadyNotMerged),
            new CardStateReason(CardStateCode::Paused),
            new CardStateReason(CardStateCode::ChecksFailed),
        ]);

        self::assertSame(CardStateCode::Paused, $state->reason->code);
        self::assertSame(CardStateCode::ChecksFailed, $state->others[0]->code);
    }

    public function test_the_digest_names_the_winner_its_time_and_the_other_codes(): void
    {
        $state = CardState::of([
            new CardStateReason(CardStateCode::Paused, [], new \DateTimeImmutable('@1000')),
            new CardStateReason(CardStateCode::RunOpen),
        ]);

        self::assertSame('paused|1000|run-open', $state->digest());
    }

    public function test_a_reason_names_its_translation_key_after_its_code(): void
    {
        self::assertSame('board.card_state.reason.waits_for_approval', new CardStateReason(CardStateCode::WaitsForApproval)->translationKey());
        self::assertSame('board.card_state.needs_you', CardStateKind::NeedsYou->translationKey());
    }
}
