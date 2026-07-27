<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gates the install wizard behind a deploy-time INSTALL_TOKEN, on top of
 * InstallationState::isOpen(). An unset/empty token preserves the previous
 * "first visitor wins" behaviour dev, test, and worktree provisioning depend
 * on; a set token must be supplied once (via the `token` query parameter) and
 * is then remembered for the rest of the session so later wizard steps don't
 * need to repeat it. A missing/wrong token 404s — the same response as an
 * already-closed wizard, so the two states aren't distinguishable.
 */
final readonly class InstallAccessGuard
{
    private const string SESSION_TOKEN_VERIFIED = 'install_token_verified';

    public function __construct(
        private InstallationState $installationState,

        #[Autowire(env: 'INSTALL_TOKEN')]
        private string $installToken,

        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function ensureAccessible(Request $request): void
    {
        if (!$this->installationState->isOpen()) {
            throw new NotFoundHttpException();
        }

        if ('' === $this->installToken) {
            // Unconfigured fails CLOSED in production: a forgotten env var must
            // not leave an admin-minting endpoint publicly reachable. Elsewhere
            // it stays open, because dev, the test suite and every
            // `just worktree-up` run the wizard unattended.
            if ('prod' === $this->environment) {
                throw new NotFoundHttpException();
            }

            return;
        }

        $session = $request->getSession();
        if (true === $session->get(self::SESSION_TOKEN_VERIFIED)) {
            return;
        }

        // A non-string `token` (e.g. ?token[]=x) must 404 like any other wrong
        // token, not 500 — getString() throws on that shape.
        $submitted = $request->query->all()['token'] ?? null;
        if (!is_string($submitted) || !hash_equals($this->installToken, $submitted)) {
            throw new NotFoundHttpException();
        }

        $session->set(self::SESSION_TOKEN_VERIFIED, true);
    }
}
