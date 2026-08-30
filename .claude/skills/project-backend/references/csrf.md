# Stateless CSRF tokens for hand-rolled forms

Read this before you write a plain HTML `<form>` with a manual `_csrf_token` field.

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
