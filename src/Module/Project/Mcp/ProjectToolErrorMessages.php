<?php

declare(strict_types=1);

namespace App\Module\Project\Mcp;

use App\Exception\DomainErrors;
use App\Module\Project\Service\SiteOrigins;
use Mcp\Exception\ToolCallException;

/**
 * Renders a handler's DomainErrors as the message an agent reads. An unmapped
 * key falls back to a generic message rather than leaking the key itself.
 */
final readonly class ProjectToolErrorMessages
{
    public const string UNMAPPED = 'The request was rejected. The error has been logged.';

    public function forAgent(DomainErrors $errors): ToolCallException
    {
        $lines = [];
        foreach ($errors->errors as $argument => $key) {
            $lines[] = \sprintf('%s: %s', $argument, self::sentence($key));
        }

        return new ToolCallException(implode("\n", $lines), previous: $errors);
    }

    private static function sentence(string $key): string
    {
        return match ($key) {
            'project.error.name_taken' => 'Another project of this owner already has this name. Choose another name.',
            'project.error.slug_empty' => 'A project name needs at least one letter or digit, because the slug comes from the name.',
            'project.error.slug_taken' => 'Another project of this owner has a name that gives the same slug. Choose another name.',
            'project.allowed_origins.error.invalid' => 'Each entry must be a site origin with no path, such as https://staging.example.com or https://*.example.com. A * stands for one label, and it cannot cover a whole registry such as *.com. Plain http works on localhost only.',
            'project.allowed_origins.error.too_many' => \sprintf('A project allows %d origins at most.', SiteOrigins::MAX),
            default => self::UNMAPPED,
        };
    }
}
