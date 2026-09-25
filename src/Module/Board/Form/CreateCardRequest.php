<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Command\CardLinkInput;
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

        /** @var list<CardLinkRowRequest> replaced whole on every save, like the URLs */
        #[Assert\Valid]
        public array $relatedCards = [],
        /** An epic of the project, which the choice list of the field guarantees. */
        public ?Card $parent = null,
    ) {
    }

    /**
     * The link rows as the handlers take them. Call it after validation only.
     *
     * @return list<CardLinkInput>
     */
    public function linkInputs(): array
    {
        return array_values(array_map(
            static fn (CardLinkRowRequest $row): CardLinkInput => new CardLinkInput(
                (string) ($row->card ?? throw new \LogicException('card required after validation'))->id,
                $row->kind ?? throw new \LogicException('kind required after validation'),
            ),
            $this->relatedCards,
        ));
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
