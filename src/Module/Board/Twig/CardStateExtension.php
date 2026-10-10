<?php

declare(strict_types=1);

namespace App\Module\Board\Twig;

use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * The time texts of a card state: when it started, and how long it has held. The age sits in a
 * span that the state-age controller keeps current while the page stays open.
 */
final class CardStateExtension extends AbstractExtension
{
    private const string AGE = "\u{0}age\u{0}";

    private const array FORMS = ['now', 'minute', 'minutes', 'hour', 'hours', 'day', 'days'];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('card_state_text', $this->text(...)),
            new TwigFunction('card_state_age_texts', $this->ageTexts(...)),
        ];
    }

    /**
     * Translates a key whose %clock% is the start time, such as "14:02" or "Oct 7, 14:02", and whose
     * %age% is how long ago it was, in the ago mode, or how long the state has held, in the duration mode.
     *
     * @param array<string, string|int> $params
     * @param 'ago'|'duration'          $mode
     */
    public function text(string $key, \DateTimeImmutable $since, string $mode = 'ago', array $params = []): Markup
    {
        $now = $this->clock->now();
        $local = $now->setTimezone($since->getTimezone());
        $clock = $since->format('Y-m-d') === $local->format('Y-m-d') ? $since->format('H:i') : $since->format('M j, H:i');
        [$form, $count] = self::form($since, $now);
        $age = \sprintf(
            '<span data-controller="state-age" data-state-age-since-value="%d" data-state-age-now-value="%d" data-state-age-mode-value="%s">%s</span>',
            $since->getTimestamp(),
            $now->getTimestamp(),
            $mode,
            htmlspecialchars($this->translator->trans('board.card_state.'.$mode.'.'.$form, ['%count%' => $count])),
        );
        $text = htmlspecialchars($this->translator->trans($key, [...$params, '%clock%' => $clock, '%age%' => self::AGE]));

        return new Markup(str_replace(htmlspecialchars(self::AGE), $age, $text), 'UTF-8');
    }

    /** @return array{ago: array<string, string>, duration: array<string, string>} each form with a literal %count% */
    public function ageTexts(): array
    {
        $texts = ['ago' => [], 'duration' => []];
        foreach (array_keys($texts) as $mode) {
            foreach (self::FORMS as $form) {
                $texts[$mode][$form] = $this->translator->trans('board.card_state.'.$mode.'.'.$form);
            }
        }

        return $texts;
    }

    /** @return array{string, int} the form of the age and its count, in the largest whole unit */
    private static function form(\DateTimeImmutable $since, \DateTimeImmutable $now): array
    {
        $minutes = intdiv(max(0, $now->getTimestamp() - $since->getTimestamp()), 60);
        [$unit, $count] = match (true) {
            $minutes < 60 => ['minute', $minutes],
            $minutes < 1440 => ['hour', intdiv($minutes, 60)],
            default => ['day', intdiv($minutes, 1440)],
        };

        return [match ($count) {
            0 => 'now',
            1 => $unit,
            default => $unit.'s',
        }, $count];
    }
}
