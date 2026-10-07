<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Entity\ProposalState;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AcceptProposalControllerTest extends WebTestCase
{
    use BoardColumnFixtures;
    use InsightsScenario;

    public function test_accept_creates_the_card_and_redirects_back_with_a_flash(): void
    {
        $client = static::createClient();
        $proposal = $this->proposal('accept-controller');
        $projectId = (string) $proposal->analysis->project->id;
        $owner = $proposal->analysis->project->owner;
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/proposals/'.$proposal->id.'/accept', ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/reports');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('The proposal is now a card in the backlog.', $crawler->filter('body')->text());
        $stored = $this->stored($proposal);
        self::assertSame(ProposalState::Created, $stored->state);
        self::assertNotNull($stored->cardId);
    }

    public function test_a_refused_accept_redirects_back_with_an_error(): void
    {
        $client = static::createClient();
        $proposal = $this->proposal('accept-controller-rule', ProposalKind::BucketRule);
        $projectId = (string) $proposal->analysis->project->id;
        $owner = $proposal->analysis->project->owner;
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/proposals/'.$proposal->id.'/accept', ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/reports');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Loupe cannot apply a bucket rule yet.', $crawler->filter('body')->text());
        self::assertSame(ProposalState::Proposed, $this->stored($proposal)->state);
    }

    public function test_a_proposal_of_another_project_is_not_found(): void
    {
        $client = static::createClient();
        $proposal = $this->proposal('accept-controller-scope');
        $owner = $proposal->analysis->project->owner;
        $other = $this->project($this->em(), $owner, 'Other accept-controller-scope');
        $otherId = (string) $other->id;
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, '/projects/'.$otherId.'/analytics/proposals/'.$proposal->id.'/accept', ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(ProposalState::Proposed, $this->stored($proposal)->state);
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $proposal = $this->proposal('accept-controller-theirs');
        $projectId = (string) $proposal->analysis->project->id;
        $stranger = $this->user($this->em(), 'accept-controller-stranger-'.uniqid().'@example.com');
        $this->em()->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/proposals/'.$proposal->id.'/accept', ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(ProposalState::Proposed, $this->stored($proposal)->state);
    }

    private function proposal(string $name, ProposalKind $kind = ProposalKind::Card): Proposal
    {
        $em = $this->em();
        $project = $this->scenarioProject($name);
        $this->seedColumns($project);
        $em->flush();
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Done);
        $analysis->documentId = $this->seedDocument($em, $project)->id;
        $em->flush();

        return $this->seedProposal($em, $analysis, $kind);
    }

    private function stored(Proposal $proposal): Proposal
    {
        $this->em()->clear();

        return $this->em()->find(Proposal::class, $proposal->id) ?? throw new \LogicException('The proposal exists.');
    }
}
