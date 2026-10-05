<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Repository;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CardPullRequestRepositoryTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_find_for_pull_request_ignores_the_case_of_the_repository(): void
    {
        $project = $this->makeProject('links-case');
        $card = new Card($project, $this->column($project, 'backlog'), 'Ship it', '', 1);
        $link = new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/5', Forge::GitHub, 'Acme/Widgets', 5);
        $this->em->persist($card);
        $this->em->persist($link);
        $this->em->flush();

        $links = self::getContainer()->get(CardPullRequestRepository::class);
        self::assertInstanceOf(CardPullRequestRepository::class, $links);

        self::assertSame([$link], $links->findForPullRequest($project->id ?? throw new \LogicException('Flushed.'), Forge::GitHub, 'acme/widgets', 5));
        self::assertSame([], $links->findForPullRequest($project->id, Forge::GitHub, 'acme/widgets', 6));
    }

    public function test_find_url_of_pull_request_answers_the_first_link_of_the_card_to_it(): void
    {
        $project = $this->makeProject('links-url');
        $card = new Card($project, $this->column($project, 'backlog'), 'Ship it', '', 1);
        $other = new Card($project, $this->column($project, 'backlog'), 'Other', '', 2);
        $this->em->persist($card);
        $this->em->persist($other);
        $this->em->persist(new CardPullRequest($other, 'https://github.com/acme/widgets/pull/5#other', Forge::GitHub, 'acme/widgets', 5));
        $this->em->persist(new CardPullRequest($card, 'https://gitlab.com/acme/widgets/-/merge_requests/5', Forge::GitLab, 'acme/widgets', 5));
        $this->em->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/5', Forge::GitHub, 'Acme/Widgets', 5));
        $this->em->persist(new CardPullRequest($card, 'https://github.com/acme/widgets/pull/5#again', Forge::GitHub, 'acme/widgets', 5));
        $this->em->flush();

        self::assertSame('https://github.com/Acme/Widgets/pull/5', $this->links()->findUrlOfPullRequest($card, 'github', 'acme/widgets', 5));
        self::assertNull($this->links()->findUrlOfPullRequest($card, 'github', 'acme/widgets', 6));
    }

    public function test_find_git_hub_references_for_cards_answers_the_parsed_github_links_of_the_cards(): void
    {
        $project = $this->makeProject('links-batch');
        $card = new Card($project, $this->column($project, 'backlog'), 'Ship it', '', 1);
        $second = new Card($project, $this->column($project, 'backlog'), 'Ship more', '', 2);
        $unasked = new Card($project, $this->column($project, 'backlog'), 'Not asked', '', 3);
        foreach ([$card, $second, $unasked] as $each) {
            $this->em->persist($each);
        }
        $this->em->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/5', Forge::GitHub, 'Acme/Widgets', 5));
        $this->em->persist(new CardPullRequest($second, 'https://github.com/acme/widgets/pull/5', Forge::GitHub, 'acme/widgets', 5));
        $this->em->persist(new CardPullRequest($card, 'https://github.com/acme/widgets/pulls', Forge::GitHub));
        $this->em->persist(new CardPullRequest($card, 'https://gitlab.com/acme/widgets/-/merge_requests/7', Forge::GitLab, 'acme/widgets', 7));
        $this->em->persist(new CardPullRequest($unasked, 'https://github.com/acme/widgets/pull/9', Forge::GitHub, 'acme/widgets', 9));
        $this->em->flush();

        $rows = $this->links()->findGitHubReferencesForCards([$this->id($card), $this->id($second)]);

        self::assertEqualsCanonicalizing([
            ['cardId' => $this->id($card)->toRfc4122(), 'repository' => 'Acme/Widgets', 'number' => 5],
            ['cardId' => $this->id($second)->toRfc4122(), 'repository' => 'acme/widgets', 'number' => 5],
        ], $rows);
        self::assertSame([], $this->links()->findGitHubReferencesForCards([]));
    }

    public function test_find_card_ids_with_open_git_hub_pull_request_joins_the_open_rows_of_the_project(): void
    {
        $project = $this->makeProject('links-open');
        $other = $this->makeProject('links-open-other');
        $open = new Card($project, $this->column($project, 'backlog'), 'Open', '', 1);
        $closed = new Card($project, $this->column($project, 'backlog'), 'Closed', '', 2);
        $unread = new Card($project, $this->column($project, 'backlog'), 'Unread', '', 3);
        $elsewhere = new Card($other, $this->column($other, 'backlog'), 'Elsewhere', '', 1);
        foreach ([$open, $closed, $unread, $elsewhere] as $each) {
            $this->em->persist($each);
        }
        $this->em->persist(new CardPullRequest($open, 'https://github.com/Acme/Widgets/pull/5', Forge::GitHub, 'Acme/Widgets', 5));
        $this->em->persist(new CardPullRequest($closed, 'https://github.com/acme/widgets/pull/6', Forge::GitHub, 'acme/widgets', 6));
        $this->em->persist(new CardPullRequest($unread, 'https://github.com/acme/widgets/pull/7', Forge::GitHub, 'acme/widgets', 7));
        $this->em->persist(new CardPullRequest($elsewhere, 'https://github.com/acme/widgets/pull/5', Forge::GitHub, 'acme/widgets', 5));
        $this->em->persist(new ForgePullRequest($project, 'github', 'acme/widgets', 5));
        $closedRow = new ForgePullRequest($project, 'github', 'acme/widgets', 6);
        $closedRow->state = PullRequestState::Closed;
        $this->em->persist($closedRow);
        $this->em->flush();

        self::assertSame([$this->id($open)->toRfc4122()], $this->links()->findCardIdsWithOpenGitHubPullRequest($project));
        self::assertSame([], $this->links()->findCardIdsWithOpenGitHubPullRequest($other));
    }

    private function links(): CardPullRequestRepository
    {
        $links = self::getContainer()->get(CardPullRequestRepository::class);
        self::assertInstanceOf(CardPullRequestRepository::class, $links);

        return $links;
    }

    private function id(Card $card): Uuid
    {
        return $card->id ?? throw new \LogicException('Flushed.');
    }
}
