<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Form\CardParentAutocompleteField;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class CardParentFormTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_edit_form_sets_and_clears_the_parent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'parent-form-edit@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'The epic'), CardType::Epic);
        $card = $this->card($em, $project, 'The child');
        [$epicId, $cardId] = [$epic->id, $card->id];
        $em->clear();

        $client->loginUser($owner);
        $this->submitEdit($client, $project, $cardId, ['parent' => (string) $epicId]);
        self::assertResponseRedirects();
        self::assertSame((string) $epicId, (string) $this->reload($em, $cardId)->parent?->id);

        // The form shows the parent it holds, so a plain save keeps it.
        $crawler = $client->request(Request::METHOD_GET, $this->editUrl($project, $cardId));
        self::assertSame((string) $epicId, $crawler->filter('select[name="create_card_form[parent]"] option[selected]')->attr('value'));
        $this->submitEdit($client, $project, $cardId, []);
        self::assertSame((string) $epicId, (string) $this->reload($em, $cardId)->parent?->id);

        $this->submitEdit($client, $project, $cardId, ['parent' => '']);
        self::assertResponseRedirects();
        self::assertNull($this->reload($em, $cardId)->parent);
    }

    public function test_the_create_form_sets_the_parent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'parent-form-create@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'The epic'), CardType::Epic);
        $epicId = $epic->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/new');
        self::assertResponseIsSuccessful();
        $this->submit($client, $crawler, 'Create card', ['title' => 'Born under an epic', 'parent' => (string) $epicId]);

        self::assertResponseRedirects();
        $em->clear();
        $created = static::getContainer()->get(CardRepository::class)->findOneBy(['title' => 'Born under an epic']);
        self::assertInstanceOf(Card::class, $created);
        self::assertSame((string) $epicId, (string) $created->parent?->id);
    }

    public function test_a_card_that_is_not_an_epic_is_refused_as_a_parent(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'parent-form-not-epic@example.com');
        $project = $this->project($em, $owner);
        $feature = $this->card($em, $project, 'A plain feature');
        $card = $this->card($em, $project, 'The child');
        [$featureId, $cardId] = [$feature->id, $card->id];
        $em->clear();

        $client->loginUser($owner);
        $crawler = $this->submitEdit($client, $project, $cardId, ['parent' => (string) $featureId]);

        // The choice list holds epics only, so the field refuses it before the handler runs.
        self::assertResponseStatusCodeSame(422);
        self::assertNotSame('', trim($crawler->filter('[data-card-parent] .lp-field-errors')->text()));
        self::assertNull($this->reload($em, $cardId)->parent);
    }

    public function test_a_child_turned_into_an_epic_shows_the_refusal_on_the_type(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'parent-form-child-epic@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'The epic'), CardType::Epic);
        $card = $this->card($em, $project, 'The child');
        $card->parent = $epic;
        $em->flush();
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $this->submitEdit($client, $project, $cardId, ['type' => CardType::Epic->value]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A card with a parent cannot become an epic.', $crawler->filter('main')->text());
        self::assertSame(CardType::Feature, $this->reload($em, $cardId)->type);
    }

    public function test_an_epic_with_an_open_child_is_not_saved_into_done(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'parent-form-epic-done@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'The epic', 'in-progress'), CardType::Epic);
        $child = $this->card($em, $project, 'The child');
        $child->parent = $epic;
        $em->flush();
        [$epicId, $number] = [$epic->id, $child->number];
        $done = (string) $this->column($project, 'done')->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $this->submitEdit($client, $project, $epicId, ['column' => $done]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(\sprintf('Move #%d to a done column first.', $number), $crawler->filter('main')->text());
        self::assertSame('in-progress', $this->reload($em, $epicId)->column->slug);
    }

    public function test_an_epic_with_children_is_not_deleted_and_the_card_page_says_why(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'parent-form-delete@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'The epic'), CardType::Epic);
        $child = $this->card($em, $project, 'The child');
        $child->parent = $epic;
        $em->flush();
        $epicId = $epic->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$epicId);
        $client->submit($crawler->filter('form[action$="/delete"]')->form());

        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$epicId);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'This epic has child cards, so it cannot be deleted.');
        $em->clear();
        self::assertInstanceOf(Card::class, $em->find(Card::class, $epicId));
    }

    public function test_a_submitted_parent_must_be_an_epic_of_the_project_other_than_the_card(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'parent-form-candidates@example.com');
        $project = $this->project($em, $owner);
        $other = $this->project($em, $owner, 'Other project');
        $self = $this->typed($em, $this->card($em, $project, 'The card itself'), CardType::Epic);
        $epic = $this->typed($em, $this->card($em, $project, 'An epic'), CardType::Epic);
        $feature = $this->card($em, $project, 'A feature');
        $foreign = $this->typed($em, $this->card($em, $other, 'A foreign epic'), CardType::Epic);
        $factory = static::getContainer()->get(FormFactoryInterface::class);
        $extraOptions = ['projectId' => (string) $project->id, 'excludeCardId' => (string) $self->id];

        foreach ([[$epic, true], [$self, false], [$feature, false], [$foreign, false]] as [$card, $valid]) {
            $field = $factory->create(CardParentAutocompleteField::class, options: ['extra_options' => $extraOptions]);
            $field->submit((string) $card->id);
            self::assertSame($valid, $field->isValid(), $card->title);
        }
    }

    private function reload(EntityManagerInterface $em, ?Uuid $cardId): Card
    {
        $em->clear();

        return $em->find(Card::class, $cardId) ?? throw new \LogicException('The card must exist.');
    }

    private function editUrl(Project $project, ?Uuid $cardId): string
    {
        return '/projects/'.$project->id.'/board/cards/'.$cardId.'/edit';
    }

    /** @param array<string, string> $fields */
    private function submitEdit(KernelBrowser $client, Project $project, ?Uuid $cardId, array $fields): Crawler
    {
        $crawler = $client->request(Request::METHOD_GET, $this->editUrl($project, $cardId));
        self::assertResponseIsSuccessful();

        return $this->submit($client, $crawler, 'Save card', $fields);
    }

    /**
     * Posts the form with these values. The autocomplete select holds only the
     * option it shows, so the values go in by name rather than through the crawler.
     *
     * @param array<string, string> $fields
     */
    private function submit(KernelBrowser $client, Crawler $crawler, string $button, array $fields): Crawler
    {
        $form = $crawler->selectButton($button)->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['create_card_form']);
        foreach ($fields as $name => $value) {
            $values['create_card_form'][$name] = $value;
        }

        return $client->request($form->getMethod(), $form->getUri(), $values);
    }
}
