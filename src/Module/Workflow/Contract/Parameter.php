<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

final readonly class Parameter
{
    /**
     * @param ?list<string> $choices     the values a string parameter accepts, or null for any
     * @param ?string       $fixed       the one value a string parameter accepts, which its error names
     * @param ?int          $min         the least value an int parameter accepts, or null for any
     * @param ?string       $pattern     the regular expression a string parameter matches, or null for any
     * @param ?string       $patternHint what the error says about the pattern
     * @param ?string       $needs       the name of the parameter that this one cannot be given without
     * @param bool          $appOnly     true when only the rules the app adds may carry the parameter
     */
    public function __construct(
        public string $name,
        public ParameterType $type,
        public bool $required = true,
        public ?array $choices = null,
        public ?string $fixed = null,
        public ?int $min = null,
        public ?string $pattern = null,
        public ?string $patternHint = null,
        public ?string $needs = null,
        public bool $appOnly = false,
    ) {
    }
}
