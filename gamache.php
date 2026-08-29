<?php

declare(strict_types=1);

use Gamache\Check\CommentBudgetCheck;
use Gamache\Check\DeploymentConfigParityCheck;
use Gamache\Check\FormTypeTranslationKeysCheck;
use Gamache\Check\MessengerRoutingCheck;
use Gamache\Check\NoArbitraryValuesCheck;
use Gamache\Check\NoTodosCheck;
use Gamache\Check\PageTitleBrandNameCheck;
use Gamache\Check\SelfContainedCommentsCheck;
use Gamache\Check\ServicesYamlCheck;
use Gamache\Check\ServiceTagNamesCheck;
use Gamache\Check\Severity;
use Gamache\Check\SkillReferenceCheck;
use Gamache\Check\TranslationCheck;
use Gamache\Check\TranslationParityCheck;
use Gamache\Check\TurboStreamTargetsCheck;
use Gamache\Check\XlfPluralizationCheck;
use Gamache\Config\GamacheConfig;
use PhpCsFixer\FixerConfiguration\FixerOptionBuilder;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

return (new GamacheConfig())->registerChecks([
    new ServicesYamlCheck(),
    new ServiceTagNamesCheck(),
    new MessengerRoutingCheck(),
    new NoArbitraryValuesCheck(),
    new NoTodosCheck(),
    new SelfContainedCommentsCheck(),
    new SkillReferenceCheck(
        /*
         * Replaces the check's defaults rather than adding to them, so this
         * restates them and appends the top-level directories this project has
         * that they do not cover. `src/` and `tests/` stay out: skills cite
         * paths there to illustrate naming, not to point at real files.
         */
        pathPrefixes: [
            'assets/',
            'bin/',
            'cli/',
            'config/',
            'docker/',
            'docs/',
            'e2e/',
            'migrations/',
            'public/',
            'templates/',
            'terraform/',
            'translations/',
        ],
    ),
    new DeploymentConfigParityCheck(
        moduleProvidedEnvKeys: [
            /*
             * base_env in terraform-digitalocean-symfony-app, at the ref
             * terraform/main.tf pins. Only some of the module's arguments become
             * environment variables, so this cannot be derived from the argument
             * list — re-read the module when changing the pin.
             */
            'APP_ENCRYPTION_KEY',
            'APP_ENV',
            'APP_SECRET',
            'APP_SHARE_DIR',
            'DATABASE_URL',
            'DEFAULT_URI',
            'MAILER_DSN',
            'MERCURE_JWT_SECRET',
            'MERCURE_PUBLIC_URL',
            'MERCURE_URL',
            'MESSENGER_TRANSPORT_DSN',
        ],
        ignoredAppEnvKeys: [
            // Compose reads this one itself; it never reaches the app.
            'COMPOSE_PROJECT_NAME',
            // The committed .env value is the production answer, and matches the
            // module's own default.
            'MESSENGER_TRANSPORT_DSN',
            // Development tooling: the IDE link, Symfony's own proxy and sendfile
            // overrides, the PHPUnit database suffix, the dump server and the
            // per-worktree database suffix.
            'SYMFONY_IDE',
            'SYMFONY_TRUSTED_PROXIES',
            'SYMFONY_TRUST_X_SENDFILE_TYPE_HEADER',
            'TEST_TOKEN',
            'VAR_DUMPER_SERVER',
            'WORKTREE_DB_SUFFIX',
        ],
    ),
    new CommentBudgetCheck(
        /*
         * Beyond the default Symfony layout, the files where comment essays
         * actually accumulate here: the justfile, the compose topologies and
         * the dotenv template, which together hold more over-budget blocks
         * than src/ does.
         */
        patterns: [
            'src/**/*.php',
            'tests/**/*.php',
            'config/**/*.yaml',
            'templates/**/*.twig',
            'assets/**/*.js',
            'assets/**/*.css',
            'e2e/**/*.ts',
            'justfile',
            'compose.yaml',
            'docker/compose/prod.yaml',
            '.env',
        ],
        // Binding rather than advisory: an advisory check's green result says
        // nothing, and three over-budget blocks once shipped through three
        // consecutive green gates here. `@comment-budget-ignore` marks the
        // blocks that have earned their length.
        severity: Severity::Error,
    ),
    new FormTypeTranslationKeysCheck(),
    new TurboStreamTargetsCheck(),
    new XlfPluralizationCheck(),
    new PageTitleBrandNameCheck(),
    new TranslationParityCheck(),
    new TranslationCheck(
        /*
         * Strings passed to these constructors/methods are not user-facing prose.
         * Add entries as 'Namespace\Class::method' (method call) or
         * 'Namespace\Class' (constructor call).
         */
        ignoredCallSites: [
            // Symfony controller factory methods
            AbstractController::class.'::createNotFoundException',
            AbstractController::class.'::createAccessDeniedException',

            // PHP CS Fixer tool-framework strings (fixer descriptions and option builders)
            FixerDefinition::class,
            FixerOptionBuilder::class,

            // Rector tool-framework strings (rule descriptions)
            RuleDefinition::class,
        ],
        ignoreExceptionClasses: true,
        /*
         * Files whose FQCN matches are skipped entirely — these namespaces are
         * guaranteed to contain no user-facing prose.
         * Supports * (single segment) and ** (any depth).
         */
        ignoredSourceNamespaces: [
            'App\\**\\Command\\*',    // command handlers and console commands
            'App\\**\\Repository\\*', // Doctrine repositories (DQL/SQL only)
        ],
        /*
         * String arguments to PHP attributes whose class FQCN matches are skipped.
         * Covers framework config strings that are never user-facing prose.
         */
        safeAttributeNamespaces: [
            'Symfony\\Bridge\\Doctrine\\Attribute\\*',   // #[MapEntity(expr: '...')]
            'Doctrine\\ORM\\Mapping\\*',                 // #[JoinColumn(onDelete: 'SET NULL')]
            'Symfony\\Component\\Routing\\Attribute\\*', // #[Route(...)]
            'Symfony\\Component\\Console\\Attribute\\*', // #[AsCommand(description: '...')]
        ],
        /*
         * Twig filter/function names whose string arguments are never translatable.
         */
        safeTwigFunctions: [
            'date', // e.g. {{ value|date('M j, Y') }}
        ],
    ),
]);
