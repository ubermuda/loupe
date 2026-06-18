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
