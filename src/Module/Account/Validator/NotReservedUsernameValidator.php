<?php

declare(strict_types=1);

namespace App\Module\Account\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NotReservedUsernameValidator extends ConstraintValidator
{
    private const array RESERVED = [
        'admin',
        'administrator',
        'root',
        'superuser',
        'support',
        'help',
        'api',
        'www',
        'mail',
        'email',
        'noreply',
        'no-reply',
        'system',
        'moderator',
        'mod',
        'staff',
        'team',
        'security',
        'abuse',
        'postmaster',
        'webmaster',
        'hostmaster',
    ];

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NotReservedUsername) {
            throw new UnexpectedTypeException($constraint, NotReservedUsername::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (in_array(strtolower((string) $value), self::RESERVED, true)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', (string) $value)
                ->addViolation();
        }
    }
}
