<?php

declare(strict_types=1);

namespace App\Module\Project\Twig;

use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** The first text of a Workshop tile's elapsed time. The elapsed Stimulus controller applies the same rule in the browser. */
final class WorkshopElapsedExtension extends AbstractExtension
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('workshop_elapsed', $this->elapsed(...)),
        ];
    }

    public function elapsed(\DateTimeImmutable $since): string
    {
        $minutes = max(0, intdiv($this->clock->now()->getTimestamp() - $since->getTimestamp(), 60));
        [$unit, $count] = match (true) {
            $minutes < 60 => ['minutes', $minutes],
            $minutes < 1440 => ['hours', intdiv($minutes, 60)],
            default => ['days', intdiv($minutes, 1440)],
        };

        return $this->translator->trans('project.workshop.in_motion.elapsed.'.$unit, ['%count%' => $count]);
    }
}
