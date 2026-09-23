<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Service\CardLinkResolver;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardLinkResolverTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CardLinkResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $resolver = self::getContainer()->get(CardLinkResolver::class);
        self::assertInstanceOf(CardLinkResolver::class, $resolver);
        $this->resolver = $resolver;
    }

    public function test_it_resolves_trimmed_ids_to_cards_of_the_project_with_their_kinds(): void
    {
        $project = $this->makeProject('resolve-ok');
        [$a, $b, $c] = [$this->cardIn($project), $this->cardIn($project), $this->cardIn($project)];

        $resolved = $this->resolver->resolve($project, $a, [
            new CardLinkInput(' '.$b->id.' ', CardLinkKind::BlockedBy),
            new CardLinkInput((string) $c->id),
        ]);

        self::assertCount(2, $resolved);
        self::assertSame($b, $resolved[0][0]);
        self::assertSame(CardLinkKind::BlockedBy, $resolved[0][1]);
        self::assertSame($c, $resolved[1][0]);
        self::assertSame(CardLinkKind::RelatesTo, $resolved[1][1]);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownIds(): iterable
    {
        yield 'not a uuid' => ['not-a-uuid'];
        yield 'blank' => ['  '];
        yield 'no such card' => ['0199c0de-0000-7000-8000-0000000000ff'];
    }

    #[DataProvider('unknownIds')]
    public function test_an_id_naming_no_card_is_refused(string $id): void
    {
        $project = $this->makeProject('resolve-unknown');

        $this->assertRefused('board.card.error.linked_card_unknown', fn () => $this->resolver->resolve($project, null, [new CardLinkInput($id)]));
    }

    public function test_a_card_of_another_project_is_refused_as_unknown(): void
    {
        $project = $this->makeProject('resolve-mine');
        $theirs = $this->cardIn($this->makeProject('resolve-theirs'));

        $this->assertRefused('board.card.error.linked_card_unknown', fn () => $this->resolver->resolve($project, null, [new CardLinkInput((string) $theirs->id)]));
    }

    public function test_the_card_itself_is_refused(): void
    {
        $project = $this->makeProject('resolve-self');
        $a = $this->cardIn($project);

        $this->assertRefused('board.card.error.linked_card_self', fn () => $this->resolver->resolve($project, $a, [new CardLinkInput((string) $a->id)]));
    }

    public function test_the_same_card_named_twice_is_refused_whatever_the_case_of_the_id(): void
    {
        $project = $this->makeProject('resolve-twice');
        $b = $this->cardIn($project);

        $this->assertRefused('board.card.error.linked_card_twice', fn () => $this->resolver->resolve($project, null, [
            new CardLinkInput((string) $b->id),
            new CardLinkInput(strtoupper((string) $b->id), CardLinkKind::Blocks),
        ]));
    }

    public function test_an_empty_set_resolves_to_nothing(): void
    {
        self::assertSame([], $this->resolver->resolve($this->makeProject('resolve-empty'), null, []));
    }

    private function assertRefused(string $key, callable $call): void
    {
        try {
            $call();
            self::fail(\sprintf('Expected the refusal %s.', $key));
        } catch (DomainErrors $e) {
            self::assertSame(['relatedCards' => $key], $e->errors);
        }
    }

    private function cardIn(Project $project): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand($project, 'Ship it', 'Body', CardType::Feature));
    }
}
