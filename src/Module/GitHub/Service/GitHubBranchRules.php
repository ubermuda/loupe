<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** The status check rules that apply to one branch, read from `GET /repos/{owner}/{repo}/rules/branches/{branch}`. */
final readonly class GitHubBranchRules
{
    /** @param list<string> $requiredChecks */
    public function __construct(
        public array $requiredChecks,
        public bool $strict,
    ) {
    }

    /**
     * @param array<mixed> $rules
     *
     * @throws \UnexpectedValueException when the answer is not a list of rules
     */
    public static function fromRules(array $rules): self
    {
        if (!array_is_list($rules)) {
            throw new \UnexpectedValueException('The branch rules are not a list.');
        }

        $checks = [];
        $strict = false;
        foreach ($rules as $rule) {
            if (!\is_array($rule) || 'required_status_checks' !== ($rule['type'] ?? null) || !\is_array($rule['parameters'] ?? null)) {
                continue;
            }

            $strict = $strict || true === ($rule['parameters']['strict_required_status_checks_policy'] ?? null);
            foreach (\is_array($rule['parameters']['required_status_checks'] ?? null) ? $rule['parameters']['required_status_checks'] : [] as $check) {
                $context = \is_array($check) ? ($check['context'] ?? null) : null;
                if (\is_string($context) && '' !== $context) {
                    $checks[$context] = true;
                }
            }
        }

        return new self(array_map(strval(...), array_keys($checks)), $strict);
    }
}
