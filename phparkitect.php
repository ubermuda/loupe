<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\DependsOnlyOnTheseNamespaces;
use Arkitect\Expression\ForClasses\NotDependsOnTheseNamespaces;
use Arkitect\Expression\ForClasses\NotResideInTheseNamespaces;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;

/*
 * Module boundary rules.
 *
 * Add a rule for each inter-module dependency you want to forbid.
 * The allowed dependency directions should form a directed acyclic graph (DAG).
 *
 * Example — to make App\Module\Account a leaf that depends on nothing:
 *
 *   $config->add($src,
 *       Rule::allClasses()
 *           ->that(new Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces('App\Module\Account'))
 *           ->should(new NotDependsOnTheseNamespaces(['App\Module\OtherModule']))
 *           ->because('Account is a leaf module — it must not depend on any other module'),
 *   );
 */
return static function (Config $config): void {
    // The whole of src/, not just src/Module: the root namespace is where a
    // module's concerns leak to when they have nowhere else to go.
    $src = ClassSet::fromDir(__DIR__.'/src');

    $config->add($src,
        Rule::allClasses()
            ->that(new NotResideInTheseNamespaces('App\Module\Billing'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\Billing']))
            ->because('Billing is a leaf: the paywall reaches out through its own listener, never the other way round'),
    );

    $config->add($src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Module\Audit'))
            ->should(new DependsOnlyOnTheseNamespaces([
                'App\Module\Audit',
                'Psr\Clock',
                'Psr\Log',
                // A package may depend on Doctrine; it may not depend on Loupe.
                // Mapping only, never the rest of the ORM: the sink writes
                // through the DBAL so its rows survive a rolled-back transaction.
                'Doctrine\Bundle\DoctrineBundle\Repository',
                'Doctrine\DBAL',
                'Doctrine\ORM\Mapping',
                'Doctrine\Persistence',
                'Symfony\Bridge\Doctrine\Types',
                'Symfony\Component\DependencyInjection\Attribute',
                'Symfony\Component\Uid',
                'Symfony\Contracts\Service',
            ]))
            ->because('Audit is destined for a standalone package: it must depend on nothing in this application'),
    );
};
