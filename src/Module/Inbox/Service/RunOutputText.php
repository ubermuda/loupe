<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

/** Cleans the output of a worker run for a person. Old runs and other harnesses still write the stage protocol. */
final class RunOutputText
{
    public static function clean(string $output): string
    {
        $text = trim($output);
        $text = (string) preg_replace('/\A\s*STAGE RESULT:\s*(?:blocked:\s*)?/i', '', $text);
        $text = (string) preg_replace('/\s*\[reason:\s*[a-z0-9-]+\]\s*\z/i', '', $text);

        return trim($text);
    }
}
