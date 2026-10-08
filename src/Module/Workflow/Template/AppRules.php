<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/** The rules the app adds after the rules of every project's template, and the prompts they name. */
final class AppRules
{
    /** @var ?list<Rule> */
    private ?array $rules = null;

    /** @var ?list<AppRequest> */
    private ?array $requests = null;

    public function __construct(
        private readonly TemplateParser $parser,

        #[Autowire('%kernel.project_dir%/config/workflows/app')]
        private readonly string $directory,
    ) {
    }

    /**
     * @return list<Rule>
     *
     * @throws InvalidTemplate
     */
    public function rules(): array
    {
        return $this->rules ??= $this->load();
    }

    /**
     * @return list<AppRequest>
     *
     * @throws InvalidTemplate
     */
    public function requests(): array
    {
        $this->rules();

        return $this->requests ?? [];
    }

    /** @throws InvalidTemplate when a rule of the template has the id of an app rule */
    public function appendTo(Template $template): Template
    {
        $rules = $this->rules();
        if ([] === $rules) {
            return $template;
        }

        $templateIds = array_map(static fn (Rule $rule): string => $rule->id, $template->rules);
        $errors = [];
        foreach ($rules as $rule) {
            if (\in_array($rule->id, $templateIds, true)) {
                $errors[] = \sprintf('rules (%s): the template "%s" has a rule with the same id', $rule->id, $template->key);
            }
        }
        if ([] !== $errors) {
            throw new InvalidTemplate($errors);
        }

        return new Template(
            $template->key,
            $template->version,
            $template->slots,
            [...$template->rules, ...$rules],
            $template->manualMoves,
            $template->backoffMinutes,
            $template->workTimeoutMinutes,
            $template->types,
            $template->defaultType,
            $template->onWorkFailed,
        );
    }

    public function prompt(string $name): string
    {
        // Check the name against the rules and requests before it becomes part of a path.
        $names = [
            ...array_map(static fn (Rule $rule): mixed => $rule->then->params[TemplateParser::PROMPT] ?? null, $this->rules()),
            ...array_map(static fn (AppRequest $request): string => $request->prompt, $this->requests()),
        ];
        if (!\in_array($name, $names, true)) {
            throw new \InvalidArgumentException(\sprintf('No app rule or request names the prompt "%s".', $name));
        }

        $text = file_get_contents($this->promptPath($name));

        return false !== $text ? $text : throw new \RuntimeException(\sprintf('The app prompt "%s" cannot be read.', $name));
    }

    /** @return list<Rule> */
    private function load(): array
    {
        $source = Yaml::parseFile($this->directory.'/rules.yaml');
        if (!\is_array($source)) {
            throw new InvalidTemplate(['rules.yaml: must be a map']);
        }
        $rules = $this->parser->parseAppRules($source);
        $requests = $this->parser->parseAppRequests($source);

        $errors = [];
        $ruleIds = array_map(static fn (Rule $rule): string => $rule->id, $rules);
        foreach ($requests as $request) {
            if (\in_array($request->id, $ruleIds, true)) {
                $errors[] = \sprintf('requests (%s): a rule has the same id', $request->id);
            }
            if (!is_file($this->promptPath($request->prompt))) {
                $errors[] = \sprintf('requests (%s): prompt "%s" has no file prompts/%s.md', $request->id, $request->prompt, $request->prompt);
            }
        }
        foreach ($rules as $rule) {
            $name = $rule->then->params[TemplateParser::PROMPT] ?? null;
            if (\is_string($name) && !is_file($this->promptPath($name))) {
                $errors[] = \sprintf('rules (%s): prompt "%s" has no file prompts/%s.md', $rule->id, $name, $name);
            }
        }
        if ([] !== $errors) {
            throw new InvalidTemplate($errors);
        }
        $this->requests = $requests;

        return $rules;
    }

    private function promptPath(string $name): string
    {
        return \sprintf('%s/prompts/%s.md', $this->directory, $name);
    }
}
