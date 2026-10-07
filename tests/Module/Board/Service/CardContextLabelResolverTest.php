<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\CardContextLabelResolver;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardContextLabelResolverTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private CardContextLabelResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $resolver = self::getContainer()->get(CardContextLabelResolver::class);
        self::assertInstanceOf(CardContextLabelResolver::class, $resolver);
        $this->resolver = $resolver;
    }

    public function test_it_names_a_card_of_the_asking_project_and_links_to_it(): void
    {
        [$project, $card] = $this->projectWithCard('ctx-ok');

        $label = $this->resolver->resolve('card:'.$card->id, $project);

        self::assertNotNull($label);
        self::assertSame('#1 Footer overlaps the launcher', $label->label);
        self::assertNotNull($label->url);
        self::assertStringContainsString((string) $card->id, $label->url);
    }

    /**
     * Silence is the feature. The save refuses a card of another project, so a
     * reviewer told its name would have been told a lie.
     */
    public function test_a_card_of_another_project_resolves_to_nothing(): void
    {
        [, $card] = $this->projectWithCard('ctx-foreign-card');
        [$other] = $this->projectWithCard('ctx-foreign-site');

        self::assertNull($this->resolver->resolve('card:'.$card->id, $other));
    }

    public function test_it_declines_a_marker_it_cannot_honour(): void
    {
        [$project, $card] = $this->projectWithCard('ctx-declines');

        // Guard: prove it resolves at all, or every assertion below passes on a
        // resolver that never answers anything.
        self::assertNotNull($this->resolver->resolve('card:'.$card->id, $project));

        self::assertNull($this->resolver->resolve('branch:feat/x', $project));
        self::assertNull($this->resolver->resolve('card:not-a-uuid', $project));
        self::assertNull($this->resolver->resolve('card:0199c0de-0000-7000-8000-0000000000ff', $project));
    }

    /** @return array{Project, Card} */
    private function projectWithCard(string $slug): array
    {
        $owner = new User(fullName: 'Riley', email: $slug.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, $slug);
        $this->em->persist($project);
        $this->seedColumns($project);
        $card = new Card($project, $this->column($project, 'backlog'), 'Footer overlaps the launcher', 'body', 1);
        $this->em->persist($card);
        $this->em->flush();

        return [$project, $card];
    }
}
