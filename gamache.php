<?php

declare(strict_types=1);

use Gamache\Check\FormTypeTranslationKeysCheck;
use Gamache\Check\MessengerRoutingCheck;
use Gamache\Check\NoArbitraryValuesCheck;
use Gamache\Check\NoTodosCheck;
use Gamache\Check\PageTitleBrandNameCheck;
use Gamache\Check\ServicesYamlCheck;
use Gamache\Check\ServiceTagNamesCheck;
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
