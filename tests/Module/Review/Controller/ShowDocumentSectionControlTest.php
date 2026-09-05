<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The approval control beside each heading, as the page actually renders it.
 *
 * SectionControlInjectorTest proves the string surgery adds no text. This proves
 * the same of the real template, which is where a later contributor would add a
 * visible label to the button and move every comment anchor below it.
 */
final class ShowDocumentSectionControlTest extends WebTestCase
{
    private const string MARKDOWN = "## Alpha\n\nAlpha body.\n\n### Nested\n\nNested body.\n\n## Beta\n\nBeta body.\n";

    public function test_the_pane_text_still_equals_the_anchor_basis_with_the_controls_rendered(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->reviewUrl($document));

        self::assertResponseIsSuccessful();
        $pane = $crawler->filter('[data-comment-anchor-target="doc"]');
        self::assertCount(3, $pane->filter('[data-section-approve]'), 'one control per section');
        self::assertSame($document->currentVersion()->plainText(), $pane->text(null, false));
    }

    public function test_every_control_is_an_icon_with_its_name_on_an_attribute(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->reviewUrl($document));

        $buttons = $crawler->filter('[data-comment-anchor-target="doc"] [data-section-approve]');
        self::assertCount(3, $buttons);

        for ($index = 0; $index < $buttons->count(); ++$index) {
            $node = $buttons->eq($index);
            self::assertSame('', $node->text(null, false), 'the button must contribute no text to the pane');
            self::assertNotSame('', (string) $node->attr('aria-label'));
            self::assertSame('false', $node->attr('aria-pressed'));
            self::assertCount(1, $node->filter('svg'));
        }

        self::assertSame(
            'Approve section Alpha',
            $buttons->filter('[data-section-approve="heading-alpha"]')->attr('aria-label'),
        );
    }

    public function test_approving_from_a_heading_control_flips_it_and_the_panel_together(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->reviewUrl($document));
        $client->submit($crawler->filter('[data-section-approve="heading-alpha"]')->form());

        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        $control = $crawler->filter('[data-section-approve="heading-alpha"]');
        self::assertSame('true', $control->attr('aria-pressed'));
        self::assertSame('Withdraw approval of section Alpha', $control->attr('aria-label'));

        // The glyph itself says which state the section is in, so the two do not
        // read alike for someone who cannot tell the two colours apart. Both keep
        // the ring; only the approved one carries the tick inside it.
        $stillOpen = $crawler->filter('[data-section-approve="heading-beta"]');
        self::assertSame('false', $stillOpen->attr('aria-pressed'));
        self::assertCount(1, $control->filter('svg circle'));
        self::assertCount(1, $stillOpen->filter('svg circle'));
        self::assertCount(1, $control->filter('svg path'), 'the approved glyph carries a tick');
        self::assertCount(0, $stillOpen->filter('svg path'), 'the unapproved glyph is an empty ring');
        self::assertNotSame($control->filter('svg')->html(), $stillOpen->filter('svg')->html());

        // Contents and section approvals are one panel. It reports the same
        // rows, keeps its navigation links, and offers no control of its own.
        self::assertStringContainsString('1 of 3 approved', $crawler->filter('#section-summary-count')->text());
        self::assertCount(1, $crawler->filter('[data-section-approved="heading-alpha"]'));
        self::assertCount(0, $crawler->filter('[data-panel="contents"] button'));
        self::assertCount(0, $crawler->filter('[data-panel="sections"]'));
        self::assertCount(3, $crawler->filter('[data-panel="contents"] .lp-review-contents__link'));
        self::assertSame(
            '#heading-alpha',
            $crawler->filter('[data-panel="contents"] .lp-review-contents__link')->first()->attr('href'),
        );

        // And the basis still holds once a control has changed state.
        $pane = $crawler->filter('[data-comment-anchor-target="doc"]');
        self::assertSame($document->currentVersion()->plainText(), $pane->text(null, false));
    }

    public function test_an_older_version_renders_no_control(): void
    {
        $client = static::createClient();
        [$owner, $document] = $this->seed();

        $revise = static::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $revise);
        $revise(new ReviseDocumentCommand($document, self::MARKDOWN, 'Second version.'));

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->reviewUrl($document).'/versions/1');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-section-approve]'));
        // Still the same basis, so an older version stays anchorable.
        $versions = static::getContainer()->get(DocumentVersionRepository::class);
        self::assertInstanceOf(DocumentVersionRepository::class, $versions);
        $version = $versions->findByNumber($document, 1);
        self::assertInstanceOf(DocumentVersion::class, $version);
        self::assertSame(
            $version->plainText(),
            $crawler->filter('[data-comment-anchor-target="doc"]')->text(null, false),
        );
    }

    /** @return array{User, Document} */
    private function seed(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $owner = new User(fullName: 'Reviewer', email: 'section-control-'.uniqid().'@example.test', password: 'hashed');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $em->persist($owner);
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        $create = static::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $create);

        return [$owner, $create(new CreateDocumentCommand($project, 'Spec', self::MARKDOWN))];
    }

    private function reviewUrl(Document $document): string
    {
        return '/projects/'.$document->project->id.'/documents/'.$document->id.'/review';
    }
}
