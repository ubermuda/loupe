<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Service\GitHubPullRequestUrlParser;
use App\Module\Board\Service\PullRequestUrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitHubPullRequestUrlParserTest extends TestCase
{
    private GitHubPullRequestUrlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GitHubPullRequestUrlParser();
    }

    public function test_a_github_pull_request_url_is_read(): void
    {
        $url = 'https://github.com/ubermuda/loupe/pull/362';

        self::assertTrue($this->parser->supports($url));

        $ref = $this->parser->parse($url);

        self::assertSame(Forge::GitHub, $ref->forge);
        self::assertSame('ubermuda/loupe', $ref->repository);
        self::assertSame(362, $ref->number);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedUrls(): iterable
    {
        yield 'another forge' => ['https://gitlab.com/group/project/-/merge_requests/7'];
        yield 'self-hosted forge' => ['https://code.example.org/team/app/pulls/12'];
        yield 'github issue' => ['https://github.com/ubermuda/loupe/issues/362'];
        yield 'github repository root' => ['https://github.com/ubermuda/loupe'];
        yield 'not a url' => ['loupe pull 362'];
    }

    #[DataProvider('unsupportedUrls')]
    public function test_an_unsupported_url_is_not_claimed(string $url): void
    {
        self::assertFalse($this->parser->supports($url));
    }

    public function test_an_unknown_host_falls_back_to_other_rather_than_being_rejected(): void
    {
        $resolver = new PullRequestUrlResolver([$this->parser]);

        $ref = $resolver->resolve('https://code.example.org/team/app/pulls/12');

        self::assertSame(Forge::Other, $ref->forge);
        self::assertNull($ref->repository);
        self::assertNull($ref->number);
    }

    public function test_a_recognised_url_still_wins_when_a_parser_supports_it(): void
    {
        $resolver = new PullRequestUrlResolver([$this->parser]);

        $ref = $resolver->resolve('https://github.com/ubermuda/loupe/pull/1');

        self::assertSame(Forge::GitHub, $ref->forge);
    }

    /** @return iterable<string, array{string}> */
    public static function unstorableReferences(): iterable
    {
        $owner = str_repeat('a', CardPullRequest::MAX_REPOSITORY_LENGTH);

        yield 'repository past its column' => ['https://github.com/'.$owner.'/loupe/pull/1'];
        yield 'number past a 32-bit integer' => ['https://github.com/ubermuda/loupe/pull/2147483648'];
        yield 'number past a 64-bit integer' => ['https://github.com/ubermuda/loupe/pull/99999999999999999999'];
    }

    /**
     * A URL short enough to store can still carry parts that no column holds.
     * The link stays, and only the parts that do not fit are dropped.
     */
    #[DataProvider('unstorableReferences')]
    public function test_a_reference_no_column_can_hold_is_dropped_and_the_link_kept(string $url): void
    {
        self::assertLessThanOrEqual(CardPullRequest::MAX_URL_LENGTH, mb_strlen($url));

        $ref = new PullRequestUrlResolver([$this->parser])->resolve($url);

        self::assertSame(Forge::GitHub, $ref->forge);
        self::assertNull($ref->repository);
        self::assertNull($ref->number);
    }
}
