<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
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
            ->that(new NotResideInTheseNamespaces('App\Module\Board', 'App\Module\Inbox'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\Board']))
            ->because('Board is a leaf: a card belongs to a project, so Board depends on Project and Project must not depend back. Folding a card export into ProjectExporter reads as the convenient move and closes the cycle. Inbox is exempt, because it links an item to a card by foreign key and Board never imports Inbox'),
    );

    $config->add($src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Module\Board', 'App\Module\Review', 'App\Module\Bridge'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\Inbox']))
            ->because('Inbox depends on Board and Review to link an item to a card or a document, and on Bridge to read whether a bridge still sends its heartbeat, so an import back closes a cycle. A card or document page reaches the inbox through a Twig function'),
    );

    $config->add($src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Module\Forge'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\Board', 'App\Module\GitHub']))
            ->because('Forge is the forge-neutral contract. Board and each forge module depend on it, so an import back closes a cycle'),
    );

    $config->add($src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Module\Board'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\GitHub']))
            ->because('Board reads forge deliveries through the Forge contract alone, so it must not learn which forge sent one'),
    );
};
