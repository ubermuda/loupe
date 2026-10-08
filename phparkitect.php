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
            ->that(new NotResideInTheseNamespaces('App\Module\Board', 'App\Module\Inbox', 'App\Module\Readiness', 'App\Module\Workflow'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\Board']))
            ->because('Board is a leaf: a card belongs to a project, so Board depends on Project and Project must not depend back. Folding a card export into ProjectExporter reads as the convenient move and closes the cycle. Inbox is exempt, because it links an item to a card by foreign key and Board never imports Inbox. Workflow is exempt, because the engine reads and moves cards, and Board never imports Workflow. Readiness is exempt, because it creates and moves cards, and Board never imports Readiness'),
    );

    $config->add($src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Module\Board', 'App\Module\Review', 'App\Module\Bridge', 'App\Module\Workflow'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\Inbox']))
            ->because('Inbox depends on Board and Review to link an item to a card or a document, and on Bridge to read whether a bridge still sends its heartbeat, so an import back closes a cycle. A card or document page reaches the inbox through a Twig function. Workflow stays clear of Inbox, so that Inbox can later depend on the engine'),
    );

    $config->add($src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Module\Board', 'App\Module\Bridge', 'App\Module\Forge', 'App\Module\GitHub'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\Workflow'], ['App\Module\Workflow\Contract']))
            ->because('Workflow depends on Board, Bridge, Forge and GitHub to read facts and act on cards, so an import back closes a cycle. A module plugs its facts and conditions into the engine through Workflow\Contract, which imports nothing back. Board reaches the engine through a port it declares, and Workflow implements that port'),
    );

    $config->add($src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Module\Workflow\Contract'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module'], ['App\Module\Workflow\Contract']))
            ->because('Workflow\Contract is a leaf: every module may import it, so it imports no module and no import of it can close a cycle'),
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

    $config->add($src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Module\GitHub'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\Board']))
            ->because('GitHub announces a delivery through the Forge event, and Board decides what it means for a card'),
    );

    $config->add($src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Module\Bridge'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\Insights']))
            ->because('Insights reads worker runs and metrics from Bridge to show the Analytics pages, so an import back closes a cycle'),
    );
};
