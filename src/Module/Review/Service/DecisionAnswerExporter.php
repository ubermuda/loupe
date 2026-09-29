<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Review\Repository\DecisionAnswerRepository;

final readonly class DecisionAnswerExporter implements UserDataExporterInterface
{
    public function __construct(
        private DecisionAnswerRepository $decisionAnswers,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'decision_answers.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->decisionAnswers->streamByAnsweredBy($user) as $answer) {
            yield [
                'document' => $answer->document->title,
                'decisionId' => $answer->decisionId,
                'note' => $answer->note,
                'answeredAtVersion' => $answer->answeredAtVersion,
                'updatedAt' => $answer->updatedAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
