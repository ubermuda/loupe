<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Entity\WorkerRun;

final readonly class OpenCardRunHandler
{
    public const string NAME_BLANK = 'board.card.error.run_name_blank';
    public const string NAME_TOO_LONG = 'board.card.error.run_name_too_long';

    public function __construct(
        private UpdateCardHandler $updateCard,
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

        $updated = ($this->updateCard)(new UpdateCardCommand(
            card: $command->card,
            actor: $command->actor,
            column: $command->column,
            openInteractiveRun: new OpenInteractiveRun($command->sessionId, $name),
        ));

        return new OpenCardRunView(
            $updated->card,
            $updated->openedRun ?? throw new \LogicException('An update that carries a run to open returns it.'),
        );
    }
}
