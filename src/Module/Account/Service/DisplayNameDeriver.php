<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\User;

/**
 * Builds a display name from an email address for the paths that cannot ask
 * for one: social login when the provider sends no name, and the console admin
 * command with no --full-name.
 *
 * The derivation rules are duplicated in assets/controllers/display_name_suggestion_controller.js,
 * which pre-fills the same value in the registration and install forms. Change
 * one and you must change the other.
 */
final readonly class DisplayNameDeriver
{
    public function derive(string $email): string
    {
        $localPart = explode('@', $email)[0];

        // A `+tag` suffix addresses a mailbox, it is not part of anyone's name.
        $withoutTag = explode('+', $localPart)[0];

        $spaced = trim((string) preg_replace('/\s+/u', ' ', strtr($withoutTag, ['.' => ' ', '_' => ' '])));

        $derived = '' !== $spaced ? $this->capitalize(mb_strtolower($spaced)) : $localPart;

        return mb_substr('' !== $derived ? $derived : $email, 0, User::MAX_FULL_NAME_LENGTH);
    }

    /** Capitalizes each space-separated word and each hyphen-separated part of it. */
    private function capitalize(string $value): string
    {
        $words = array_map(
            fn (string $word): string => implode('-', array_map($this->upperFirst(...), explode('-', $word))),
            explode(' ', $value),
        );

        return implode(' ', $words);
    }

    private function upperFirst(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }
}
