<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Controller;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\BindWorkflowTemplateHandler;
use App\Module\Workflow\Engine\Engine;
use App\Tests\Module\Board\Controller\BoardScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The card page shows the Workflow panel. */
final class CardWorkflowPanelPageTest extends WebTestCase
{
    use BoardScenario;

    public function test_with_the_board_automation_off_a_card_with_no_pause_shows_no_panel(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'panel-empty@example.com');
        $project = $this->project($em, $owner);
        $this->bindSimple($project);
        $card = $this->card($em, $project, 'Quiet', 'next');
        $automation = static::getContainer()->get(BoardAutomation::class);
        self::assertInstanceOf(BoardAutomation::class, $automation);
        $automation->settingsForUpdate($project)->enabled = false;
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-card-runs]'));
        self::assertCount(0, $crawler->filter('[data-workflow-panel]'));
    }

    public function test_a_paused_card_shows_the_reason_and_the_release_condition(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
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

    public function test_a_retries_pause_offers_the_manager_a_retry_button(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'panel-retry@example.com');
        $project = $this->project($em, $owner);
        $this->bindSimple($project);
        $card = $this->card($em, $project, 'Paused', 'next');
        $pause = static::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $pause);
        $paused = $pause(new PauseCardCommand($card, 'move-refused', 'implement', CardPauseKind::Retries));
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $button = $crawler->filter('[data-workflow-pause] [data-workflow-retry]');
        self::assertSame('Retry now', trim($button->text()));
        $form = $button->closest('form') ?? self::fail('The button sits in a form.');
        self::assertSame('/projects/'.$project->id.'/board/cards/'.$card->id.'/workflow-pauses/'.$paused?->id.'/release', $form->attr('action'));
        self::assertSame('_top', $form->attr('data-turbo-frame'));
        self::assertNotEmpty($form->filter('input[name="_csrf_token"]')->attr('value'));
    }

    public function test_a_rule_pause_offers_no_retry_button(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'panel-rule@example.com');
        $project = $this->project($em, $owner);
        $this->bindSimple($project);
        $card = $this->card($em, $project, 'Paused', 'next');
        $pause = static::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $pause);
        $pause(new PauseCardCommand($card, 'on-hold', 'implement', CardPauseKind::Rule));
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-workflow-pause]'));
        self::assertCount(0, $crawler->filter('[data-workflow-retry]'));
    }

    private function bindSimple(Project $project): void
    {
        $bind = static::getContainer()->get(BindWorkflowTemplateHandler::class);
        self::assertInstanceOf(BindWorkflowTemplateHandler::class, $bind);
        $bind(new BindWorkflowTemplateCommand($project, 'simple', []));
    }
}
