<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use Symfony\Component\Validator\Constraints as Assert;

class CreateCardRequest
{
    public function __construct(
        #[Assert\Length(max: Card::MAX_TITLE_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $title = null,
        public ?string $body = null,

        /** Nullable so a submit that omits the select fails validation rather than throwing out of the property mapper. */
        #[Assert\NotNull]
        public ?CardType $type = CardType::Feature,

        /** The controller passes the board's default column. */
        #[Assert\NotNull]
        public ?BoardColumn $column = null,
        /** One URL per line, as typed. The list is replaced whole on every save. */
        public ?string $pullRequestUrls = null,
    ) {
    }

    /**
     * The textarea's lines as a list, blank ones dropped.
     *
     * @return list<string>
     */
    public static function toUrlList(?string $raw): array
    {
        $lines = preg_split('/\R/', $raw ?? '');
        if (false === $lines) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), $lines),
            static fn (string $line): bool => '' !== $line,
        ));
    }
}
