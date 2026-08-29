---
name: project-command-handler
description: Use when a controller action needs business logic (creating, updating, or deleting anything), or any action beyond rendering a template or redirecting. Documents the command + handler pair pattern, the enforced class shapes, and DomainErrors propagation.
---

# Command + Handler Pattern

Any controller action that does more than render a template or redirect must use
a command + handler pair. The controller injects the handler and calls it
synchronously as a callable. Symfony Messenger is not involved. Do not put
business logic in a controller. Do not route a command through Messenger unless
async dispatch is a stated requirement.

## Scope: every entry point

The pattern covers every entry point, not only controllers. Console commands
(`Command/Console/`) and messenger handlers are thin shells too. They invoke a
`Command/` + `Handler` pair. They never call a business-logic service directly.

Name domain pairs verb-first, for example `RunTrialSweepCommand`. Give the
domain pair a name that differs from the console command class and the messenger
handler class, so the three never collide.

`PurgeExpiredExportsCommand` injects `ExpiredExportPurger` directly, a known
pre-existing violation. Migrate it when you touch it. Do not copy it.

## File layout

Both classes live in the module's `Command/` directory:

```
src/Module/Project/Command/CreateIssueCommand.php
src/Module/Project/Command/CreateIssueHandler.php
```

Name the pair after the action: `<Action><Entity>Command` / `<Action><Entity>Handler`.

## The command carries data only

Write a `final readonly class` with public promoted constructor properties. It
holds no logic, and no public method other than `__construct()`.

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

- A command may carry an entity directly. The controller resolved it through
  route entity mapping.
- External-system data: carry only the stable ID. When the action imports or
  syncs data from a third-party API, the command holds the identifier only (for
  example `int $externalRepositoryId`). The handler fetches the full payload
  inside `__invoke()`.
- Sealed-parameter commands: one call site passes magic constants, and another
  passes real variables. Do not share one command between them. Create a
  dedicated command for the fixed invocation, for example an
  `OpenBrainstormCommand` whose handler hardcodes the seed prompt. A constructor
  that one caller feeds string constants is a smell.
- Raw input in, parsing in the handler. When the input is unstructured text that
  needs parsing or normalization (for example a textarea pasted as one item per
  line), the command carries the raw scalar string. The handler runs the parser
  inside `__invoke()`. Do not parse in the controller and pass a pre-built array.
  This is the same shape as the external-API rule. Every consumer of the handler
  (another handler, a console command, a test) then gets the parsing, the dedup
  and the normalization, and the controller stays a thin
  request/handler/response shell.

  ```php
  // ✗ controller parses, command carries the result
  new ImportItemsCommand(items: ItemsImport::parse($data->items ?? ''));
  // ✓ command carries raw text, handler parses
  new ImportItemsCommand(items: $data->items ?? '');
  // handler __invoke(): $labels = ItemsImport::parse($command->items);
  ```

## The handler owns the business logic

Write a `final readonly class` with exactly one public method, `__invoke()`. It
takes the command. It returns the created or affected entity, or `void`. The
handler owns repository reads, persistence (`persist` + `flush`), side effects,
domain logging and async dispatch. A handler may dispatch a Messenger message as
a side effect; only the command itself never goes through Messenger.

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

## Read handlers and view naming

Not every handler writes. A controller action that only renders but needs data
must still go through a handler, because a controller may not touch a repository
directly (`controller.directStateAccess`; see `project-backend`). A read/query
handler follows the same shape rules, `final readonly` and a single `__invoke()`,
but returns a view object instead of a persisted entity. A view is a
`final readonly` DTO in the same `Command/` directory.

Two naming rules keep a handler and its view coherent:

- The view name mirrors the handler name. A `List<X>Handler` returns a
  `List<X>View`, verb-first, for example `ListIssuesHandler` returns
  `ListIssuesView`. Do not invert it to `<X>ListView`.
- The returned data must contain what the handler name promises. A
  `ShowProjectHandler` that returns a bare `list<Issue>` is wrong, because the
  name leads you to expect a `Project`. A `Show<X>Handler` returns a view that
  contains the looked-up `<X>` and its related data, for example
  `ShowProjectHandler` returns `ProjectDetailView { public Project $project; … }`.
  The controller then reads the entity off the view (`'project' => $view->project`),
  and not from the route parameter separately.

Keep the `Show*` family (noun-first, `ProjectDetailView`) and the `List*` family
(verb-first, `ListIssuesView`) distinct. Match the family you extend. Do not
unify them.

## Enforced shapes (gamache PHPStan rules)

`Gamache\PHPStan\CommandShapeRule` and `HandlerShapeRule` run under `just ci` on
every class in a `Command\` namespace.

- Command (name does not end in `Handler`, no parent class): must be
  `final readonly`, with no public method besides `__construct()`. Identifiers:
  `command.notFinalReadonly`, `command.hasPublicMethods`.
- Handler (name ends in `Handler`): must be `final readonly`, with exactly one
  public method, `__invoke()`. Identifiers: `handler.notFinalReadonly`,
  `handler.invalidShape`.
- Symfony console commands extend `Symfony\...\Command`, so the rule skips them.
  They live in `Command/Console/`.

## Calling from the controller

Inject the handler and invoke it as a callable. The controller only parses the
request, calls the handler, handles `DomainErrors` and returns a response.

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

## DomainErrors, the domain validation failure channel

`App\Exception\DomainErrors` (`src/Exception/DomainErrors.php`) carries
field-level domain failures from the handler to the controller. Its payload is a
`non-empty-array<string, string>` of field name to translation key. Never throw
it with an empty array.

In the handler, collect the failures and `throw new DomainErrors($errors)`. This
covers uniqueness and duplicate checks, and state preconditions such as
"resource already in desired state". The handler injects the repositories it
needs and enforces the invariant itself. The controller must never pre-check a
domain rule before it calls the handler.

In the controller, catch the exception and map it onto the form. Inject
`TranslatorInterface $translator`.

```php
} catch (DomainErrors $e) {
    foreach ($e->errors as $field => $translationKey) {
        $form->get($field)->addError(new FormError($this->translator->trans($translationKey)));
    }
}
```

Then re-render with `renderFormResponse()`, which sets HTTP 422 on a submitted
form.

## Handler composition

A handler may inject another handler and call it as a callable,
`($this->subHandler)(new SubCommand(...))`. Do this only when other call sites
also invoke the sub-operation. If it is only ever a sub-step, inline the logic.

The caller decides whether `DomainErrors` from the sub-handler propagates, or is
a tolerated fallback. Check the values, which are translation keys, with
`in_array`. Re-throw anything unexpected.

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

## Related conventions

The examples above are illustrative. The skeleton ships no concrete command
classes of its own. For surrounding conventions (controllers, forms/DTOs, flash
messages, logging), see `project-backend`. For handler tests, see
`project-testing`.
