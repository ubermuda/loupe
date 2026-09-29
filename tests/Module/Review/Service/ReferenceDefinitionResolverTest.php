<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Service\ReferenceDefinitionResolver;
use App\Module\Review\Service\ReferenceReminderInjector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ReferenceDefinitionResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DocumentVersionRepository $versions;
    private UrlGeneratorInterface $urls;
    private Project $project;
    private User $owner;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $versions = self::getContainer()->get(DocumentVersionRepository::class);
        self::assertInstanceOf(DocumentVersionRepository::class, $versions);
        $this->versions = $versions;

        $urls = self::getContainer()->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $urls);
        $this->urls = $urls;

        $this->owner = new User(fullName: 'Ref Owner', email: 'ref-owner@example.com', password: 'x');
        $this->em->persist($this->owner);
        $this->project = new Project($this->owner, 'p-'.uniqid());
        $this->em->persist($this->project);
    }

    public function test_own_definitions_win_then_references_in_order(): void
    {
        [$own, $older, $newer] = $this->documents();

        $definitions = $this->resolver($this->grantAllBut(null))->resolve($own, $this->versions->findLatest($own));

        self::assertSame(['R1', 'R2', 'H3'], array_keys($definitions));
        self::assertSame(['text' => 'Own rule.', 'source' => null, 'href' => '#ref-R1'], $definitions['R1']);
        self::assertSame('Older second rule.', $definitions['R2']['text']);
        self::assertSame('Older', $definitions['R2']['source']);
        self::assertStringEndsWith(
            '/projects/'.$this->project->id.'/documents/'.$older->id.'/review#ref-R2',
            $definitions['R2']['href'],
        );
        self::assertSame(['text' => 'Newer hypothesis.', 'source' => 'Newer'], array_slice($definitions['H3'], 0, 2));
        self::assertStringEndsWith('/documents/'.$newer->id.'/review#ref-H3', $definitions['H3']['href']);
    }

    public function test_a_reference_the_reader_cannot_view_is_skipped(): void
    {
        [$own, $older] = $this->documents();

        $definitions = $this->resolver($this->grantAllBut($older))->resolve($own, $this->versions->findLatest($own));

        self::assertSame(['R1', 'R2', 'H3'], array_keys($definitions));
        self::assertSame('Newer second rule.', $definitions['R2']['text']);
        self::assertSame('Newer', $definitions['R2']['source']);
    }

    public function test_no_reference_means_no_version_query(): void
    {
        $own = new Document(owner: $this->owner, project: $this->project, title: 'Alone');
        $version = $own->addVersion('x', '<ul><li><strong>R1:</strong> Own rule.</li></ul>');
        $this->em->persist($own);
        $this->em->flush();

        $versions = $this->createMock(DocumentVersionRepository::class);
        $versions->expects($this->never())->method('findLatestByDocuments');

        $resolver = new ReferenceDefinitionResolver(new ReferenceReminderInjector(), $versions, $this->grantAllBut(null), $this->urls);

        self::assertSame(
            ['R1' => ['text' => 'Own rule.', 'source' => null, 'href' => '#ref-R1']],
            $resolver->resolve($own, $version),
        );
    }

    /**
     * @return array{Document, Document, Document} the referring document, then its references by age
     */
    private function documents(): array
    {
        $older = new Document(owner: $this->owner, project: $this->project, title: 'Older', createdAt: new \DateTimeImmutable('-2 days'));
        $older->addVersion('x', '<ul><li><strong>R1:</strong> Older rule.</li><li><strong>R2:</strong> Older first rule.</li></ul>');
        $older->addVersion('x', '<ul><li><strong>R1:</strong> Older rule.</li><li><strong>R2:</strong> Older second rule.</li></ul>');

        $newer = new Document(owner: $this->owner, project: $this->project, title: 'Newer', createdAt: new \DateTimeImmutable('-1 day'));
        $newer->addVersion('x', '<ul><li><strong>R2:</strong> Newer second rule.</li><li><strong>H3:</strong> Newer hypothesis.</li></ul>');

        $own = new Document(owner: $this->owner, project: $this->project, title: 'Own');
        $own->addVersion('x', '<ul><li><strong>R1:</strong> Own rule.</li></ul><p>R2 and H3.</p>');
        $own->addReference($newer);
        $own->addReference($older);

        $this->em->persist($older);
        $this->em->persist($newer);
        $this->em->persist($own);
        $this->em->flush();
        $this->em->clear();

        return [
            $this->em->find(Document::class, $own->id) ?? throw new \LogicException('Own document is missing.'),
            $this->em->find(Document::class, $older->id) ?? throw new \LogicException('Older document is missing.'),
            $this->em->find(Document::class, $newer->id) ?? throw new \LogicException('Newer document is missing.'),
        ];
    }

    private function grantAllBut(?Document $denied): AuthorizationCheckerInterface
    {
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute, mixed $subject): bool => DocumentVoter::VIEW === $attribute
                && !($subject instanceof Document && null !== $denied && $subject->id?->equals($denied->id)),
        );

        return $authorization;
    }

    private function resolver(AuthorizationCheckerInterface $authorization): ReferenceDefinitionResolver
    {
        return new ReferenceDefinitionResolver(new ReferenceReminderInjector(), $this->versions, $authorization, $this->urls);
    }
}
