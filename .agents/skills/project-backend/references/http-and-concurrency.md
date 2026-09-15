# Outbound HTTP and concurrent writes

## Outbound HTTP with Symfony HttpClient

Read this before you call an external service through `HttpClientInterface`. Symfony surfaces failures in a way that is easy to get wrong.

- `getStatusCode()` does not throw on 4xx or 5xx. It blocks on headers, so only a transport error surfaces there.
- `toArray()` and `getContent()` throw on a non-2xx response: a `ClientException` on 4xx, a `ServerException` on 5xx. Both are `HttpExceptionInterface`, not `TransportExceptionInterface`. `toArray()` also throws `DecodingExceptionInterface` on a non-JSON body.
- To degrade gracefully, gate on the status explicitly before you call `toArray()`, with `if ($status < 200 || $status >= 300) { return …; }`, and catch `TransportExceptionInterface|DecodingExceptionInterface`. A catch of `TransportExceptionInterface` alone lets a revoked-token 401 or a bad body abort the whole operation.
- For retryable work, classify `>= 500` as transient and keep it retryable, and classify 4xx as terminal. Never mark a 5xx failure terminal, because that strands recoverable work.

## Read, check, write under a row lock

Read this before a handler does read, then check, then write, where a concurrent request could violate a uniqueness or state precondition. Examples: create-if-absent, consumption of a single-use token, "a user has at most one X". A bare `if (already exists) throw; else create;` is a TOCTOU race that two simultaneous requests both pass. Run the check and the write under a pessimistic row lock:

```php
return $this->em->wrapInTransaction(function () use ($command): Foo {
    $this->em->lock($entity, LockMode::PESSIMISTIC_WRITE);
    // lock() acquires the row but does NOT refresh the in-memory entity — without
    // this refresh a racing commit's change is unseen and the check re-passes.
    $this->em->refresh($entity);
    if (/* precondition already met */) {
        throw new DomainErrors([...]);
    }
    // ... create / consume, then flush ...
});
```

The concurrency itself is not unit-testable here. `dama/doctrine-test-bundle` wraps each test in a single connection's transaction, so two overlapping database transactions cannot be expressed. Verify the lock by code review. Add a sequential test for the observable single-use or precondition behaviour, where the first call succeeds and the second is rejected, as the regression guard, and note the limitation in a comment.
