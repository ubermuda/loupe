<?php

declare(strict_types=1);

namespace App\Module\Account\Export;

use App\Module\Account\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One implementation per module; each contributes one JSON file to the
 * user-data ZIP. Tagged + collected by DataExportArchiveBuilder.
 */
#[AutoconfigureTag('app.user_data_exporter')]
interface UserDataExporterInterface
{
    /** Basename of the JSON file inside the archive, e.g. 'projects.json'. */
    public function filename(): string;

    /** @return array<array-key, mixed> JSON-serializable payload. */
    public function export(User $user): array;
}
