---
name: project-command-handler
description: Use when a controller action needs business logic — creating, updating, or deleting anything, or any action beyond rendering a template or redirecting. Documents the command + handler pair pattern, the enforced class shapes, and DomainErrors propagation.
---

# Command + Handler Pattern

Any controller action that does more than render a template or redirect must be
backed by a **command + handler pair**. No Symfony Messenger involved — the
controller injects the handler and calls it synchronously as a callable. Do not
put business logic directly in a controller, and do not route commands through
Messenger unless async dispatch is explicitly required.

## File layout

Both classes live in the module's `Command/` directory:

```
src/Module/Project/Command/CreateIssueCommand.php
src/Module/Project/Command/CreateIssueHandler.php
```

Name the pair after the action: `<Action><Entity>Command` / `<Action><Entity>Handler`.

## The command — a pure data carrier

`final readonly class`, public promoted constructor properties, **no logic and
no public methods other than `__construct()`**. Example:

```php
final readonly class CreateIssueCommand
{
    public function __construct(
        public Project $project,
        /** @phpstan-var non-empty-string */
        public string $title,
        public ?string $description,
    ) {
    }
}
```

- Commands may carry entities directly (the controller resolved them via route
  entity mapping).
- **External-system data: carry only the stable ID.** When the action imports
  or syncs data from a third-party API, the command holds only the identifier
  (e.g. `int $externalRepositoryId`); the handler fetches the full payload
  inside `__invoke()`.
- **Sealed-parameter commands:** when one call site would pass magic constants
  and another real variables into a shared command, create a dedicated command
  for the fixed invocation instead (e.g. an `OpenBrainstormCommand` whose handler
  hardcodes the seed prompt). A constructor where one caller passes string
  constants is a smell.

## The handler — owns all business logic

`final readonly class` with **exactly one public method: `__invoke()`**, taking
the command and returning the created/affected entity (or `void`). The handler
owns repository reads, persistence (`persist` + `flush`), side effects, domain
logging, and async dispatch. (Dispatching a Messenger message from inside the
handler as a side effect is fine — it is the command itself that never goes
through Messenger.) Example:

```php
final readonly class CreateProjectHandler
{
    public function __construct(
        private ProjectRepository $projectRepo,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(CreateProjectCommand $command): Project
    {
        $existing = $this->projectRepo->findByOrgAndSlug($command->org, $command->slug);
        if (null !== $existing) {
            throw new DomainErrors(['slug' => 'project.new_project.error.slug_duplicate']);
        }

        $project = new Project($command->org, $command->name, $command->slug);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
```

## Enforced shapes (gamache PHPStan rules)

`Gamache\PHPStan\CommandShapeRule` and `HandlerShapeRule` run under `just ci`
on every class in a `Command\` namespace:

- **Command** (name not ending in `Handler`, no parent class): must be
  `final readonly`; no public methods besides `__construct()`
  (`command.notFinalReadonly`, `command.hasPublicMethods`).
- **Handler** (name ending in `Handler`): must be `final readonly`; exactly one
  public method, `__invoke()` (`handler.notFinalReadonly`,
  `handler.invalidShape`).
- Symfony console commands extend `Symfony\...\Command` and are skipped by the
  rule; they live in `Command/Console/`.

## Calling from the controller

Inject the handler and invoke it **as a callable**. The controller's only jobs:
parse the request, call the handler, handle `DomainErrors`, return a response.
Example:

```php
public function __construct(
    private readonly CreateIssueHandler $createIssueHandler,
) {
}

// inside __invoke(), after $form->isValid():
$issue = ($this->createIssueHandler)(new CreateIssueCommand(
    project: $project,
    title: $formRequest->title ?: throw new \LogicException('title required after validation'),
    description: $formRequest->description,
));
```

## DomainErrors — domain validation failures

`App\Exception\DomainErrors` (`src/Exception/DomainErrors.php`) is the bridge
from handler to controller for field-level domain failures. Its payload is
`non-empty-array<string, string>` of **field name → translation key** — never
throw it with an empty array.

**In the handler:** collect failures and `throw new DomainErrors($errors)`.
This includes uniqueness/duplicate checks and state preconditions ("resource
already in desired state") — the handler injects the repositories it needs and
enforces the invariant itself. The controller must never pre-check domain rules
before calling the handler.

**In the controller:** catch and map onto the form (inject
`TranslatorInterface $translator`):

```php
} catch (DomainErrors $e) {
    foreach ($e->errors as $field => $translationKey) {
        $form->get($field)->addError(new FormError($this->translator->trans($translationKey)));
    }
}
```

Then re-render with `renderFormResponse()` (sets HTTP 422 on submitted forms).

## Handler composition

A handler may inject and call another handler as a callable —
`($this->subHandler)(new SubCommand(...))` — **only** when the sub-operation is
also invoked from other call sites. If it's only ever a sub-step, inline the
logic. The caller decides whether `DomainErrors` from the sub-handler
propagates or is a tolerated fallback; check the **values** (translation keys)
with `in_array`, and re-throw anything unexpected:

```php
try {
    ($this->openBrainstorm)(new OpenBrainstormCommand($conversation));
} catch (DomainErrors $e) {
    if (!\in_array('brainstorm.error.no_inference_key', $e->errors, true)) {
        throw $e;
    }
    $this->logger->info('project.issue.brainstorm_open_skipped', [...]);
}
```

## Patterns recap

The examples above are illustrative — the skeleton ships no concrete command
classes of its own. The conceptual points worth remembering:

- **Composition with a tolerated fallback** — a handler calling another handler
  may catch its `DomainErrors`, tolerate specific known translation keys, and
  re-throw the rest.
- **ID-only commands for external APIs** — when syncing data from a third-party
  system, the command carries only the stable identifier and the handler fetches
  the payload.
- **Uniqueness via `DomainErrors`** — duplicate/uniqueness checks live in the
  handler, which throws `DomainErrors` keyed by the offending field.

For surrounding conventions (controllers, forms/DTOs, flash messages, logging),
see `project-backend`. For handler tests, see `project-testing`.
