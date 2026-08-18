<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Install\InstallFlagDefault;
use App\Module\Account\Install\InstallFlagDefaultsInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final readonly class SeedInstallFlagsHandler
{
    /** @param iterable<InstallFlagDefaultsInterface> $flagDefaults */
    public function __construct(
        private FeatureFlagRepository $featureFlags,
        private EntityManagerInterface $em,

        #[AutowireIterator('app.install_flag_defaults')]
        private iterable $flagDefaults,
    ) {
    }

    public function __invoke(SeedInstallFlagsCommand $command): void
    {
        $existing = $this->featureFlags->findAllIndexed();

        $created = 0;
        foreach ($this->flagDefaults as $contributor) {
            foreach ($contributor->defaults($command) as $default) {
                if (isset($existing[$default->name])) {
                    continue;
                }
                $this->em->persist($this->build($default));
                ++$created;
            }
        }

        if (0 === $created) {
            return;
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent seeding request won the race on the unique flag name;
            // the flags exist, which is all this handler guarantees.
        }
    }

    private function build(InstallFlagDefault $default): FeatureFlag
    {
        $flag = new FeatureFlag(name: $default->name, type: $default->type, value: $default->value);
        $flag->options = $default->options;

        return $flag;
    }
}
