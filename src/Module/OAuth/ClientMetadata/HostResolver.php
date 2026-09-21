<?php

declare(strict_types=1);

namespace App\Module\OAuth\ClientMetadata;

interface HostResolver
{
    /** @return list<string> every A and AAAA address of the host */
    public function resolve(string $host): array;
}
