<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Install\ReviewInstallFlags;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ShowDocumentMermaidTest extends WebTestCase
{
    private const string MERMAID_MARKDOWN = "## Flow\n\n```mermaid\ngraph TD\n  A --> B\n```\n\nAfter the diagram.\n";

    /** @return iterable<string, array{bool, string}> */
    public static function flagStates(): iterable
    {
        yield 'flag off' => [false, 'false'];
        yield 'flag on' => [true, 'true'];
    }

    #[DataProvider('flagStates')]
    public function test_a_mermaid_block_attaches_the_controller_with_the_flag_state(bool $enabled, string $expected): void
    {
        $client = static::createClient();
        $this->setFlag($enabled);
        [$owner, $document] = $this->seed(self::MERMAID_MARKDOWN);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->reviewUrl($document));

        self::assertResponseIsSuccessful();
        $root = $crawler->filter('[data-controller~="mermaid"]');
        self::assertCount(1, $root);
        self::assertSame($expected, $root->attr('data-mermaid-enabled-value'));
        self::assertSame(
            'https://cdn.jsdelivr.net/npm/mermaid@12.0.0/dist/mermaid.esm.min.mjs',
            $root->attr('data-mermaid-module-url-value'),
        );
        self::assertCount(1, $root->filter('[data-mermaid-target="doc"] pre > code.language-mermaid'));
        foreach (['disabledNotice', 'errorNotice', 'toggle'] as $target) {
            self::assertCount(1, $root->filter('template[data-mermaid-target="'.$target.'"]'), $target);
        }

        $pane = $crawler->filter('[data-comment-anchor-target="doc"]');
        self::assertSame($document->currentVersion()->plainText(), $pane->text(null, false));
    }

    public function test_a_document_without_mermaid_attaches_no_controller(): void
    {
        $client = static::createClient();
        $this->setFlag(true);
        [$owner, $document] = $this->seed("## Plain\n\n```php\necho 1;\n```\n");

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->reviewUrl($document));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-comment-anchor-target="doc"] pre > code'));
        self::assertCount(0, $crawler->filter('[data-controller~="mermaid"]'));
        self::assertCount(0, $crawler->filter('[data-mermaid-target]'));
    }

    private function setFlag(bool $enabled): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[ReviewInstallFlags::FLAG_MERMAID]->value = $enabled;
        $em->flush();
    }

    /** @return array{User, Document} */
    private function seed(string $markdown): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $owner = new User(fullName: 'Reviewer', email: 'mermaid-'.uniqid().'@example.test', password: 'hashed');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $create = static::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);

        return [$owner, $create(new CreateDocumentCommand($project, 'Spec', $markdown))];
    }

    private function reviewUrl(Document $document): string
    {
        return '/projects/'.$document->project->id.'/documents/'.$document->id.'/review';
    }
}
