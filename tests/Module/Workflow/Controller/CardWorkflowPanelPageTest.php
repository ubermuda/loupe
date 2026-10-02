<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Controller;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\BindWorkflowTemplateHandler;
use App\Module\Workflow\Engine\Engine;
use App\Tests\Module\Board\Controller\BoardScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The card page shows the Workflow panel, with the engine off as it ships. */
final class CardWorkflowPanelPageTest extends WebTestCase
{
    use BoardScenario;

    public function test_a_held_card_shows_unmanaged(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'panel-held@example.com');
        $project = $this->project($em, $owner);
        $this->bindSimple($project);
        $card = $this->card($em, $project, 'Held', 'next');
        $holds = static::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);
        $holds->hold($project, $card->id ?? throw new \LogicException('A flushed card has an id.'), null);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $panel = $crawler->filter('[data-workflow-panel]');
        self::assertSame('unmanaged', $panel->attr('data-workflow-management'));
        self::assertStringContainsString('Unmanaged: the workflow makes no move and starts no work on this card.', $panel->text());
        self::assertCount(0, $panel->filter('[data-workflow-pause]'));
    }

    public function test_a_managed_card_shows_managed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'panel-managed@example.com');
        $project = $this->project($em, $owner);
        $this->bindSimple($project);
        $card = $this->card($em, $project, 'Managed', 'next');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $panel = $crawler->filter('[data-workflow-panel]');
        self::assertSame('managed', $panel->attr('data-workflow-management'));
        self::assertStringContainsString('Managed: the card follows the workflow template of the project.', $panel->text());
        // The engine is off, so the panel shows no slot and no next step.
        self::assertCount(0, $panel->filter('[data-workflow-slot]'));
    }

    public function test_a_card_of_a_project_with_no_template_shows_that(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'panel-no-template@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Free', 'next');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        self::assertSame('no-template', $crawler->filter('[data-workflow-panel]')->attr('data-workflow-management'));
    }

    public function test_a_paused_card_shows_the_reason_and_the_release_condition(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'panel-paused@example.com');
        $project = $this->project($em, $owner);
        $this->bindSimple($project);
        $card = $this->card($em, $project, 'Paused', 'next');
        $pause = static::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $pause);
        $pause(new PauseCardCommand($card, Engine::NO_BRIDGE_TOOK_WORK, 'implement', CardPauseKind::WorkTimeout));
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $paused = $crawler->filter('[data-workflow-panel] [data-workflow-pause]');
        self::assertSame('no-bridge-took-work', $paused->attr('data-workflow-pause'));
        self::assertStringContainsString('No bridge took the requested work in time.', $paused->text());
        self::assertStringContainsString('The pause ends when the facts that the rule reads change.', $paused->text());
    }

    public function test_an_unknown_pause_code_shows_the_code(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'panel-paused-code@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Paused', 'next');
        $pause = static::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $pause);
        $pause(new PauseCardCommand($card, 'api-failed-rate-limited', 'merge-ready', CardPauseKind::Retries));
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('api-failed-rate-limited', $crawler->filter('[data-workflow-pause]')->text());
    }

    private function bindSimple(Project $project): void
    {
        $bind = static::getContainer()->get(BindWorkflowTemplateHandler::class);
        self::assertInstanceOf(BindWorkflowTemplateHandler::class, $bind);
        $bind(new BindWorkflowTemplateCommand($project, 'simple', []));
    }
}
