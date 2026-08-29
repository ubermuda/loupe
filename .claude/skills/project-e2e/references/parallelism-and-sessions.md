# Parallelism and PHP sessions

Tests run `fullyParallel: false`: parallel at the file level. Each spec file that needs an authenticated session calls `createTest(credentials)` from `e2e/tests/fixtures.ts` with its own dedicated user. The worker fixture logs in as that user, registering and verifying it through Mailpit on the first run. Each file therefore has its own PHP session. That avoids CSRF token conflicts, and stops a mutation to one user (password re-hash, displayName change) invalidating another file's session. There is no shared setup phase: the fixture creates users lazily, on the file's first run.

Mailpit tests (register, delete account) search by recipient address instead of clearing all messages, so they do not race.

**Mailpit is per-worktree but never cleared. Assume dirty inboxes.** Each worktree has its own sidecar (`MAILPIT_URL`, exported by `just e2e`); a run with an explicit `E2E_BASE_URL` gets the shared instance. Recipient search is not enough when a previous run sent an identical-subject email to that address. Capture the newest matching message id *before* the triggering action, then use `getEmailWithSubject(request, address, subject, { afterId })` so the poll waits for a *fresh* message. Symptom of getting this wrong: a link from another worktree's stale email, so a wrong-host 404 that looks like an app bug.

**Helpers in `e2e/tests/helpers.ts`:**
- `registerAndVerify(page, request, credentials)` fills the registration form, polls Mailpit for the verification link, and navigates to it
- `fetchVerificationUrl(request, email)` polls Mailpit until a verification email arrives for that address, then returns the URL

**Global setup (`e2e/global-setup.ts`)** runs once before any worker starts. Use it for one-time prerequisites all tests depend on. Do not use `test.beforeAll`: its `{ timeout }` option is unreliable when `test` comes from `createTest`, and workers racing to do the same setup cause flakiness.

To add an authenticated spec file, call `createTest({ email: 'e2e-your-feature@example.com', password: 'e2e_password_123', name: '...' })` at the top of the file. The user is created on the first run; there is no central registry to update.

**Session invalidation risk:** any controller that writes back to the `User` entity (`displayName`, `password`) makes Symfony see a changed serialized user and invalidate that session on the next request. Each file has its own user, so this stays contained to that file. Use `test.use({ storageState: {} })` plus a `beforeEach` login whenever:
- a `beforeAll` creates a second context using `workerStorageState` before an authenticated test (that context can leave the server-side session in an unexpected state)

**Warning:** `test.use({ storageState: { cookies: [], origins: [] } })` severs the `workerStorageState` fixture from Playwright's dependency graph, so the user is never registered. With this pattern, reference `workerStorageState` in `beforeEach`; `void workerStorageState` is enough to make the fixture run.

Tests that mutate the user's password (like `can change password`) use a throwaway timestamped user from `registerAndVerify`, not the shared worker user. This avoids a broken DB if the test fails partway through.
