<?php

declare(strict_types=1);

namespace App\Module\Board\Twig;

use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** The time texts of a card state: when it started, and how long it has held. */
final class CardStateExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('card_state_time', $this->time(...)),
            new TwigFilter('card_state_duration', $this->duration(...)),
        ];
    }

    /** The clock time, with the day when it is not today, then how long ago: "14:02, 2 hours ago". */
    public function time(\DateTimeImmutable $since): string
    {
        $now = $this->clock->now()->setTimezone($since->getTimezone());
        $clock = $since->format('Y-m-d') === $now->format('Y-m-d') ? $since->format('H:i') : $since->format('M j, H:i');
        [$unit, $count] = self::span($since, $now);

        return $clock.', '.$this->translator->trans('board.card_state.ago.'.$unit, ['%count%' => $count]);
    }

    /** How long the state has held, in its largest whole unit: "2 h". */
    public function duration(\DateTimeImmutable $since): string
    {
        [$unit, $count] = self::span($since, $this->clock->now());

        return $this->translator->trans('board.card_state.duration.'.$unit, ['%count%' => $count]);
    }

    /** @return array{'minutes'|'hours'|'days', int} */
    private static function span(\DateTimeImmutable $since, \DateTimeImmutable $now): array
    {
        $minutes = intdiv(max(0, $now->getTimestamp() - $since->getTimestamp()), 60);

        return match (true) {
            $minutes < 60 => ['minutes', $minutes],
            $minutes < 1440 => ['hours', intdiv($minutes, 60)],
            default => ['days', intdiv($minutes, 1440)],
        };
    }
}
