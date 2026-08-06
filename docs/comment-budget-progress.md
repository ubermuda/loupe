# Branch progress: chore/comment-budget-check

Scratch tracker for this branch only. **Delete before opening the PR.**

## Done

- gamache PR #31 — `CommentBudgetCheck` (advisory, all comment syntaxes).
- Pinned gamache to `dev-feat/comment-budget-check#75ebfa3 as dev-main`.
- Registered `CommentBudgetCheck` + `SelfContainedCommentsCheck` in `gamache.php`.
- CLAUDE.md: removed a duplicated Gamache-checks table and paragraph; added the
  comment-length bullet.
- `project-comments` skill: documented the new check and its advisory nature.

## In flight — 78 phpstan errors surfaced by the pin bump

Owner chose to fix all of them in this branch (not baseline them).

`controller.directStateAccess` × 77 across 33 files, plus one
`assignment.selfAssigningTernary`. Each controller needs a read handler +
view object in the module's `Command/` dir per `project-command-handler`.

### Remaining files

- [ ] src/Controller/ShowHealthController.php (1)
- [ ] src/Module/Account/Controller/Admin/ListWaitlistController.php (1)
- [ ] src/Module/Account/Controller/ConfirmAccountDeletionController.php (1)
- [ ] src/Module/Account/Controller/Dev/RegisterAndVerifyController.php (2)
- [ ] src/Module/Account/Controller/Dev/ResetDatabaseController.php (3)
- [ ] src/Module/Account/Controller/HomeController.php (1)
- [ ] src/Module/Account/Controller/RegisterController.php (2)
- [ ] src/Module/Account/Controller/ResetPasswordController.php (1)
- [ ] src/Module/Account/Controller/RevokeApiTokenController.php (1)
- [ ] src/Module/Account/Security/ApiTokenAuthenticator.php (1, selfAssigningTernary)
- [ ] src/Module/Admin/Controller/Dev/E2eFeatureFlagController.php (3)
- [ ] src/Module/Billing/Controller/Dev/SeedBillingStateController.php (5)
- [ ] src/Module/Project/Controller/CreateProjectController.php (4)
- [ ] src/Module/Project/Controller/ListProjectsController.php (4)
- [ ] src/Module/Review/Controller/AddCommentController.php (2)
- [ ] src/Module/Review/Controller/DeleteCommentController.php (1)
- [ ] src/Module/Review/Controller/Dev/GetReviewStateController.php (2)
- [ ] src/Module/Review/Controller/Dev/SeedDocumentController.php (3)
- [ ] src/Module/Review/Controller/DiffDocumentVersionsController.php (3)
- [ ] src/Module/Review/Controller/ListDocumentsController.php (6)
- [ ] src/Module/Review/Controller/ReplyToCommentController.php (1)
- [ ] src/Module/Review/Controller/ResolveCommentController.php (1)
- [ ] src/Module/Review/Controller/SelectDecisionOptionController.php (2)
- [ ] src/Module/Review/Controller/ShowDocumentController.php (5)
- [ ] src/Module/Review/Controller/StrikePassageController.php (2)
- [ ] src/Module/Review/Controller/SuggestRewordingController.php (2)
- [ ] src/Module/SiteReview/Controller/Admin/ListSiteReviewOutboxController.php (3)
- [ ] src/Module/SiteReview/Controller/Api/ListSitesController.php (1)
- [ ] src/Module/SiteReview/Controller/Api/ShowDraftCommentsController.php (1)
- [ ] src/Module/SiteReview/Controller/Api/StreamCredentialsController.php (1)
- [ ] src/Module/SiteReview/Controller/Dev/SiteReviewHarnessController.php (8)
- [ ] src/Module/SiteReview/Controller/ListProjectOutboxController.php (1)
- [ ] src/Module/SiteReview/Controller/ShowSiteReviewController.php (3)

## Still to do after that

- The comment cleanup itself: 144 over-budget blocks reported by
  `vendor/bin/gamache`. Legitimate long headers (`compose.prod.yaml`, `.env`,
  Twig file headers) get `@comment-budget-ignore`, not a rewrite.
- Repoint the gamache pin to `dev-main#<merge-sha>` once PR #31 merges, and drop
  the `as dev-main` alias.
