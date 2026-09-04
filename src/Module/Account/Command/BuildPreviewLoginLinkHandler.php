<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Worktrees share APP_SECRET and all seed the same accounts, so the signature
 * covers the whole absolute URL: the host is what binds a link to one of them.
 */
#[When('dev')]
final readonly class BuildPreviewLoginLinkHandler
{
    public function __construct(
        private UserRepository $users,
        private UriSigner $uriSigner,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(BuildPreviewLoginLinkCommand $command): BuildPreviewLoginLinkView
    {
        if (null === $this->users->findOneByEmail($command->email)) {
            throw new DomainErrors(['email' => 'account.preview_login.error.unknown_email']);
        }

        // Browsers normalise a backslash to a slash, so /\evil.test is network-path
        // relative once the redirect reaches them.
        if (1 !== preg_match('#^/(?![/\\\\])#', $command->path)) {
            throw new DomainErrors(['path' => 'account.preview_login.error.path_not_local']);
        }

        if ($command->lifetimeSeconds < 1) {
            throw new DomainErrors(['lifetimeSeconds' => 'account.preview_login.error.lifetime_not_positive']);
        }

        $expiresAt = new \DateTimeImmutable()->modify('+'.$command->lifetimeSeconds.' seconds');

        $url = $this->urlGenerator->generate(
            'dev_preview_login',
            ['email' => $command->email, 'to' => $command->path],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return new BuildPreviewLoginLinkView($this->uriSigner->sign($url, $expiresAt), $expiresAt);
    }
}
