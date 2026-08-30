<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 20)]
final class FirstAdminUserPanelFixture extends AdminUserPanelFixture
{
    #[\Override]
    public string $label { get => 'first'; }
}
