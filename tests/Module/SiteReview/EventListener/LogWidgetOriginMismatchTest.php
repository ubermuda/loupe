<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\EventListener;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Module\Account\Security\AuthenticatedApiTokenResolver;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\SiteReview\EventListener\LogWidgetOriginMismatch;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The listener observes and never refuses, so every case here asserts on what
 * was logged rather than on the response.
 */
final class LogWidgetOriginMismatchTest extends TestCase
{
    public function test_an_origin_outside_the_projects_domain_is_recorded(): void
    {
        $records = $this->handle('https://evil.example', 'loupe.ac');

        self::assertCount(1, $records);
        self::assertSame('site_review.widget.origin_mismatch', $records[0]['message']);
        self::assertSame('https://evil.example', $records[0]['context']['origin']);
    }

    public function test_the_projects_own_origin_is_not_recorded(): void
    {
        self::assertSame([], $this->handle('https://loupe.ac', 'loupe.ac'));
    }

    /**
     * `domain` is free text collected on the project form, so the shapes it
     * already holds must not each read as a mismatch.
     */
    public function test_domain_shapes_that_mean_the_same_host_match(): void
    {
        self::assertSame([], $this->handle('https://loupe.ac', 'https://loupe.ac'));
        self::assertSame([], $this->handle('https://loupe.ac', 'www.loupe.ac'));
        self::assertSame([], $this->handle('https://loupe.ac', 'LOUPE.AC'));
        self::assertSame([], $this->handle('https://www.loupe.ac', 'loupe.ac'));
    }

    public function test_a_request_without_an_origin_is_not_a_signal(): void
    {
        self::assertSame([], $this->handle(null, 'loupe.ac'));
    }

    public function test_a_project_without_a_domain_has_nothing_to_compare(): void
    {
        self::assertSame([], $this->handle('https://evil.example', null));
    }

    /**
     * Safe methods are not rate-limited, so without this an unlimited GET loop
     * would mint an unlimited number of warnings.
     */
    public function test_a_project_is_only_warned_about_once_in_the_window(): void
    {
        $seen = new ArrayAdapter();

        self::assertCount(1, $this->handle('https://evil.example', 'loupe.ac', seen: $seen));
        self::assertSame([], $this->handle('https://evil.example', 'loupe.ac', seen: $seen));
        self::assertSame([], $this->handle('https://other.example', 'loupe.ac', seen: $seen));
    }

    public function test_requests_outside_the_site_review_api_are_ignored(): void
    {
        self::assertSame([], $this->handle('https://evil.example', 'loupe.ac', '/projects/123'));
    }

    /**
     * @return list<array{message: string, context: array<string, mixed>}>
     */
    private function handle(?string $origin, ?string $domain, string $path = '/api/site-review/review', ?CacheItemPoolInterface $seen = null): array
    {
        $project = new Project(new User('Riley Chen', 'riley@example.com', 'x'), 'Loupe', $domain);

        $projects = $this->createStub(ProjectRepository::class);
        $projects->method('findOneByWidgetToken')->willReturn($project);

        $apiTokens = $this->createStub(ApiTokenRepository::class);
        $apiTokens->method('find')->willReturn($this->createStub(ApiToken::class));

        $securityToken = $this->createStub(TokenInterface::class);
        $securityToken->method('hasAttribute')->willReturn(true);
        $securityToken->method('getAttribute')->willReturn((string) Uuid::v7());

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($securityToken);

        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            #[\Override]
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        $listener = new LogWidgetOriginMismatch(
            new AuthenticatedProjectResolver(new AuthenticatedApiTokenResolver($tokenStorage, $apiTokens), $projects),
            $seen ?? new ArrayAdapter(),
            $logger,
        );

        $request = Request::create($path);
        if (null !== $origin) {
            $request->headers->set('Origin', $origin);
        }

        $listener(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response(),
        ));

        return $logger->records;
    }
}
