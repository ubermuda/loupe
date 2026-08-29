# Admin list and edit conventions

Read this before you add or change an admin list, edit, delete or toggle action.

An admin list page must preserve filter and sort state when the user clicks a row's Edit, Delete or Toggle action, so that the form's Back and Cancel links return to the same filtered view.

`App\Module\Admin\Listing\AdminReturnTo` handles the open-redirect guard. It accepts only `/admin/`-prefixed URLs and logs `admin.list.return_to_rejected` on rejection.

- On the list, build the Edit link with `returnTo: app.request.requestUri` in the query params: `path('app_admin_<entity>_edit', { id: ..., returnTo: app.request.requestUri })`. For Delete (modal) and Toggle, add `<input type="hidden" name="returnTo" value="{{ app.request.requestUri }}">` between `form_start` and `form_end`.
- In the Edit controller, inject `AdminReturnTo`, validate `$request->query->get('returnTo')`, and redirect to `app_admin_<entity>_edit` with `id` plus the validated `returnTo` as a query param. The user lands back on the edit page and can keep iterating, while Back and Cancel still go to the filtered list. Never redirect directly to `returnTo` on an Edit save.
- In the Delete and Toggle controllers, validate `$request->request->get('returnTo')` from the POST body and redirect there on success, otherwise to the list route. The entity is gone, or the user wanted to stay on the list.
- In the edit template, read it as `{% set returnTo = app.request.query.get('returnTo') %}` and use `(returnTo and returnTo starts with '/admin/') ? returnTo : path('app_admin_<entity>_list')` for both the Back header link and the Cancel button.
