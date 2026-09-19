<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The MCP endpoint as an OAuth protected resource (RFC 8707, RFC 9728). Its
 * URI is the one a user enters in an MCP client, with no trailing slash, and
 * every access token for the mcp scope carries it as its audience.
 */
final readonly class McpResource
{
    /** @var non-empty-string */
    public string $uri;
    public string $metadataUrl;

    public function __construct(
        #[Autowire(param: 'app.url')]
        string $instanceUrl,
    ) {
        $base = rtrim($instanceUrl, '/');
        $this->uri = $base.'/mcp';
        $this->metadataUrl = $base.'/.well-known/oauth-protected-resource/mcp';
    }

    /**
     * No resource is accepted, because the scope alone sets the audience. One
     * resource must be this one, and only for the mcp scope when it is known.
     *
     * @param list<string> $resources
     */
    public function accepts(array $resources, ?bool $mcpScope): bool
    {
        if ([] === $resources) {
            return true;
        }

        return 1 === \count($resources) && false !== $mcpScope && $this->matches($resources[0]);
    }

    /** The WWW-Authenticate value (RFC 6750, RFC 9728) of a refused MCP request. */
    public function challenge(?string $error): string
    {
        return 'Bearer '.(null === $error ? '' : 'error="'.$error.'", ').'resource_metadata="'.$this->metadataUrl.'", scope="mcp"';
    }

    /** Scheme and host compare without case, and a default port counts as absent. */
    public function matches(string $resource): bool
    {
        if (1 !== preg_match('/^[\x21-\x7e]+$/', $resource) || str_contains($resource, '?') || str_contains($resource, '#')) {
            return false;
        }

        return null !== self::normalise($resource) && self::normalise($resource) === self::normalise($this->uri);
    }

    private static function normalise(string $uri): ?string
    {
        $parts = parse_url($uri);
        if (false === $parts || !isset($parts['scheme'], $parts['host'], $parts['path']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        if (('https' === $scheme && 443 === $port) || ('http' === $scheme && 80 === $port)) {
            $port = null;
        }

        return $scheme.'://'.strtolower($parts['host']).(null === $port ? '' : ':'.$port).$parts['path'];
    }
}
