<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Controller;

use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalState;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class DismissProposalControllerTest extends WebTestCase
{
    use InsightsScenario;

    public function test_dismiss_stores_the_reason_and_redirects_back_with_a_flash(): void
    {
        $client = static::createClient();
        $proposal = $this->proposal('dismiss-controller');
        $projectId = (string) $proposal->analysis->project->id;
        $owner = $proposal->analysis->project->owner;
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/analytics/reports');
        $client->submitForm('dismiss-'.$proposal->id, [
            'dismiss_proposal_'.$proposal->id.'[reason]' => 'We cache them already.',
        ]);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/reports');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('The proposal is dismissed.', $crawler->filter('body')->text());
        $stored = $this->stored($proposal);
        self::assertSame(ProposalState::Dismissed, $stored->state);
        self::assertSame('We cache them already.', $stored->dismissReason);
    }

    public function test_dismiss_takes_no_reason(): void
    {
        $client = static::createClient();
        $proposal = $this->proposal('dismiss-controller-bare');
        $projectId = (string) $proposal->analysis->project->id;
        $owner = $proposal->analysis->project->owner;
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/proposals/'.$proposal->id.'/dismiss', [
            'dismiss_proposal_'.$proposal->id => ['reason' => '', '_token' => 'csrf-token'],
        ], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/reports');
        $stored = $this->stored($proposal);
        self::assertSame(ProposalState::Dismissed, $stored->state);
        self::assertNull($stored->dismissReason);
    }

    public function test_a_reason_that_is_too_long_is_shown_on_its_field(): void
    {
        $client = static::createClient();
        $proposal = $this->proposal('dismiss-controller-long');
        $projectId = (string) $proposal->analysis->project->id;
        $owner = $proposal->analysis->project->owner;
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/proposals/'.$proposal->id.'/dismiss', [
            'dismiss_proposal_'.$proposal->id => ['reason' => str_repeat('a', Proposal::MAX_DISMISS_REASON_LENGTH + 1), '_token' => 'csrf-token'],
        ], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseStatusCodeSame(422);
        self::assertNotSame('', trim($crawler->filter('[data-proposal="'.$proposal->id.'"] [data-field-errors="reason"]')->text()));
        self::assertSame(ProposalState::Proposed, $this->stored($proposal)->state);
    }

    public function test_a_second_dismiss_redirects_back_with_an_error(): void
    {
        $client = static::createClient();
        $proposal = $this->proposal('dismiss-controller-twice');
        $proposal->state = ProposalState::Dismissed;
        $this->em()->flush();
        $projectId = (string) $proposal->analysis->project->id;
        $owner = $proposal->analysis->project->owner;
        $this->em()->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/proposals/'.$proposal->id.'/dismiss', [
            'dismiss_proposal_'.$proposal->id => ['reason' => '', '_token' => 'csrf-token'],
        ], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseRedirects('/projects/'.$projectId.'/analytics/reports');
        $crawler = $client->followRedirect();
        self::assertStringContainsString('This proposal is no longer open.', $crawler->filter('body')->text());
    }

    public function test_another_users_project_is_refused(): void
    {
        $client = static::createClient();
        $proposal = $this->proposal('dismiss-controller-theirs');
        $projectId = (string) $proposal->analysis->project->id;
        $stranger = $this->user($this->em(), 'dismiss-controller-stranger-'.uniqid().'@example.com');
        $this->em()->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_POST, '/projects/'.$projectId.'/analytics/proposals/'.$proposal->id.'/dismiss', [
            'dismiss_proposal_'.$proposal->id => ['reason' => '', '_token' => 'csrf-token'],
        ], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(ProposalState::Proposed, $this->stored($proposal)->state);
    }

    private function proposal(string $name): Proposal
    {
        $em = $this->em();
        $project = $this->scenarioProject($name);
        $analysis = $this->seedAnalysis($em, $project, AnalysisState::Done);
        $analysis->documentId = $this->seedDocument($em, $project)->id;
        $em->flush();

        return $this->seedProposal($em, $analysis);
    }

    private function stored(Proposal $proposal): Proposal
    {
        $this->em()->clear();

        return $this->em()->find(Proposal::class, $proposal->id) ?? throw new \LogicException('The proposal exists.');
    }
}
