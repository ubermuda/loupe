<?php

declare(strict_types=1);

namespace App\Module\Account\Install;

use App\Module\Account\Command\SeedInstallFlagsCommand;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module's opinion about how its own feature flags should be seeded on a
 * fresh install, mirroring UserDataExporterInterface: the module owning the
 * behaviour owns the default, and Account only collects.
 *
 * Without it the seeder is a central list naming other modules' flags — as bare
 * strings, which no namespace rule can see, so the coupling is invisible to
 * `just arkitect` as well as to the reader.
 *
 * The command carries the wizard's answers, because some flags are set from the
 * install form rather than fixed.
 */
#[AutoconfigureTag('app.install_flag_defaults')]
interface InstallFlagDefaultsInterface
{
    /** @return iterable<InstallFlagDefault> */
    public function defaults(SeedInstallFlagsCommand $command): iterable;
}
