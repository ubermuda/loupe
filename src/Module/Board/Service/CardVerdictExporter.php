<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Repository\CardVerdictDeliveryRepository;
use App\Module\Board\Repository\CardVerdictRepository;

/**
 * The verdicts a user sent from the site-review widget, in the account data export.
 *
 * The notes stay as the verdict copied them. A forge token is never part of a verdict.
 */
final readonly class CardVerdictExporter implements UserDataExporterInterface
{
    public function __construct(
        private CardVerdictRepository $cardVerdicts,
        private CardVerdictDeliveryRepository $cardVerdictDeliveries,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'card-verdicts.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $cardVerdicts = $this->cardVerdicts->findByReviewer($user);
        $cardVerdictDeliveries = $this->cardVerdictDeliveries->findForVerdicts($cardVerdicts);

        foreach ($cardVerdicts as $verdict) {
            yield [
                'id' => (string) $verdict->id,
                'cardId' => (string) $verdict->card->id,
                'project' => $verdict->card->project->name,
                'kind' => $verdict->kind->value,
                'message' => $verdict->message,
                'notes' => $verdict->notes,
                'createdAt' => $verdict->createdAt->format(\DateTimeInterface::ATOM),
                'deliveries' => array_map(
                    static fn (CardVerdictDelivery $delivery): array => [
                        'pullRequest' => PullRequestLabel::of($delivery->pullRequest),
                        'state' => $delivery->state->value,
                        'reason' => $delivery->reason,
                        'reviewUrl' => $delivery->reviewUrl,
                        'settledAt' => $delivery->settledAt?->format(\DateTimeInterface::ATOM),
                    ],
                    $cardVerdictDeliveries[(string) $verdict->id] ?? [],
                ),
            ];
        }
    }
}
