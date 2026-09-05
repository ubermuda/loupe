<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use App\Module\Review\Entity\SectionApproval;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * `action` carries the raw submitted value of the button the reviewer pressed.
 * The handler reads it as a boolean; this DTO only guards the two shapes a
 * hand-crafted POST could otherwise send through.
 */
final class SetSectionApprovalRequest
{
    public const string ACTION_APPROVE = 'approve';
    public const string ACTION_WITHDRAW = 'withdraw';

    public function __construct(
        // headingId backs a VARCHAR column and would otherwise reach the driver.
        #[Assert\Length(max: SectionApproval::MAX_HEADING_ID_LENGTH)]
        #[Assert\NotBlank]
        public ?string $headingId = null,

        #[Assert\Choice(choices: [self::ACTION_APPROVE, self::ACTION_WITHDRAW])]
        #[Assert\NotBlank]
        public ?string $action = null,

        /**
         * The version the reviewer was reading when they pressed the button. The
         * handler refuses it once a revision has moved on.
         */
        #[Assert\NotNull]
        #[Assert\Positive]
        public ?int $versionNumber = null,
    ) {
    }
}
