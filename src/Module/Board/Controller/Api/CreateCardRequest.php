<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A card raised from the site-review widget.
 *
 * Deliberately narrower than CreateCardCommand. It takes no status, because a
 * page visitor has no business filing straight into a column, and no pull
 * request URLs, because a visitor knows nothing that belongs in them and a
 * free-text URL field on a public endpoint is worth avoiding.
 */
final class CreateCardRequest
{
    public function __construct(
        #[Assert\Length(max: Card::MAX_TITLE_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $title = null,

        #[Assert\Length(max: 10000)]
        public string $body = '',

        #[Assert\NotNull]
        public ?CardType $type = CardType::Feature,

        #[Assert\NotNull]
        public ?CardPriority $priority = CardPriority::Medium,
    ) {
    }
}
