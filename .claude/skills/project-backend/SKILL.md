---
name: project-backend
description: Use when working on PHP code under `src/` — forms, DTOs, Doctrine entities, controllers, commands, flash messages, or any PHP implementation work. For authorization see `project-authz`. For PHPUnit tests see `project-testing`.
---

# Backend — Forms/DTOs, Doctrine, Controllers, Flash Messages

## Module boundaries

No code in `src/Module/X/` may import from `src/Module/Y/` — not entities, not services, not controllers, not repositories. Each module is an island; cross-module coupling is a structural defect.

**Enforcement:** phparkitect. Run `just arkitect`. When a violation is reported, do not suppress it — redesign instead.

**Design patterns for legitimate cross-module needs:**

- **Shared abstraction in the owning module:** if module Y extends a base class from module X, add an abstract property hook or interface method to the base in module X. Each subtype in its own module implements it. Callers in module X read the abstract member and never need to know the concrete type. Example: `Repository` (Project module) declares `abstract public string $label { get; }`. `GitHubRepository` (GitHub module) implements it. Templates read `project.repository.label` — no GitHub import needed anywhere in the Project module.
- **Events / Symfony Messenger:** for side effects that cross module boundaries, dispatch a domain event from the originating module and handle it in the receiving module.
- **Shared value objects:** truly generic types (e.g. a `Slug` value object) may live in the root namespace (`src/`) rather than any module.

## Forms and DTOs

- **User input for HTML/Turbo endpoints is bound through a Symfony form (DTO + FormType), never hand-parsed from the `Request`.** This includes Turbo / AJAX / stream endpoints that render UI — there is no "it's just a small POST" exception. Do not write `$request->request->get('x')` + ad-hoc `is_numeric` / `trim` validation in a controller; create a `FooRequest` DTO with constraints and a `FooFormType`, then `createForm()` / `handleRequest()` / `isSubmitted() && isValid()`. The only raw-request reads that remain acceptable are non-form technical values such as a CSRF token on a hand-rolled fieldless form (see "Stateless CSRF tokens for hand-rolled forms") and dev/test-only controllers. **JSON API endpoints are the exception — they use `#[MapRequestPayload]`, not forms (see "API controllers" below).**
- Forms must not bind directly to Doctrine entities
- Instead, create a DTO named with a `Request` suffix (e.g. `FooRequest`) that lives alongside the form file
- DTOs use constructor promoted properties with sensible defaults
- DTOs have a static named constructor to hydrate from an entity **when there is an edit flow that pre-populates the form** (e.g. `FooRequest::fromFoo($entity)`). Do not add `fromEntity()` factories speculatively — unused static constructors are dead code.
- **DTO inheritance when update mirrors create:** When an update DTO has identical fields and validation constraints to its create counterpart, extend rather than duplicate: `class UpdateFooRequest extends CreateFooRequest {}`. PHP attribute inheritance on constructor-promoted properties works correctly with Symfony's Validator. Duplication is dead code — any future change to constraints would need to be applied twice.
- Validation constraints live on the DTO, not in the form's `buildForm()`. **Exception:** forms with no `data_class` (no bound DTO) may keep constraints inline in `buildForm()` — there is nowhere else to put them. The PHPStan `form.inlineConstraints` rule only fires for forms that configure a `data_class`. Property constraints (`#[NotBlank]`, `#[Length]`, etc.) go on individual promoted properties. **Class-level constraints** (validators that need access to the whole DTO or a related object) go as `#[MyConstraint]` on the class itself — the validator receives the whole DTO as `$value` and must declare `#[\Override] public function getTargets(): string { return self::CLASS_CONSTRAINT; }`. Name the constraint after what it enforces, not after the mechanism.
- Controllers pass `new FooRequest()` (or `FooRequest::fromFoo($entity)` for edit forms) to `createForm()`, then map the DTO back onto the entity after successful submission
- `#[UniqueEntity]` on an entity does not fire when the form's `data_class` is a DTO — validation runs on the DTO, not the entity. Check uniqueness in the controller after `$form->isValid()` using `findOneBy()`, then attach the error to the specific field: `$form->get('email')->addError(new FormError('There is already an account with this email.'))`.
- When a DTO has a nullable property that cannot actually be null after validation, don't use `assert()`. Use `if (null === $data->prop) { $form->addError(new FormError(...)); } else { ... }` so the user gets a proper form error.
- Every `AbstractType` subclass must declare `@extends AbstractType<DataClass>` (or `@extends AbstractType<array<string, mixed>>` for forms with no data class) so PHPStan and the IDE can infer the return type of `$form->getData()`.
- When the same form needs to be instantiated once per item in a template loop (e.g. a delete button per row), do not build the forms in the controller and pass an array. Instead, create a Twig function in the relevant module's `Twig/*Extension.php` and call it directly in the template.
  - When such a per-row form is also **submitted and re-rendered** (not just a fire-and-forget button), create it with `FormFactoryInterface::createNamed('<prefix>_'.$id, …)` so the rendered field `id`/`name` attributes don't collide across rows, and have the receiving controller rebuild the form with the **same** name before `handleRequest()`. Extract the name to a shared `public static` helper so both sides agree. Surface validation errors by passing the bound `FormView` back into that row's component/partial.
- **`form_rest()` renders all unrendered form fields**, including `CollectionType` fields. If a collection's submitted values must appear at a specific position (e.g. inside a Stimulus controller div), render them explicitly with a `{% for field in form.collection %}` loop at that location — once rendered, `form_rest` will not re-render them.
- **CollectionType errors bubble to the root form by default.** Render them once with `form_errors(rootForm)` at the top of the form. Do not also call `form_errors(field)` for the CollectionType field — this double-renders the error. Do not set `error_bubbling: false` unless you specifically want field-level error display and have verified `form_rest` won't re-render them a second time.
- **Symfony block prefix — compound words are split.** `AbstractType::getBlockPrefix()` converts camelCase to snake_case word-by-word, so compound words become two fragments: `ImportGitHubRepoFormType` → strips `Type` → `ImportGitHubRepoForm` → `import_git_hub_repo_form` (not `import_github_repo_form`). Abbreviations like `GitHub`, `OAuth`, and `API` embedded in a class name each become two snake_case words. If you remove an explicit `getBlockPrefix()` override, audit every test that submits form fields by name — those strings must change from the old prefix to the auto-derived one. The `InvalidArgumentException: Unreachable field` error at test runtime is the symptom of a stale field-name string.

## Doctrine

- Never update migrations directly — always update entities and let `just migrate-diff` generate the migration. **Exception:** see "Brand-new tables in the current branch" below.
- **Brand-new tables in the current branch:** When removing or modifying a column from a table that was *also* first created in the current branch (not yet in `main`), edit the create-migration directly to reflect the final schema. Do not generate a new ALTER migration — that adds unnecessary steps for a table no deployed database has ever seen.
- **Use `#[ORM\OneToOne]` for exclusive (has-one) relationships.** When an entity can have at most one related entity, use `#[ORM\OneToOne]` not `#[ORM\ManyToOne]`. `OneToOne` automatically implies a `UNIQUE` constraint on the FK column, so a manual `#[ORM\UniqueConstraint(columns: ['...'])]` is redundant and should be removed. Verify with `just migrate-diff` — "No changes detected" confirms the schema is consistent.
- Doctrine entities use property hooks, no getters/setters.
- Entities should be always-valid: all data required at construction time should be constructor-promoted parameters. Lifecycle fields set after construction (e.g. `$usedAt`, `$lastLoginAt`) stay as regular nullable properties.
- **Entity properties are plain `public` — no asymmetric visibility (`private(set)`) except on `$id`.** The `$id` property uses `public private(set) ?Uuid $id = null` because Doctrine sets it after `flush()` and callers must never assign it. All other properties must be plain `public string`, `public ?string`, etc. Asymmetric visibility on plain-data properties adds friction in tests and handlers without meaningful encapsulation benefit.
- Constructor promotion is safe for Doctrine entities.
- `new \DateTimeImmutable()` is a valid default constructor parameter value (PHP 8.1 "new in initializers") — use it for timestamp fields like `$createdAt`. Static method calls (e.g. `Ulid::generate()`) are not valid defaults and must be set in the constructor body.
- Property hooks can be inlined on promoted properties (PHP 8.4).
- Entities must not contain logic that requires a service (e.g. slugging, URL generation). Computed properties that depend on a service belong in that service, not as virtual property hooks on the entity.
- **Put shared path/identifier computation on the entity as a `public static` method.** When multiple handlers or services need to compute the same derived value for an entity (e.g. a canonical path or storage key built from the entity's own fields, with no service dependency), add a `public static function computeFoo(...): string` to the entity class. Static (not instance) because callers often only have raw identifiers. This gives a single source of truth and prevents drift across handlers.
- Repositories handle persistence only (queries, existence checks, writes). Logic that requires injecting non-persistence services (generators, sluggers, encoders) belongs in an Action or dedicated service — not the repository.
- Entities use ULID primary keys by default; use integer ids only where a sequence is semantically appropriate.
- **Converting a string column to a backed enum — no migration required.** When a `string` column already stores the backing values you want to introduce as a PHP enum, switching the PHP type does not change the DB schema. Keep `length:` from the original `#[ORM\Column]`, add `enumType:`, and verify with `just migrate-diff`:
  ```php
  // Before
  #[ORM\Column(length: 20)]
  public readonly string $status,

  // After — keep length:, add enumType:
  #[ORM\Column(length: 20, enumType: FooStatus::class)]
  public readonly FooStatus $status,
  ```
  Doctrine stores and reads the enum's `->value` (a plain string) in the same varchar column. If `just migrate-diff` reports "No changes detected", no migration is needed. If it proposes an `ALTER`, the backing values changed or the column definition drifted; resolve that before proceeding. PHPStan level 8 then enforces correct usage at all instantiation sites — string literals in the constructor become type errors automatically.


## Controllers

- Controllers are named `<Action><Entity>Controller` (e.g. `CreateFooController`, `ShowFooController`, `ListFooController`)
- **Issue phase controllers — one per phase, not one with guards.** Each `IssuePhase` gets its own dedicated controller (e.g. `IssueBrainstormController` for `Brainstorm`). Do not write a single `IssueDetailController` with `if ($issue->phase === ...)` blocks. For now, `IssueBrainstormController` serves the issue detail URL with route name `app_issue_detail`. When a second phase ships, add a second controller and split the routing — do not add another guard to the existing one.
- **Do not extract single-use private "response-builder" helpers in controllers — inline them.** When a private method exists only to assemble and return a `Response` (render-or-redirect, stream-or-redirect, JSON wrapper) and is called from one or two places in the same controller, inline it into `__invoke`. Converge multiple exit paths into a single tail (e.g. collapse two error branches into one `$errorMessage` variable, then one redirect-fallback + render block) rather than dispatching to a thin wrapper. A separate method that is only ever used once is noise that makes the controller harder to read top-to-bottom. **This applies to thin response wrappers only** — a single-use private method that *computes a value* via real logic (e.g. building an error string by looping over `$form->getErrors()`) is fine to keep; the threshold for extraction is genuine reusable logic, not response plumbing.
- Async tasks use Symfony Messenger with a Doctrine transport. **Message classes live in `Messenger/` within the module** (e.g. `src/Module/Inference/Messenger/RunInferenceJob.php`, namespace `App\Module\Inference\Messenger`). The routing entry in `config/packages/messenger.yaml` must exactly match the PHP namespace (gamache-enforced).
- **Injecting a FormView into a Twig component via forward:** When a form controller needs to re-render a page with an invalid form, pass the FormView as a request attribute to `forward()`: `$this->forward(ShowFooController::class, array_merge($params, ['myForm' => $form->createView()]))`. In the receiving Twig component, retrieve it with `$this->getInjectedFormView('myForm')` (a helper on `AppController`) and fall back to a fresh form with `??`: `$this->form = $this->getInjectedFormView('myForm') ?? $this->createForm(...)->createView();`. Always pair this with a 422 status code (see the project-frontend skill).

**`AppController` helpers:** `AppController` lives in `src/Controller/AppController.php`. Existing helpers:
- `renderFormResponse(string $view, FormInterface $form, array $extra = []): Response` — renders and automatically sets HTTP 422 when the form was submitted (invalid), 200 otherwise. Use this in every controller that renders a form instead of chaining `->setStatusCode(...)` manually. When a `DomainErrors` exception is caught (see below), add the field errors to the form and re-render with this method.
- `getInjectedFormView(string $key)` — retrieves a `FormView` forwarded via request attribute (see above).

**Current user:** Use `$this->getUser()` (inherited from `AbstractController` via `AppController`) to retrieve the authenticated user. Do not inject `Symfony\Bundle\SecurityBundle\Security` into a controller solely to call `getUser()`.

For PHPUnit testing patterns (WebTestCase mocking, controller integration tests, mock discipline), see `project-testing`.

## Pre-delivery gate

Before marking any PHP task done, run `just ci` (runs rector + cs-fix + phpstan + e2e). If only checking static analysis: `just phpstan`. Do not deliver code that fails either. Fix the underlying issue — never skip hooks or suppress errors with `@phpstan-ignore` without explaining why in a comment.

Custom PHP CS Fixer rules live in the `ubermuda/gamache` package (see "Custom static-analysis rules" below).

## Code quality targets

PHP 8.5+, PHPStan level 8, Symfony coding standard via CS Fixer, Rector for automated modernization. Actively use PHP 8.5 features — pipe operator (`|>`) for sequential transformations, property hooks, `new` in initializers, etc. Don't write PHP 8.0-style code when a cleaner 8.5 construct exists. Arrow functions on the RHS of `|>` need parentheses: `$x |> (fn ($v) => transform($v)) |> nextFn(...)`.

Always add `#[\Override]` to overriding/interface methods — `just rector` adds it automatically.

## PHPStan annotation patterns

`@var` is only permitted for narrowing a type the IDE/PHPStan cannot infer (e.g. narrowing a union after a `getRepository()` call). Never use it for type checking — use explicit guards instead.

When a variable cannot actually be null after validation but PHPStan can't prove it, don't use `assert()` blindly — handle it explicitly (e.g. a `FormError`, an early return, or a thrown exception).

## Flash messages

- For simple flash messages without a specific structure, use `->addFlash('<type>', '<flash message>')`
- If you need more control on the display of the alert, you can use `->addFlash('<flash message>', true)` and build the custom alert in `base.html.twig`

## Domain Observability

When code makes a domain decision (access denied, state transition, significant mutation, controlled fallback), log it at `info` level with a structured context array. Use `warning` for unexpected-but-recoverable situations.

**What to log:**
- Access denied by a business-rule gate → `info` with relevant IDs and the rule that fired
- Significant state changes (create, update, delete) → `info` with entity IDs and actor
- Controlled skips / redirects driven by state → `info` with reason

**How to log:**
- In controllers (subclasses of `AppController`): use `$this->getLogger()->info('event.name', ['key' => $value])`
- In services / actions: inject `LoggerInterface` via constructor

**Event name convention:** `<module>.<entity>.<outcome>` — e.g. `foo.created`, `bar.access_denied`

**Context keys:** always include entity IDs and any flags or counts that explain the decision.

## Admin list / edit conventions

Admin list pages must preserve filter and sort state when the user clicks into a row's Edit/Delete/Toggle, so the form's "Back" / "Cancel" links land them on the same filtered view. The shared `App\Module\Admin\Listing\AdminReturnTo` service handles the open-redirect guard (it accepts only `/admin/`-prefixed URLs and logs `admin.list.return_to_rejected` on rejection).

- On the list, build the Edit link with `returnTo: app.request.requestUri` in the query params: `path('app_admin_<entity>_edit', { id: ..., returnTo: app.request.requestUri })`. For Delete (modal) and Toggle, add `<input type="hidden" name="returnTo" value="{{ app.request.requestUri }}">` between `form_start` and `form_end`.
- On the **Edit** controller, inject `AdminReturnTo`, validate `$request->query->get('returnTo')`, and redirect to `app_admin_<entity>_edit` with `id` plus the validated `returnTo` forwarded as a query param. Saving lands the user back on the edit page they just submitted (so they can keep iterating and see the refreshed sidebar) while the form's Back/Cancel links still go to the filtered list. Never redirect directly to `returnTo` on Edit save.
- On **Delete** and **Toggle** controllers, validate `$request->request->get('returnTo')` (POST body) and redirect there on success, else to the list route — the entity is gone or the user wanted to stay on the list.
- On the edit template, read it as `{% set returnTo = app.request.query.get('returnTo') %}` and use `(returnTo and returnTo starts with '/admin/') ? returnTo : path('app_admin_<entity>_list')` for both the "Back to ..." header link and the Cancel button.

## PHPStan narrowing patterns

**`non-empty-string` at call sites:** When a property is annotated `/** @phpstan-var non-empty-string */`, PHPStan requires callers to pass `non-empty-string`, not plain `string`. To narrow a `?string` DTO value (e.g. from a form), use `$dto->field ?: throw new \LogicException('field required after validation')` — PHPStan understands the `?:` truthy check as `non-empty-string` narrowing. Do not use `(string)` casts or `@phpstan-ignore` to silence this.

**`nullsafe.neverNull` — use `->` after a guard that proves non-null transitively.** PHPStan level 8 flags `$x?->foo()` as an error when it can prove `$x` is non-null at that point. A common case: after `$rawSlug = $request?->attributes->get('slug'); if (!is_string($rawSlug)) { return; }`, `$request` is transitively guaranteed non-null (it being null would have produced `null`, failing `is_string()`). Any subsequent `$request?->` will trigger `nullsafe.neverNull`. Use `$request->` instead.

## DomainErrors — command-to-controller bridge

`App\Exception\DomainErrors` (`src/Exception/DomainErrors.php`) is the standard way to propagate field-level domain validation failures from command handlers back to controllers. Command handlers collect failures into `$errors` (an `array<string, string>` of `field name => translation key`), then `throw new DomainErrors($errors)` if non-empty. Controllers catch it via `$e->errors` (a public readonly property) and map each entry to a form field error. The controller must inject `TranslatorInterface $translator`:

```php
} catch (DomainErrors $e) {
    foreach ($e->errors as $field => $translationKey) {
        $form->get($field)->addError(new FormError($this->translator->trans($translationKey)));
    }
}
```

Do not add validation logic directly to controllers — push it into the handler and use this exception to surface it. This includes **uniqueness and duplicate checks**: if the handler needs to verify that a slug is not taken, a record hasn't already been imported, or any other domain invariant, the handler does so itself (injecting the required repository) and throws `DomainErrors` on violation. The controller must not query repositories to enforce domain rules before calling the handler.

Also includes **state precondition checks** — before performing an operation, the handler must verify the resource is in a valid state to receive it. Example: before attaching a repository to a project, check `if (null !== $command->project->repository) { throw new DomainErrors(['githubRepositoryId' => 'error.key']) }`. "Resource already in desired state" failures belong in the handler, not the controller.

**Handlers may also catch `DomainErrors` for graceful fallbacks.** When a handler calls a sub-handler that can throw `DomainErrors`, and a specific error code represents a known, tolerable condition (e.g. "no inference key configured → skip opening turn"), catch and inspect `$e->errors` using `in_array` on the **values** (translation keys). Re-throw any error that is not the expected code.
```php
try {
    ($this->subHandler)(new SubCommand(...));
} catch (DomainErrors $e) {
    if (!in_array('module.error.expected_key', $e->errors, true)) {
        throw $e; // unexpected — let it propagate
    }
    // expected fallback: log and continue
}
```
Note: `in_array` checks **values** (translation keys), not array keys (field names) — the translation key is the stable identifier for the error type.

**Hidden fields need explicit `form_errors()`.** `{{ form_errors(form) }}` only renders errors attached to the *form root* via `$form->addError()`. Errors mapped onto child fields via `$form->get('fieldName')->addError(...)` — including those added by the `DomainErrors` catch block — do **not** appear in the root `form_errors()`. For any `HiddenType` field that can receive a `DomainErrors` error, add `{{ form_errors(form.fieldName) }}` explicitly after `{{ form_widget(form.fieldName) }}` in the template.

## Stateless CSRF tokens for hand-rolled forms

When a form does NOT use Symfony's FormType CSRF extension (a plain HTML `<form>` with a manually written `_csrf_token` hidden field), follow this three-part pattern:

1. **Register the token ID** in `config/packages/csrf.yaml` under `stateless_token_ids`:
   ```yaml
   stateless_token_ids:
       - 'my-action'
   ```
2. **Declare the check with the `#[CsrfToken]` attribute** on the controller class (never an inline `isCsrfTokenValid()` call — `ValidateCsrfTokenListener` validates it on `kernel.controller`, before the action runs, and throws the 403 `AccessDeniedException`):
   ```php
   use App\Security\Attribute\CsrfToken;

   #[CsrfToken('my-action')]
   class DoMyActionController extends AppController
   ```
3. **Output the signed token in the template** using the `csrf_token()` Twig function — never a literal string:
   ```twig
   <input type="hidden" name="_csrf_token" value="{{ csrf_token('my-action') }}">
   ```

**Why the literal string fails:** `SameOriginCsrfTokenManager::isTokenValid()` rejects any value shorter than 24 characters that is not the cookie sentinel `"csrf-token"`. Literal token IDs like `"delete-project"` (14 chars) always fail — 403 on every production submission — while tests pass because the test environment has CSRF disabled.

Existing examples: `ResendVerificationEmailController`, `config/packages/csrf.yaml`, `src/Security/Attribute/CsrfToken.php`, `src/Security/EventListener/ValidateCsrfTokenListener.php`.

## Command + handler pattern

Any controller action that does more than render a template or redirect must be backed by a command + handler pair (`Command/FooCommand.php` + `Command/FooHandler.php`, no Symfony Messenger involved). **The pattern — command/handler shapes, calling the handler as a callable, `DomainErrors`, handler composition, sealed-parameter commands, ID-only commands for external-system data — is documented in the `project-command-handler` skill. Invoke it before writing a command or handler.**

Admin-facing controllers for a given feature live under `Controller/Admin/` within that feature's module directory. The `Admin` module (if present) is the shell for the admin area (layout, dashboard, auth promotion) — it does not own feature logic.

## API controllers (JSON endpoints)

A controller that consumes/produces JSON for a machine client (a widget, an SPA, a third party) is an **API controller**, and it follows different conventions from UI controllers:

- **Location & naming:** live under a `Controller/Api/` sub-namespace within the module (`App\Module\X\Controller\Api`). The route **name** is prefixed `api_` (e.g. `api_site_review_submit`) and the route **path** is prefixed `/api/` (e.g. `/api/site-review/batches`). Keep an existing public path stable even after moving the class — external callers, CORS subscribers, and firewall `access_control` rules key on the path.
- **Input binding — `#[MapRequestPayload]`, never a Symfony form.** Type-hint the action parameter with a request DTO and the `#[MapRequestPayload]` attribute; Symfony deserializes the JSON body into the DTO and runs the validator automatically, returning **422** on validation failure with no manual error assembly. Do **not** build a `FormType` + `$form->submit($decodedArray)` for JSON, and do **not** hand-roll `json_decode` + `is_array` checks.
  ```php
  public function __invoke(#[MapRequestPayload] SubmitBatchRequest $payload): JsonResponse
  ```
- **Where the DTO lives:** alongside the controller — in the same `Controller/Api/` directory and namespace (not a separate `Dto/` or `Form/` directory). The top-level payload bound by `#[MapRequestPayload]` is named `<Action>Request` to mirror its controller (`SubmitBatchController` → `SubmitBatchRequest`). This is convention only — gamache's `dto.requestSuffix` rule enforces the suffix solely for DTOs in a `Form/` namespace, so it does not apply to colocated API payloads.
- **Nested DTOs are not requests.** A collection item or sub-object *inside* the payload — needed so the Serializer hydrates a typed element and `#[Assert\Valid]` can cascade per-item — has no controller of its own, so do **not** give it a `*Request` suffix (that wrongly implies a top-level payload). Name it for what it holds: `SiteReviewCommentInput`, not `SiteReviewCommentRequest`. It lives alongside the payload.
- **DTO shape:** plain class with constructor-promoted public properties and validation constraints. `#[Assert\NotBlank]` properties must be `?string` (gamache `dto.notBlankNotNullable`); narrow them at the boundary where you map to the command (`$dto->field ?? ''`, or `?: throw` for `non-empty-string`). A nested collection needs `#[Assert\Valid]` to cascade **plus** a PHPDoc `@param list<ItemInput> $items` so the Serializer knows the element class to hydrate. Use `#[Assert\Count(min: 1)]` to reject an empty collection (→ 422).
- **CORS / auth** are handled by path-scoped subscribers and firewall rules, not the controller — see the firewall notes in `project-authz` and the per-endpoint CORS subscriber pattern. The `401`/`403`/preflight paths run before the controller, so `#[MapRequestPayload]` never sees an unauthenticated request.

## Route conventions

**Route paths must not use an underscore prefix.** The `/_foo` convention is Symfony-internal (web profiler, debug toolbar). Application routes begin with `/` followed directly by a word character — `/workspace/{workspaceId}/sync`, not `/_workspace/{workspaceId}/sync`.

**Parameter names: camelCase, no abbreviations.** Every `{placeholder}` in a Symfony `#[Route]` attribute and its corresponding PHP method parameter must be camelCase and spelled out in full — `{organizationSlug}`, not `{organization_slug}` or `{orgSlug}`; `{projectSlug}`, not `{project_slug}`. gamache's `route.paramNotCamelCase` rule enforces this. Update `path()` calls in templates consistently.

**Entity mapping — use `{param:variable}` notation, documented in the `symfony-entity-route-mapping` skill.** When a route parameter corresponds to an entity, declare the mapping in the route path itself with `{param:variable}` and type-hint the controller parameter as the entity class — never inject a repository into a controller just to do a slug lookup. **The mechanics and pitfalls — `#[MapEntity]` forms, the `{param:alias}` request-attribute rename, raw-string semantics of `expr` variables, and parent-scoped multi-entity lookups (cross-tenant safety) — live in the `symfony-entity-route-mapping` skill. Invoke it before writing or changing any entity-resolving route.**

**Use `{slug:org}` (not `{organizationSlug}`) for the org route parameter** unless there is already another `slug` parameter in the same route. When two entities with the same field name appear in one route and would conflict, keep the full descriptive name for the second entity's parameter.

**Entity context belongs in the URL path, not the query string.** When a page operates within the context of an entity, that context must be a path parameter — not a query string. When a controller serves two structural contexts (e.g. "import a new project" vs. "attach a repo to an existing project"), add a second `#[Route]` attribute for the variant rather than switching on a query parameter.

## Enum backing values

No abbreviations; spell out every word in full (e.g. `case Implementation = 'implementation'`, not `'impl'`). Backing values are persisted in the database — renaming them later requires a data migration.

For authorization conventions (Voters, `#[IsGranted]`), see `project-authz`.

## Dependency injection conventions

**Service tagging — use `#[AutoconfigureTag]` on the interface, not `_instanceof` in `services.yaml`.**
```php
#[AutoconfigureTag('app.my_tag')]
interface MyInterface { ... }
```
Consume it with `#[AutowireIterator('app.my_tag')]`. Never add an `_instanceof:` block to `services.yaml`.

**Environment variables — use `#[Autowire(env: '...')]` on the constructor parameter, not `arguments:` in `services.yaml`.**
```php
#[Autowire(env: 'int:GITHUB_APP_ID')]
private readonly int $appId,
```
For container parameters use `#[Autowire(param: 'app.mailer.from_address')]`. The goal is zero explicit service definitions in `services.yaml` beyond the `App\:` resource block.

**Deployment-variable string literals belong in `services.yaml` parameters, not hardcoded in service classes.** When a service contains a string constant that represents a project-wide configuration choice — a path prefix, a branch prefix, a base directory, a sender name — extract it to `config/services.yaml` under `parameters:` with the `app.` prefix and inject with `#[Autowire(param: 'app.param_name')]`. Do not hardcode strings that a different deployment of this app might want configured differently. Litmus test: *if someone forked and rebranded this app, would they need to change this string?* If yes, it's a parameter.

**Service tag names must use the `app.` prefix** — e.g. `app.repository_provider`, not `my_app.repository_provider`. (gamache-enforced)

**`#[AsAlias]` for single-implementation interface binding.** When an interface has exactly one concrete implementation that should be autowired everywhere the interface is type-hinted, place `#[AsAlias(InterfaceName::class)]` on the concrete class — no `services.yaml` entry needed.
```php
#[AsAlias(ApiKeyResolver::class)]
final readonly class ConversationApiKeyResolver implements ApiKeyResolver { ... }
```
Use `#[AutoconfigureTag]` + `#[AutowireIterator]` only when multiple implementations must co-exist (e.g. a strategy collection). `#[AsAlias]` is the right tool for the one-implementation case.

**Configuration-backed utilities should be injectable services, not static helpers.** When a utility class depends on a configuration value (env var or container parameter), inject that value once in the constructor — not as a parameter to every static method. Callers inject the service rather than each re-wiring the same configuration themselves. Static helpers with configuration parameters proliferate `#[Autowire]` across the codebase and make unit testing harder.

```php
// ✓ — service owns the config; callers inject one dependency
final readonly class TopicBuilder
{
    public function __construct(
        #[Autowire(env: 'APP_URL')]
        private string $appUrl,
    ) {}

    public function forConversation(Uuid $id): string
    {
        return rtrim($this->appUrl, '/').'/conversations/'.(string) $id;
    }
}
```

## Custom static-analysis rules

Custom static-analysis rules live in the **`ubermuda/gamache`** package (`vendor/ubermuda/gamache/src/` — PHPStan, PHP CS Fixer, Rector, and TwigCsFixer rules), consumed as `dev-main` — not in this repo. When a check fires for something not in the standard PHPStan/Rector/TwigCsFixer docs, look there first; the rule class names in the error output match the class names in that package. New rules are added in the gamache repo (see "Gamache Checks" in `CLAUDE.md`). Run `just ci` to exercise all of these.

## Event listeners

Use `#[AsEventListener]` on single-event listeners (preferred over implementing `EventSubscriberInterface`):

```php
#[AsEventListener]
final readonly class SendWelcomeEmailOnUserRegistered
{
    public function __invoke(UserRegistered $event): void { ... }
}
```

**Location:** `src/Module/X/EventListener/` for module-scoped listeners. Root `src/EventSubscriber/` only for infrastructure-level framework listeners (e.g. profiler control, coverage collection).

**Naming:** `{Action}{EventName}Listener` for single-event listeners (e.g. `SendWelcomeEmailOnUserRegistered`).

**Cross-module side effects:** dispatch a domain event from the originating module and handle it in a listener in the receiving module. The listener imports the event class from the originating module — that cross-module import is acceptable (events are the intended public API). The listener must not import entities or services from the originating module beyond the event class.

## Service/ — shared module logic

When the same business logic is needed by more than one handler or controller within a module, extract it to `src/Module/*/Service/`. A `Service/` class has a single responsibility, is injected via constructor, and is autowired automatically.

Examples:
- `BranchNameBuilder` — builds git branch names from an `Issue`; used by both `CreateIssueHandler` and `CreateIssueWorkspaceController`
- `*EmailSender` — builds and dispatches a transactional email; used by multiple controllers

Do not create a `Service/` class for logic that is only ever called from one place — keep it in the handler or controller where it lives. The extraction threshold is **duplication, not size**.

## Email

Email is sent synchronously everywhere (`message_bus: false` in `mailer.yaml`) — no queue worker needed in development or production.

The sender address and name are defined as `config/services.yaml` parameters: `app.mailer.from_address` and `app.mailer.from_name`. Inject them with `#[Autowire(param: 'app.mailer.from_address')]`. Never hardcode `new Address('noreply@...', '...')` inside a service or controller.

**Email sender services:** Each transactional email type gets its own sender service (e.g. `VerificationEmailSender`, `PasswordResetEmailSender`) in `src/Module/*/Service/`. The service owns URL generation, template path, subject key, and mailer parameters. Controllers call `$this->fooEmailSender->send($user)` and must never contain email-building or sending logic.

`src/Messenger/Middleware/PlaywrightSyncEmailMiddleware.php` exists but is not wired up. When async email is needed, re-enable it by:
1. Remove `message_bus: false` from `mailer.yaml`
2. Uncomment `sync: 'sync://'` in `messenger.yaml`
3. Add the middleware back to `messenger.bus.default`
