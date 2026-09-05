# Stateless CSRF tokens for hand-rolled forms

Read this before you write a plain HTML `<form>` with a manual `_csrf_token` field, and before you write any POST action that submits no fields.

## Which shape for a fieldless POST action

Both shapes stay. Pick on one question: what must happen when the token is stale or forged?

- A 403 is the right answer. Use the `#[CsrfToken]` attribute on the controller with a hand-written `<form>`, and follow "The three parts" below. This is the default, and the norm across `src/`. It costs one id in `config/packages/csrf.yaml`.
- A 403 is too blunt. Use a real Symfony form. The form component issues and checks the token, so the action needs no id in `csrf.yaml`. The controller reads `isSubmitted() && isValid()` and redirects with an error flash. It costs a `FormType`, a Twig function that builds the form, and `createNamed` plumbing. `Module/Review/Form/ArchiveDocumentFormType` is the one site that made this call, for the archive control on the documents list.

`ValidateCsrfTokenListener` throws `AccessDeniedException` before the action runs, so the attribute always gives a 403. A controller cannot soften it.

Build a per-row form with `FormFactoryInterface::createNamed('<prefix>_'.$id, …)`, and rebuild it under the same name in the controller. Without a unique name, every row renders the same DOM id on its hidden token input. Extract the name to a shared `public static` helper, as `ReviewExtension::archiveFormName()` does.

## The hybrid shape, and why to avoid it in new code

These form types set `'csrf_protection' => false` and lean on the controller's `#[CsrfToken]` attribute instead: `SubmitReviewFormType`, `SuspendUserFormType`, `DeleteUserFormType` and `InviteOldestWaitlistFormType`. Each binds a field that its template hand-writes rather than renders through `form_widget()`, so the form's own `_token` input never reaches the page. Disabling the form's CSRF stops it rejecting the submission.

Do not copy this shape for new code. Hand-render the form's own `_token` instead, as "Hand-rolling a POST to a Form-component endpoint" below shows, and leave `csrf_protection` on. The action then carries one token and needs no `csrf.yaml` id. This is guidance, and no check enforces it. The existing sites work, so leave them alone until other work touches them.

## The three parts

1. Register the token ID in `config/packages/csrf.yaml` under `stateless_token_ids`:
   ```yaml
   stateless_token_ids:
       - 'my-action'
   ```
2. Declare the check with the `#[CsrfToken]` attribute on the controller class. Never call `isCsrfTokenValid()` inline. `ValidateCsrfTokenListener` validates the token on `kernel.controller`, before the action runs, and throws a 403 `AccessDeniedException`.
   ```php
   use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

   #[CsrfToken('my-action')]
   class DoMyActionController extends AppController
   ```
3. Output the signed token in the template with the `csrf_token()` Twig function. Never write a literal string.
   ```twig
   <input type="hidden" name="_csrf_token" value="{{ csrf_token('my-action') }}">
   ```

A literal string fails because `SameOriginCsrfTokenManager::isTokenValid()` rejects any value shorter than 24 characters that is not the cookie sentinel `"csrf-token"`. A literal token ID such as `"delete-project"` (14 chars) gives a 403 on every production submission, while the tests pass because the test environment disables CSRF.

Examples: `ResendVerificationEmailController`, `config/packages/csrf.yaml`. The `#[CsrfToken]` attribute and `ValidateCsrfTokenListener` live in the `ubermuda/symfony-extra` package (`Ubermuda\SymfonyExtra\Csrf\`), not under `src/`.

## Hand-rolling a POST to a Form-component endpoint

Every Form-component form validates `_token` against the global token ID `submit`, which `csrf.yaml` sets in `framework.form.csrf_protection.token_id`. It does not validate against the form's block prefix. The block-prefix fallback exists only when no global ID is configured. Write the field like this:

```twig
<input type="hidden" name="<block_prefix>[_token]" value="{{ csrf_token('submit') }}" data-controller="csrf-protection">
```

The `data-controller` attribute is load-bearing. Without it, `csrf_protection_controller.js` never double-submits, and the `SameOriginCsrfTokenManager` downgrade check rejects the POST for any session that previously double-submitted, which is every password-login session.
