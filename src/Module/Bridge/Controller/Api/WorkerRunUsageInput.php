<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\ValueObject\WorkerRunModelUsage;
use App\Module\Bridge\ValueObject\WorkerRunUsageReport;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** The usage of one worker process, keyed by model name. An empty object means the process spent nothing. */
final class WorkerRunUsageInput
{
    public const int MAX_MODELS = 20;

    public function __construct(
        #[Assert\Choice(callback: 'sources')]
        #[Assert\NotBlank]
        public ?string $source = null,

        /** @var array<string, WorkerRunModelUsageInput>|null */
        #[Assert\Count(max: self::MAX_MODELS)]
        #[Assert\NotNull]
        #[Assert\Valid]
        public ?array $models = null,
    ) {
    }

    /** @return list<string> */
    public static function sources(): array
    {
        return array_map(static fn (WorkerRunUsageSource $source): string => $source->value, WorkerRunUsageSource::cases());
    }

    #[Assert\Callback]
    public function validateModelNames(ExecutionContextInterface $context): void
    {
        if (null === $this->models || [] === $this->models) {
            return;
        }

        if (array_is_list($this->models)) {
            $context->buildViolation('The models are an object keyed by model name.')->atPath('models')->addViolation();

            return;
        }

        foreach (array_keys($this->models) as $name) {
            $length = mb_strlen((string) $name);
            if ($length < 1 || $length > WorkerRunUsage::MAX_MODEL_LENGTH) {
                $context->buildViolation('A model name is 1 to 100 characters.')->atPath('models')->addViolation();

                return;
            }
        }
    }

    public function report(): WorkerRunUsageReport
    {
        $models = [];
        foreach ($this->models ?? [] as $name => $model) {
            $models[] = new WorkerRunModelUsage(
                model: (string) $name,
                inputTokens: $model->inputTokens ?? throw new \LogicException('inputTokens is required after validation.'),
                outputTokens: $model->outputTokens ?? throw new \LogicException('outputTokens is required after validation.'),
                cacheReadTokens: $model->cacheReadTokens ?? throw new \LogicException('cacheReadTokens is required after validation.'),
                cacheWriteTokens: $model->cacheWriteTokens ?? throw new \LogicException('cacheWriteTokens is required after validation.'),
                costUsd: $model->costUsd(),
            );
        }

        return new WorkerRunUsageReport(
            WorkerRunUsageSource::from($this->source ?? throw new \LogicException('source is required after validation.')),
            $models,
        );
    }
}
