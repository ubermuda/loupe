<?php

declare(strict_types=1);

namespace App\Module\OAuth\Grant;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * League builds its redirect matcher inside the grant, so the grant class is
 * swapped. Only the class changes: the bundle's extension has already set the
 * code and refresh token lifetimes on this definition.
 */
final class LoopbackPortAuthCodeGrantPass implements CompilerPassInterface
{
    private const string GRANT_ID = 'league.oauth2_server.grant.auth_code';

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(self::GRANT_ID)) {
            $container->getDefinition(self::GRANT_ID)->setClass(LoopbackPortAuthCodeGrant::class);
        }
    }
}
