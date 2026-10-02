<?php

declare(strict_types=1);

namespace App\Module\Bridge\Mcp;

use App\Exception\DomainErrors;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a refused bridge command or card hold into a result row. A refusal is
 * data, so one refused run in a batch leaves the others.
 *
 * @phpstan-type Refusal array{runId: string, outcome: 'refused', code: string, message: string}
 * @phpstan-type CardRefusal array{cardId: string|null, outcome: 'refused', code: string, message: string}
 */
final readonly class BridgeCommandRefusals
{
    public const string NOT_FOUND = 'not-found';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /** @return Refusal */
    public function refused(string $runId, DomainErrors $errors): array
    {
        return ['runId' => $runId, 'outcome' => 'refused', ...$this->reasonOf($errors)];
    }

    /** @return Refusal */
    public function notFound(string $runId): array
    {
        return [
            'runId' => $runId,
            'outcome' => 'refused',
            'code' => self::NOT_FOUND,
            'message' => BridgeSubjectResolver::notFound($runId),
        ];
    }

    /** @return CardRefusal */
    public function cardRefused(string $cardId, DomainErrors $errors): array
    {
        return ['cardId' => $cardId, 'outcome' => 'refused', ...$this->reasonOf($errors)];
    }

    /** @return CardRefusal */
    public function cardNotFound(?string $cardId, ?int $number): array
    {
        return [
            'cardId' => $cardId,
            'outcome' => 'refused',
            'code' => self::NOT_FOUND,
            'message' => null === $cardId ? \sprintf('This project has no card %d.', $number) : \sprintf('Card "%s" not found or not accessible.', $cardId),
        ];
    }

    /**
     * The code is the last segment of the translation key in kebab case, so
     * bridge.command.error.card_left gives card-left.
     *
     * @return array{code: string, message: string}
     */
    private function reasonOf(DomainErrors $errors): array
    {
        $key = array_first($errors->errors);
        $segment = substr($key, (int) strrpos($key, '.') + 1);

        return [
            'code' => str_replace('_', '-', $segment),
            'message' => $this->translator->trans($key),
        ];
    }
}
