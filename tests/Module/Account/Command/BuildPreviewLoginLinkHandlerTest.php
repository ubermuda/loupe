<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Command\BuildPreviewLoginLinkCommand;
use App\Module\Account\Command\BuildPreviewLoginLinkHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class BuildPreviewLoginLinkHandlerTest extends TestCase
{
    private const string HOST = 'https://feature-x.loupe.dev.localhost';

    private UriSigner $uriSigner;

    #[\Override]
    protected function setUp(): void
    {
        $this->uriSigner = new UriSigner('a-shared-secret');
    }

    public function test_the_signed_link_verifies_on_the_host_it_was_minted_for(): void
    {
        $view = ($this->handler())(new BuildPreviewLoginLinkCommand('dev@loupe.test', '/projects'));

        self::assertStringStartsWith(self::HOST.'/dev/preview-login?', $view->url);
        self::assertTrue($this->uriSigner->check($view->url));
        self::assertStringNotContainsString('_expiration', $view->url);
    }

    /** Worktrees share APP_SECRET and all seed dev@loupe.test, so only the host binds a link. */
    public function test_the_link_fails_once_the_host_is_rewritten_to_a_sibling_worktree(): void
    {
        $view = ($this->handler())(new BuildPreviewLoginLinkCommand('dev@loupe.test', '/projects'));

        $sibling = str_replace('feature-x.', 'feature-y.', $view->url);

        self::assertNotSame($view->url, $sibling);
        self::assertFalse($this->uriSigner->check($sibling));
    }

    public function test_the_link_fails_once_the_target_path_is_rewritten(): void
    {
        $view = ($this->handler())(new BuildPreviewLoginLinkCommand('dev@loupe.test', '/projects'));

        self::assertFalse($this->uriSigner->check(str_replace('%2Fprojects', '%2Fadmin', $view->url)));
    }

    public function test_an_unknown_email_is_rejected(): void
    {
        $this->expectException(DomainErrors::class);

        ($this->handler(known: false))(new BuildPreviewLoginLinkCommand('nobody@loupe.test'));
    }

    /** @return iterable<string, array{string}> */
    public static function non_local_paths(): iterable
    {
        yield 'protocol relative' => ['//evil.test/steal'];
        yield 'backslash network path' => ['/\\evil.test/steal'];
        yield 'double backslash' => ['/\\\\evil.test/steal'];
        yield 'absolute url' => ['https://evil.test/steal'];
        yield 'relative' => ['projects'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('non_local_paths')]
    public function test_a_path_that_leaves_this_instance_is_rejected(string $path): void
    {
        $this->expectException(DomainErrors::class);

        ($this->handler())(new BuildPreviewLoginLinkCommand('dev@loupe.test', $path));
    }

    private function handler(bool $known = true): BuildPreviewLoginLinkHandler
    {
        /** @var UserRepository&Stub $users */
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneByEmail')->willReturn(
            $known ? new User(fullName: 'Dev', email: 'dev@loupe.test', password: 'x') : null,
        );

        /** @var UrlGeneratorInterface&Stub $urls */
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $name, array $parameters = []): string => self::HOST.'/dev/preview-login?'.http_build_query($parameters),
        );

        return new BuildPreviewLoginLinkHandler($users, $this->uriSigner, $urls);
    }
}
