# The session and its lock

Read this before you add a route that a browser fetches in the background, or code that writes the session.

## Three modes

`App\Session\ReadOnlyAwareSessionHandler` stores sessions in the `sessions` table. It picks a mode for each request when the session opens.

| Request | Lock | Session writes |
|---|---|---|
| A route marked read-only | none | dropped |
| Any other GET or HEAD | none | kept, and the last write wins |
| POST, PUT, PATCH or DELETE | a row lock until the response | kept |

A locked request holds `SELECT ... FOR UPDATE` on the session row. Every other locked request of the same user waits for it. Two different users never wait for each other.

## Mark a background route read-only

Mark a route when a Stimulus controller, a Turbo frame reload or a live update fetches it. Otherwise the route has a lock-free read and keeps its writes. A missing mark on such a route gives no error.

Use `true` when only background code calls the route:

```php
#[Route(
    '/projects/{id:project}/inbox/open-count',
    name: 'app_project_inbox_open_count',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
```

Use the frame id when the same route also serves the full page:

```php
defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => 'board-frame'],
```

The request is then read-only only when its `Turbo-Frame` header equals that id. A normal page load keeps its writes, so it still consumes the flash messages that a redirect set. `app_project_board` and `app_project_worker_runs` use this form.

Give a list of ids when the reload fetches more than one frame, such as `['activity-frame', 'activity-count']` on `app_project_activity`. Name every frame that the refresh reloads.

A background POST can take the mark too. `app_mercure_authorize` is the example. Every Mercure reconnect calls it, and a hub that is down makes each tab call it again and again. With the row lock, each call blocks the next click of the same user. It also blocks the session write at the end of each GET. A POST qualifies only when it changes nothing in the session, as the list below says.

Put the mark on the one route that the reload fetches. A controller with two `#[Route]` attributes needs the mark on each route that background code calls, and on no other.

## What a read-only route must not do

The handler drops every write of a read-only request, and it reports no error. So a marked route must not:

1. Add a flash message, or depend on a flash being removed.
2. Store a value in the session, such as a filter or a wizard step.
3. Sign a user in, or log a user out.
4. Extend the session. A tab that only reloads in the background reaches the idle limit and signs the user out.

CSRF tokens are stateless in this project (`config/packages/csrf.yaml`), so a marked route can render a form.

## Write the session from a POST

An unmarked GET reads with no lock, and it writes the whole session row at the end. A GET that overlaps a POST of the same user can therefore overwrite what the POST wrote. So put every change to session state in a POST, PUT, PATCH or DELETE action, which holds the lock. A GET that only reads the session is safe.

## Tests

The `test` environment uses `session.storage.factory.mock_file`, so a `WebTestCase` never runs this handler. A controller test cannot show that a mark works. `tests/Session/ReadOnlyAwareSessionHandlerLockTest.php` proves the lock modes against Postgres. `tests/Session/ReadOnlyAwareSessionHandlerTest.php` covers the mode choice. Extend these when you change the handler.

## Tracing

Each session read runs in a Sentry span `session.read`. Its data carries `session.locked` and `session.mode`. The span includes the wait for the row lock. A long span with `session.locked` true shows a request that waited for another request of the same user.
