<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Entity;

use App\Module\Board\Entity\CardSource;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Workflow\Contract\Actor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CardSourceTest extends TestCase
{
    /** @return iterable<string, array{Actor, CardSourceKind}> */
    public static function reporters(): iterable
    {
        yield 'human' => [Actor::Human, CardSourceKind::Person];
        yield 'agent' => [Actor::Agent, CardSourceKind::Agent];
        yield 'reviewer' => [Actor::Reviewer, CardSourceKind::Widget];
        yield 'system' => [Actor::System, CardSourceKind::Loupe];
    }

    #[DataProvider('reporters')]
    public function test_a_reporter_maps_to_its_source_kind(Actor $reporter, CardSourceKind $kind): void
    {
        $source = CardSource::fromReporter($reporter);

        self::assertSame($kind, $source->kind);
        self::assertNull($source->runId);
        self::assertNull($source->runCardId);
    }

    public function test_a_run_source_names_the_run_and_its_card(): void
    {
        $run = Uuid::v4();
        $card = Uuid::v4();

        $source = CardSource::run($run, $card);

        self::assertSame(CardSourceKind::Run, $source->kind);
        self::assertSame($run, $source->runId);
        self::assertSame($card, $source->runCardId);
    }
}
