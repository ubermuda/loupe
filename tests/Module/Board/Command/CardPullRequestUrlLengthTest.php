<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Mcp\BoardToolErrorMessages;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A pull request URL longer than its column is a domain error, not a 500.
 *
 * The two web controllers catch DomainErrors and nothing else, so an unguarded
 * URL reached Postgres and came back as a server error on what a person typed.
 */
final class CardPullRequestUrlLengthTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private UpdateCardHandler $updateCard;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $createCard = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $createCard);
        $this->createCard = $createCard;

        $updateCard = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $updateCard);
        $this->updateCard = $updateCard;

        $owner = new User(fullName: 'Riley', email: 'board-url-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $this->project = new Project($owner, 'board-'.uniqid());
        $this->em->persist($this->project);
        $this->em->flush();
    }

    public function test_a_new_card_takes_a_url_as_long_as_its_column(): void
    {
        $url = $this->url(CardPullRequest::MAX_URL_LENGTH);

        $card = $this->create([$url]);

        self::assertSame([$url], $this->storedUrls($card));
    }

    public function test_a_new_card_refuses_a_url_one_character_over_the_column(): void
    {
        try {
            $this->create([$this->url(CardPullRequest::MAX_URL_LENGTH + 1)]);
            self::fail('expected the over-long URL to be refused');
        } catch (DomainErrors $e) {
            self::assertSame(['pullRequestUrls' => 'board.card.error.pull_request_url_too_long'], $e->errors);
        }
    }

    public function test_an_update_takes_a_url_as_long_as_its_column(): void
    {
        $card = $this->create([]);
        $url = $this->url(CardPullRequest::MAX_URL_LENGTH);

        ($this->updateCard)(new UpdateCardCommand(card: $card, pullRequestUrls: [$url]));

        self::assertSame([$url], $this->storedUrls($card));
    }

    public function test_an_update_refuses_a_url_one_character_over_the_column(): void
    {
        $card = $this->create([]);

        try {
            ($this->updateCard)(new UpdateCardCommand(
                card: $card,
                pullRequestUrls: [$this->url(CardPullRequest::MAX_URL_LENGTH + 1)],
            ));
            self::fail('expected the over-long URL to be refused');
        } catch (DomainErrors $e) {
            self::assertSame(['pullRequestUrls' => 'board.card.error.pull_request_url_too_long'], $e->errors);
        }
    }

    /** The resolver trims before it stores, so the guard measures the trimmed URL too. */
    public function test_surrounding_space_does_not_count_towards_the_limit(): void
    {
        $url = $this->url(CardPullRequest::MAX_URL_LENGTH);

        $card = $this->create(['  '.$url.'  ']);

        self::assertSame([$url], $this->storedUrls($card));
    }

    public function test_an_agent_is_told_the_limit_rather_than_the_translation_key(): void
    {
        $messages = new BoardToolErrorMessages();

        $exception = $messages->forAgent(new DomainErrors([
            'pullRequestUrls' => 'board.card.error.pull_request_url_too_long',
        ]));

        self::assertSame(
            'pullRequestUrls: A pull request URL must be at most 512 characters.',
            $exception->getMessage(),
        );
    }

    /** @return list<string> */
    private function storedUrls(Card $card): array
    {
        return array_values(array_map(
            static fn (CardPullRequest $link): string => $link->url,
            $card->pullRequests->toArray(),
        ));
    }

    /** @param list<string> $pullRequestUrls */
    private function create(array $pullRequestUrls): Card
    {
        return ($this->createCard)(new CreateCardCommand(
            project: $this->project,
            title: 'A card',
            body: 'Body',
            type: CardType::Feature,
            priority: CardPriority::Medium,
            status: CardStatus::Backlog,
            pullRequestUrls: $pullRequestUrls,
        ));
    }

    private function url(int $length): string
    {
        $prefix = 'https://example.com/';

        return $prefix.str_repeat('a', $length - mb_strlen($prefix));
    }
}
