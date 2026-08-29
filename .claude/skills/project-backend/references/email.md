# Email

Read this before you send, build or test an email.

## Delivery is asynchronous

`MailerInterface::send()` enqueues a `Symfony\Component\Mailer\Messenger\SendEmailMessage` on the `async` transport, routed in `messenger.yaml`. The messenger worker (`messenger:consume scheduler_default async`) performs the delivery. The dev `worker` compose service already consumes both transports. In production the worker runs as its own container from the same image with the command overridden. Never run it inside the web container's supervisord. A failed delivery retries 3 times, then lands in the `failed` transport.

## Sender parameters

The sender address and name are `config/services.yaml` parameters: `app.mailer.from_address` and `app.mailer.from_name`. Inject them with `#[Autowire(param: 'app.mailer.from_address')]`. Never hardcode `new Address('noreply@...', '...')` in a service or a controller.

## Sender services

Each transactional email type gets its own sender service in `src/Module/*/Service/`, for example `VerificationEmailSender` and `PasswordResetEmailSender`. The service owns URL generation, the template path, the subject key and the mailer parameters. Controllers call `$this->fooEmailSender->send($user)`. A controller never contains email-building or sending logic.

## Tests

A request enqueues mail instead of sending it. Use `assertQueuedEmailCount()` in a WebTestCase. Never use `assertEmailCount()`, which counts sent mail and sees zero. `getMailerMessage()` still returns the queued message for header and recipient assertions.

## Worktrees

The shared dev `worker` service consumes only the main checkout's database, so a worktree's own `app_wt_<slug>` queue has no consumer. This affects manual mail testing against a worktree. Start a consumer from the worktree with `bin/worktrees/compose-exec.sh bin/console messenger:consume scheduler_default async`, and stop it when you are done. This does not affect e2e: `PlaywrightSyncMiddleware` handles every message dispatched under `X-Playwright` inline, so the suite has no queue to drain.

## Worker staleness

Email rendering runs in the long-lived worker, which `--time-limit=3600` recycles every hour. After you change an email template or a sender service in dev, restart the worker with `docker compose restart worker` from the main checkout, or wait for the recycle. Otherwise the delivered mail renders the stale code.
