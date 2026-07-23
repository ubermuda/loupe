---
name: project-testing
description: Use when writing or modifying PHPUnit tests — unit tests, integration tests, WebTestCase controller tests, or mock setup.
---

# Testing — PHPUnit Patterns

## WebTestCase — mocking services across multiple requests

**Call `$client->disableReboot()` whenever a mock must survive a GET → POST sequence.** Symfony shuts down the kernel after each `$client->request()` call by default, which discards any `getContainer()->set(ServiceClass::class, $mock)` override. A subsequent `submitForm()` call boots a fresh kernel and uses the real service. Add `disableReboot()` before the first request in any test that both mocks a service AND makes two or more HTTP requests:

```php
$client = static::createClient();
$client->disableReboot();          // keep mock alive across GET + submitForm
$this->mockExternalService($data);
$client->request('GET', '/confirm?id=123');
$client->submitForm('Save', [...]);
```

This is only needed when the handler (not just the controller) calls the mocked service — e.g. after moving an API call from the controller's GET prefill into the command handler's POST flow.

## Controller integration tests — assert DB state

For POST endpoints (create, update, delete, attach, detach), controller integration tests must assert the database outcome — not just the redirect. After following the redirect:

```php
$em->clear(); // discard identity-map cache
$fetched = $em->find(Project::class, $project->id);
self::assertNull($fetched); // for a delete
// or
self::assertNotNull($fetched->repository); // for an attach
```

A test that only asserts `assertResponseRedirects(...)` is not a full integration test — it only tests routing.

**Doctrine identity-map stale state in test setup:** When a test persists an entity that is the *inverse* side of a relationship (e.g. `new GitHubRepository($project, ...)` sets `repo.project = $project` but leaves `project.repository = null` in memory), the in-memory owning entity is not updated automatically. If a subsequent `$client->request()` causes the controller to load that owning entity via `MapEntity`, Doctrine returns the stale cached object from the identity map — not a fresh SQL fetch — so the relationship property is still null, and any handler that reads it will silently no-op.

**Fix:** call `$em->clear()` after all setup writes and before the first `$client->request()`:

```php
$em->persist($repo);
$em->flush();
$em->clear(); // force fresh fetch during the subsequent HTTP request

$client->loginUser($user);
$client->request(...);
```

## PHPUnit mock discipline

PHPUnit 13 emits an `N` ("PHPUnit Notice") for every `createMock()` call where no `expects()` assertion is ever configured.

- Use **`createStub()`** for any dependency that only needs return value configuration — no call verification. Declare the property as `Foo&Stub`.
- Use **`createMock()`** only when verifying method calls via `expects($this->once())` etc. Declare the property as `Foo&MockObject`.

```php
// ✗ createMock() without expects() → PHPUnit 13 notice
private EntityManagerInterface&MockObject $em;
$this->em = $this->createMock(EntityManagerInterface::class);

// ✓ pure stub — only return values, no call-count assertion
private EntityManagerInterface&Stub $em;
$this->em = $this->createStub(EntityManagerInterface::class);

// ✓ mock with explicit expectation
private UserRepository&MockObject $repo;
$this->repo = $this->createMock(UserRepository::class);
$this->repo->expects($this->once())->method('findOneByEmail')->willReturn(null);
```

Update the `use` import to match: `use PHPUnit\Framework\MockObject\Stub;` or `use PHPUnit\Framework\MockObject\MockObject;`. Watch for `N` characters in test output and the "OK, but there were issues!" summary line — these are framework-level notices separate from PHP `E_NOTICE`.

## WebTestCase — assert on stable hooks, not markup structure

`WebTestCase` crawler assertions are coupled to the rendered markup, so a visual refactor silently breaks them — `assertCount(1, $crawler->filter('tbody tr'))` fails the moment a `<table>` becomes `.bp-doc-row` divs. Prefer **stable hooks** over structural/positional selectors:

- ✓ `$crawler->filter('[data-document-id]')`, `'.bp-doc-row'`, `'[data-controller="…"]'` — a `data-*` attribute, a route-bound `id`, or a `.bp-*` component class.
- ✗ `'tbody tr'`, `'table td'`, `'div > span:first-child'` — tree position and raw HTML tags that a template redesign will move.

This mirrors the Playwright selector-scoping rule in `project-e2e`: assert on intent-carrying hooks the markup is unlikely to drop.

## Rate limiters

**Every rate limiter needs a `when@test` high-limit override, and its throttle is tested with a hand-built factory.** When you add a limiter under `framework.rate_limiter` in `config/packages/framework.yaml`, add a matching entry under the file's `when@test` block with a high limit (e.g. 1000) so the rest of the suite is never throttled. Test the throttling in isolation with a low-limit factory built directly:

```php
$factory = new RateLimiterFactory(
    ['id' => '<name>', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'],
    new InMemoryStorage(),
);
```
