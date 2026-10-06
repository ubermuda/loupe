<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Command\BindWorkflowTemplateHandler;
use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Tests\Module\Board\Controller\BoardScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ReleaseWorkflowPauseControllerTest extends WebTestCase
{
    use BoardScenario;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->enableBoard();
    }

    public function test_the_manager_releases_a_retries_pause_and_returns_to_the_card(): void
    {
        [$project, $card, $pause] = $this->paused('release-ok@example.com', CardPauseKind::Retries);

        $this->post($project, $card, $pause);

        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$card->id, 303);
        $this->em->clear();
        $stored = $this->em->find(CardPause::class, $pause->id);
        self::assertInstanceOf(CardPause::class, $stored);
        self::assertSame(ReleaseWorkflowPauseCommand::REASON, $stored->releaseReason);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The pause ended. The workflow runs the rule again.');
    }

    public function test_a_refused_release_flashes_its_reason_and_keeps_the_pause(): void
    {
        [$project, $card, $pause] = $this->paused('release-rule@example.com', CardPauseKind::Rule);

        $this->post($project, $card, $pause);

        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$card->id, 303);
        $this->assertStillPaused($pause);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'A pause that a rule made ends only when its release condition is met.');
    }

    public function test_a_user_who_does_not_manage_the_project_is_refused(): void
    {
        [$project, $card, $pause] = $this->paused('release-owner@example.com', CardPauseKind::Retries);
        $stranger = $this->user($this->em, 'release-stranger@example.com');

        $this->post($project, $card, $pause, as: $stranger);

        self::assertResponseStatusCodeSame(403);
        $this->assertStillPaused($pause);
    }

    public function test_a_bad_csrf_token_is_refused(): void
    {
        [$project, $card, $pause] = $this->paused('release-csrf@example.com', CardPauseKind::Retries);

        $this->post($project, $card, $pause, token: 'invalid-token');

        self::assertResponseStatusCodeSame(403);
        $this->assertStillPaused($pause);
    }

    public function test_a_card_of_another_project_is_not_found(): void
    {
        [$project, , $pause] = $this->paused('release-scope@example.com', CardPauseKind::Retries);
        $other = $this->project($this->em, $project->owner, 'other-board');
        $foreign = $this->card($this->em, $other, 'Foreign', 'next');

        $this->post($project, $foreign, $pause);

        self::assertResponseStatusCodeSame(404);
        $this->assertStillPaused($pause);
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{Project, Card, CardPause}
     */
    private function paused(string $email, CardPauseKind $kind): array
    {
        $owner = $this->user($this->em, $email);
        $project = $this->project($this->em, $owner);
        $bind = static::getContainer()->get(BindWorkflowTemplateHandler::class);
        self::assertInstanceOf(BindWorkflowTemplateHandler::class, $bind);
        $bind(new BindWorkflowTemplateCommand($project, 'simple', []));
        $card = $this->card($this->em, $project, 'Paused', 'next');
        $pauseCard = static::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $pauseCard);
        $pause = $pauseCard(new PauseCardCommand($card, 'move-refused', 'implement', $kind)) ?? throw new \LogicException('The card had no pause.');

        return [$project, $card, $pause];
    }

    /** The same-origin sentinel passes the CSRF check, so a 403 with it is the voter's. */
    private function post(Project $project, Card $card, CardPause $pause, ?User $as = null, string $token = 'csrf-token'): void
    {
        $this->em->clear();
        $this->client->loginUser($as ?? $project->owner);
        $url = '/projects/'.$project->id.'/board/cards/'.$card->id.'/workflow-pauses/'.$pause->id.'/release';
        $this->client->request(Request::METHOD_POST, $url, ['_csrf_token' => $token], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
    }

    private function assertStillPaused(CardPause $pause): void
    {
        $this->em->clear();
        $stored = $this->em->find(CardPause::class, $pause->id);
        self::assertInstanceOf(CardPause::class, $stored);
        self::assertNull($stored->releasedAt);
    }
}
