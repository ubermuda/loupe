<?php

namespace App\Exception;

final class DomainErrors extends \RuntimeException
{
    /** @param non-empty-array<string, string> $errors field name => translation key */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct('Domain errors detected.');
    }
}
