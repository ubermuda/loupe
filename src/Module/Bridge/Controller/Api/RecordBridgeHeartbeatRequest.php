<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\Bridge;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One heartbeat, as the bridge sends it.
 *
 * @phpstan-import-type HookRow from Bridge
 */
final class RecordBridgeHeartbeatRequest
{
    /** Far above the projects one account runs, and small enough to bound the JSON column. */
    public const int MAX_PROJECTS = 500;

    /** Far above the hooks one bridge runs, and small enough to bound the JSON column. */
    public const int MAX_HOOKS = 100;

    /**
     * @param list<string>|null          $projects
     * @param list<BridgeHookInput>|null $hooks    null from a bridge that predates hooks
     */
    public function __construct(
        #[Assert\All([new Assert\NotBlank(), new Assert\Uuid()])]
        #[Assert\Count(max: self::MAX_PROJECTS)]
        #[Assert\NotNull]
        #[Assert\Type('list')]
        public ?array $projects = null,

        #[Assert\Length(max: Bridge::MAX_CLI_VERSION_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $cliVersion = null,

        #[Assert\All([new Assert\Type(BridgeHookInput::class)])]
        #[Assert\Count(max: self::MAX_HOOKS)]
        #[Assert\Type('list')]
        #[Assert\Valid]
        public ?array $hooks = null,
    ) {
    }

    /**
     * The hook rows as the bridge row stores them, with every time in one
     * format. Null when the bridge sent no report.
     *
     * @return list<HookRow>|null
     */
    public function hooks(): ?array
    {
        if (null === $this->hooks) {
            return null;
        }

        return array_map(static fn (BridgeHookInput $hook): array => [
            'package' => trim($hook->package ?? ''),
            'ref' => trim($hook->ref ?? ''),
            'event' => $hook->event ?? '',
            'lastRunAt' => $hook->lastRunAt?->format(\DateTimeInterface::ATOM),
            'outcome' => $hook->outcome ?? '',
            'error' => '' === trim($hook->error ?? '') ? null : trim($hook->error ?? ''),
        ], array_values($this->hooks));
    }

    /**
     * The project ids in canonical form, once each, so an upper-case copy of an
     * id cannot slip past the ownership filter as a second entry.
     *
     * @return list<string>
     */
    public function projectIds(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $id): string => Uuid::fromString($id)->toRfc4122(),
            $this->projects ?? [],
        )));
    }

    /** Trimmed, because the length constraint measured the trimmed value. */
    public function cliVersion(): string
    {
        return trim($this->cliVersion ?? '');
    }
}
