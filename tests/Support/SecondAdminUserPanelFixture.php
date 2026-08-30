<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 10)]
final class SecondAdminUserPanelFixture extends AdminUserPanelFixture
{
    #[\Override]
    public string $label { get => 'second'; }
}
