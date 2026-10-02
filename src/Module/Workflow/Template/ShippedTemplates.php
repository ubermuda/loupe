<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/** The templates that ship in config/workflows. */
final readonly class ShippedTemplates
{
    private const array KEYS = ['lifecycle', 'simple'];

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    /** @return list<string> */
    public function keys(): array
    {
        return self::KEYS;
    }

    /**
     * @return array<mixed> the template array, before the parser checks it
     *
     * @throws UnknownTemplate
     */
    public function source(string $key): array
    {
        // Check the key against the list before it becomes part of a path.
        if (!\in_array($key, self::KEYS, true)) {
            throw new UnknownTemplate($key);
        }

        $source = Yaml::parseFile(\sprintf('%s/config/workflows/%s.yaml', $this->projectDir, $key));

        return \is_array($source) ? $source : throw new \LogicException(\sprintf('The shipped workflow template "%s" is not a map.', $key));
    }
}
