---
name: project-testing
description: Use when writing or modifying PHPUnit tests, unit tests, integration tests, WebTestCase controller tests, or mock setup.
---

# Testing, PHPUnit patterns

## WebTestCase, mocking services across multiple requests

Call `$client->disableReboot()` whenever a mock must survive a GET then POST sequence. Symfony shuts the kernel down after each `$client->request()` call, which discards any `getContainer()->set(ServiceClass::class, $mock)` override. The next `submitForm()` call boots a fresh kernel and uses the real service. Add `disableReboot()` before the first request in any test that both mocks a service and makes two or more HTTP requests.

```php
$client = static::createClient();
$client->disableReboot();          // keep mock alive across GET + submitForm
$this->mockExternalService($data);
$client->request('GET', '/confirm?id=123');
$client->submitForm('Save', [...]);
```

You need this only when the handler, and not just the controller, calls the mocked service. Moving an API call from the controller's GET prefill into the command handler's POST flow is the usual trigger.

## Controller integration tests, assert DB state

For POST endpoints (create, update, delete, attach, detach), assert the database outcome, not only the redirect. A test that asserts only `assertResponseRedirects(...)` tests routing. Assert after you follow the redirect.

```php
$em->clear(); // discard identity-map cache
$fetched = $em->find(Project::class, $project->id);
self::assertNull($fetched); // for a delete
// or
self::assertNotNull($fetched->repository); // for an attach
```

### Stale identity-map state in test setup

A test that persists the *inverse* side of a relationship does not update the owning entity. `new GitHubRepository($project, ...)` sets `repo.project = $project`, but leaves `project.repository = null` in memory. If a later `$client->request()` makes the controller load that owning entity through `MapEntity`, Doctrine returns the cached object instead of a fresh SQL fetch. The property is still null, so any handler that reads it silently does nothing.

Call `$em->clear()` after all setup writes and before the first `$client->request()`.

```php
$em->persist($repo);
$em->flush();
$em->clear(); // force fresh fetch during the subsequent HTTP request

$client->loginUser($user);
$client->request(...);
```

## PHPUnit mock discipline

PHPUnit 13 emits an `N` ("PHPUnit Notice") for every `createMock()` call that never configures `expects()`.

- Use `createStub()` for a dependency that only needs return values, with no call verification. Declare the property as `Foo&Stub`.
- Use `createMock()` only when you verify calls with `expects($this->once())` or similar. Declare the property as `Foo&MockObject`.

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

Update the `use` import to match: `use PHPUnit\Framework\MockObject\Stub;` or `use PHPUnit\Framework\MockObject\MockObject;`. Watch for `N` characters in the test output and for the "OK, but there were issues!" summary line. These are framework-level notices, separate from PHP `E_NOTICE`.

## WebTestCase, assert on stable hooks, not markup structure

`WebTestCase` crawler assertions couple to the rendered markup, so a visual refactor breaks them silently. `assertCount(1, $crawler->filter('tbody tr'))` fails the moment a `<table>` becomes `.lp-doc-row` divs. Prefer stable hooks over structural or positional selectors.

- ✓ `$crawler->filter('[data-document-id]')`, `'.lp-doc-row'`, `'[data-controller="…"]'`: a `data-*` attribute, a route-bound `id`, or a `.lp-*` component class.
- ✗ `'tbody tr'`, `'table td'`, `'div > span:first-child'`: tree position and raw HTML tags that a template redesign moves.

This mirrors the Playwright selector-scoping rule in `project-e2e`. Assert on intent-carrying hooks that the markup is unlikely to drop.

## A test that cannot fail is worse than no test

Negative assertions say "no query was issued", "no email was sent", or "this list
is empty". They pass trivially when the operation under test never ran at all.
Assert first that it happened, *then* that the specific effect is absent.

```php
// Guard: without this, the assertion below also passes on a request that
// ran no queries whatsoever, proving nothing about the skip.
self::assertNotEmpty($statements);
self::assertSame([], array_values(array_filter(
    $statements,
    static fn (string $sql): bool => str_contains($sql, 'UPDATE api_tokens'),
)));
```

A test for an optimization must fail when the optimization is removed. Verify
that explicitly: temporarily revert the guard, watch the test fail, restore it.
Say in the commit message that you did.

Collected-query assertions use the profiler, which is off by default in `test`
(`framework.profiler.collect: false`). Call `$client->enableProfiler()` before
the request, then `$client->getProfile()`. Narrow the result with
`assertInstanceOf(Profile::class, ...)`. `assertNotFalse()` does not narrow
`null`, so phpstan rejects the subsequent method call.

## Rate limiters

Every rate limiter needs a `when@test` high-limit override, and you test its throttle with a hand-built factory. When you add a limiter under `framework.rate_limiter` in `config/packages/framework.yaml`, add a matching entry under the `when@test` block of that file with a high limit (for example 1000). The rest of the suite is then never throttled. Test the throttling in isolation with a low-limit factory built directly.

```php
$factory = new RateLimiterFactory(
    ['id' => '<name>', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'],
    new InMemoryStorage(),
);
```

## Terms acceptance in a WebTestCase

A fixture `User` has not accepted the terms, so `RequireTermsAcceptanceListener`
diverts an authenticated HTML request to the interstitial. The symptom is an
unexplained `302` to `/terms/accept` in a test that has nothing to do with
terms. Readers usually mistake it for a broken redirect or a lost session.

Stamp the fixture.

```php
$user = new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'x');
AcceptedTerms::stamp($user, static::getContainer());
$em->persist($user);
```

`App\Tests\Support\AcceptedTerms` reads `app.terms.version`, so a bump to that
parameter does not invalidate the fixtures.

The gate stays live in the test environment on purpose. Making it inert costs
three lines instead of a stamp per fixture, but the rest of the suite would then
run a configuration production never uses, and a regression where the gate fires
when it should not would pass unnoticed. The tests that *are* about the gate,
`RequireTermsAcceptanceListenerTest` and `AcceptTermsControllerTest`, build their
users without the stamp.
