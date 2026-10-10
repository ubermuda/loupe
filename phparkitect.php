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
            ->that(new NotResideInTheseNamespaces('App\Module\Board', 'App\Module\Inbox', 'App\Module\Readiness', 'App\Module\AgentReview'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\Board']))
            ->because('Board is a leaf: a card belongs to a project, so Board depends on Project and Project must not depend back. Folding a card export into ProjectExporter reads as the convenient move and closes the cycle. Inbox is exempt, because it links an item to a card by foreign key and Board never imports Inbox. Readiness is exempt, because it creates and moves cards, and Board never imports Readiness. AgentReview is exempt, because it reads the card, its pull requests and the board settings, and Board never imports AgentReview'),
    );

    $config->add($src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Module\Board', 'App\Module\Review', 'App\Module\Bridge', 'App\Module\Workflow'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\Inbox']))
            ->because('Inbox depends on Board and Review to link an item to a card or a document, and on Bridge to read whether a bridge still sends its heartbeat, so an import back closes a cycle. A card or document page reaches the inbox through a Twig function. Workflow stays clear of Inbox, so that Inbox can later depend on the engine'),
    );

    $config->add($src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Module\Board', 'App\Module\Bridge', 'App\Module\Forge', 'App\Module\GitHub', 'App\Module\AgentReview'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\Workflow'], ['App\Module\Workflow\Contract']))
            ->because('Board, Bridge, Forge, GitHub and AgentReview plug their facts, conditions and actions into the engine through Workflow\Contract. They import nothing else from Workflow, and Workflow imports none of them, so the contract is the only link between the two sides'),
    );

    $config->add($src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Module\Workflow'))
            ->andThat(new NotResideInTheseNamespaces('App\Module\Workflow\Contract'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\Board', 'App\Module\Bridge', 'App\Module\Forge', 'App\Module\GitHub', 'App\Module\Review', 'App\Module\AgentReview']))
            ->because('Board, Bridge, Forge, GitHub and AgentReview plug facts, conditions and actions into the engine through Workflow\Contract. So the engine depends on none of them, and they may depend on the contract. Review is in the list too, because the engine reads documents through the contract alone'),
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
            ->that(new ResideInOneOfTheseNamespaces('App\Module\Board', 'App\Module\Forge'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\AgentReview']))
            ->because('AgentReview depends on Board and Forge to read a card and its pull requests, so an import back closes a cycle. Board reaches the agent review through a port'),
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

    $config->add($src,
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('App\Module\DesignSystem'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module'], ['App\Module\DesignSystem']))
            ->because('The design system is a leaf: the catalog and the tokens describe the UI and read no feature'),
    );

    $config->add($src,
        Rule::allClasses()
            ->that(new NotResideInTheseNamespaces('App\Module\DesignSystem'))
            ->should(new NotDependsOnTheseNamespaces(['App\Module\DesignSystem']))
            ->because('Nothing depends on the design system classes: templates and tools read the catalog, and no feature code does'),
    );
};
