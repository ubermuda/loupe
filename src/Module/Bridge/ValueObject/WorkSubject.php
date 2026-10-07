<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

use Symfony\Component\Uid\Uuid;

/**
 * What a work request or a worker run is about. The type is an open code,
 * because each module that runs work names its own subject types.
 */
final readonly class WorkSubject
{
    public const string CARD = 'card';

    public const int MAX_TYPE_LENGTH = 40;

    public const string TYPE_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/D';

    public function __construct(
        public string $type,
        public Uuid $id,
    ) {
        if (1 !== preg_match(self::TYPE_PATTERN, $type)) {
            throw new \LogicException('A subject type is a code.');
        }
    }

    public static function card(Uuid $cardId): self
    {
        return new self(self::CARD, $cardId);
    }

    public function isCard(): bool
    {
        return self::CARD === $this->type;
    }
}
