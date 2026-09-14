<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/**
 * Where a response form sends the owner back to: the route of the redirect
 * after a saved response, and the controller of the forward that re-renders
 * the page after a refused one.
 */
final readonly class InboxReturnTarget
{
    public const string PAGE_QUERY = 'returnTo';
    public const string ID_QUERY = 'returnId';
    public const string VERSION_QUERY = 'returnVersion';

    /**
     * @param array<string, int|string> $routeParameters
     * @param array<string, mixed>      $attributes      what the forward hands the controller
     * @param array<string, int>        $query           what the forward keeps of the query string
     */
    public function __construct(
        public string $route,
        public array $routeParameters,
        public string $controller,
        public array $attributes,
        public array $query,
    ) {
    }
}
