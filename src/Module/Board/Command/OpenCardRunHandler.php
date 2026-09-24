<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;

final readonly class OpenCardRunHandler
{
    public const string NAME_BLANK = 'board.card.error.run_name_blank';
    public const string NAME_TOO_LONG = 'board.card.error.run_name_too_long';
    public const string RUN_CLOSED_BY_MOVE = 'board.card.error.run_closed_by_move';

    public function __construct(
        private UpdateCardHandler $updateCard,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    public function __invoke(OpenCardRunCommand $command): OpenCardRunView
    {
        $name = trim($command->name);
        if ('' === $name) {
            throw new DomainErrors(['name' => self::NAME_BLANK]);
        }
        if (mb_strlen($name) > WorkerRun::MAX_RULE_NAME_LENGTH) {
            throw new DomainErrors(['name' => self::NAME_TOO_LONG]);
        }

        $card = ($this->updateCard)(new UpdateCardCommand(
            card: $command->card,
            actor: $command->actor,
            column: $command->column,
            openInteractiveRun: new OpenInteractiveRun($command->sessionId, $name),
        ));

        // A move that commits between the open and this read closes the run.
        $run = $this->workerRuns->findOpenInteractive($card->project, $card->id ?? throw new \LogicException('A persisted card has an id.'), $command->sessionId)
            ?? throw new DomainErrors(['run' => self::RUN_CLOSED_BY_MOVE]);

        return new OpenCardRunView($card, $run);
    }
}
