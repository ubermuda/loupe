<?php

declare(strict_types=1);

use Gamache\Check\CommentBudgetCheck;
use Gamache\Check\FormTypeTranslationKeysCheck;
use Gamache\Check\MessengerRoutingCheck;
use Gamache\Check\NoArbitraryValuesCheck;
use Gamache\Check\NoTodosCheck;
use Gamache\Check\PageTitleBrandNameCheck;
use Gamache\Check\SelfContainedCommentsCheck;
use Gamache\Check\ServicesYamlCheck;
use Gamache\Check\ServiceTagNamesCheck;
use Gamache\Check\Severity;
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
            'deploy/compose.prod.yaml',
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
