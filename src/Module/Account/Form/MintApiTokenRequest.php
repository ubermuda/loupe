<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use Symfony\Component\Validator\Constraints as Assert;

class MintApiTokenRequest
{
    public function __construct(
        #[Assert\Length(max: ApiToken::MAX_LABEL_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $label = null,

        /** Nullable so a submit that omits the select fails validation rather than throwing out of the property mapper. */
        #[Assert\NotNull]
        public ?ApiTokenScope $scope = ApiTokenScope::SiteReview,
    ) {
    }
}
