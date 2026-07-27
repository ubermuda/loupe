<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\ToggleProjectWidgetForwardingCommand;
use App\Module\Project\Command\ToggleProjectWidgetForwardingHandler;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ToggleProjectWidgetForwardingHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ToggleProjectWidgetForwardingHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->handler = new ToggleProjectWidgetForwardingHandler($this->em, new NullLogger());
    }

    public function test_a_freshly_minted_token_starts_collect_only(): void
    {
        $project = $this->project('forwarding-a@example.com');

        self::assertNotNull($project->widgetToken);
        self::assertFalse($project->widgetToken->forwardsToAgent);
    }

    public function test_toggling_turns_forwarding_on_and_back_off(): void
    {
        $project = $this->project('forwarding-b@example.com');

        self::assertTrue(($this->handler)(new ToggleProjectWidgetForwardingCommand($project)));
        self::assertNotNull($project->widgetToken);
        self::assertTrue($project->widgetToken->forwardsToAgent);

        self::assertFalse(($this->handler)(new ToggleProjectWidgetForwardingCommand($project)));
        self::assertFalse($project->widgetToken->forwardsToAgent);
    }

    public function test_the_new_state_is_persisted(): void
    {
        $project = $this->project('forwarding-c@example.com');
        ($this->handler)(new ToggleProjectWidgetForwardingCommand($project));
        $tokenId = $project->widgetToken?->id;

        $this->em->clear();

        $reloaded = $this->em->find(ApiToken::class, $tokenId);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->forwardsToAgent);
    }

    public function test_a_project_without_a_widget_token_is_a_domain_error(): void
    {
        $project = $this->project('forwarding-d@example.com', withToken: false);

        $this->expectException(DomainErrors::class);
        ($this->handler)(new ToggleProjectWidgetForwardingCommand($project));
    }

    /** @param non-empty-string $email */
    private function project(string $email, bool $withToken = true): Project
    {
        $owner = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $this->em->persist($owner);
        $project = new Project($owner, 'forwarding-site');

        if ($withToken) {
            [$token] = ApiToken::issue($owner, 'Widget', ApiTokenScope::SiteReview);
            $project->widgetToken = $token;
            $this->em->persist($token);
        }

        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
