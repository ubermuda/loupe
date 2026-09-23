<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Form\CardLinkAutocompleteField;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

final class CardLinkAutocompleteTest extends WebTestCase
{
    use BoardScenario;

    private const string URL_ATTRIBUTE = 'data-symfony--ux-autocomplete--autocomplete-url-value';

    public function test_a_manager_finds_the_project_cards_by_title_newest_first(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'link-search-owner@example.com');
        $project = $this->project($em, $owner);
        $other = $this->project($em, $owner, 'Other project');
        $older = $this->card($em, $project, 'Fix the LOGIN form');
        $newer = $this->card($em, $project, 'Login page copy');
        $this->card($em, $project, 'Unrelated');
        $self = $this->card($em, $project, 'Login card being edited');
        $this->card($em, $other, 'Login in another project');
        $url = $this->url($project, $self);
        $em->clear();

        $client->loginUser($owner);
        $results = $this->search($client, $url, 'login');

        self::assertSame(['#2 Login page copy', '#1 Fix the LOGIN form'], array_column($results, 'text'));
        self::assertSame([(string) $newer->id, (string) $older->id], array_column($results, 'value'));
    }

    #[TestWith(['#3'])]
    #[TestWith(['3'])]
    #[TestWith([' #3 '])]
    public function test_a_number_query_finds_that_card(string $query): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'link-number-owner@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'First');
        $this->card($em, $project, 'Second, card #3 is the next one');
        $this->card($em, $project, 'Third');
        $url = $this->url($project);
        $em->clear();

        $client->loginUser($owner);

        self::assertSame(['#3 Third'], array_column($this->search($client, $url, $query), 'text'));
    }

    public function test_a_wildcard_in_the_query_matches_itself_only(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'link-wildcard-owner@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Plain title');
        $this->card($em, $project, 'Reach 100% coverage');
        $url = $this->url($project);
        $em->clear();

        $client->loginUser($owner);

        self::assertSame(['#2 Reach 100% coverage'], array_column($this->search($client, $url, '%'), 'text'));
    }

    public function test_a_page_holds_twenty_cards(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'link-page-owner@example.com');
        $project = $this->project($em, $owner);
        for ($i = 1; $i <= 21; ++$i) {
            $this->card($em, $project, 'Card '.$i);
        }
        $url = $this->url($project);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['results']);
        self::assertCount(20, $payload['results']);
        self::assertIsString($payload['next_page']);
    }

    public function test_a_signed_in_user_who_cannot_manage_the_project_is_refused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'link-refuse-owner@example.com');
        $stranger = $this->user($em, 'link-refuse-stranger@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Secret title');
        $url = $this->url($project);
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $url.'&query=secret');

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Secret title', (string) $client->getResponse()->getContent());
    }

    public function test_a_request_without_signed_options_is_refused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'link-bare-owner@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Secret title');
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/autocomplete/board_card_link?query=secret');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_an_anonymous_visitor_is_sent_to_the_login_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'link-anonymous-owner@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Secret title');
        $url = $this->url($project);
        $em->clear();

        $client->request(Request::METHOD_GET, $url);

        self::assertResponseRedirects('/login');
    }

    public function test_the_endpoint_is_absent_while_the_board_is_off(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'link-flag-owner@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Secret title');
        $url = $this->url($project);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_submitted_card_must_be_a_candidate(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'link-submit-owner@example.com');
        $project = $this->project($em, $owner);
        $other = $this->project($em, $owner, 'Other project');
        $self = $this->card($em, $project, 'The card itself');
        $sibling = $this->card($em, $project, 'A sibling');
        $foreign = $this->card($em, $other, 'A foreign card');
        $factory = static::getContainer()->get(FormFactoryInterface::class);
        $extraOptions = ['projectId' => (string) $project->id, 'excludeCardId' => (string) $self->id];

        foreach ([[$sibling, true], [$self, false], [$foreign, false]] as [$card, $valid]) {
            $field = $factory->create(CardLinkAutocompleteField::class, options: ['extra_options' => $extraOptions]);
            $field->submit((string) $card->id);
            self::assertSame($valid, $field->isValid(), $card->title);
        }
    }

    /**
     * A row that form_collection_controller.js adds after page load is the
     * prototype markup, so the prototype must carry the Stimulus controller and
     * the signed URL for Tom Select to start on the new row.
     */
    public function test_a_collection_prototype_carries_the_autocomplete_controller(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'link-prototype-owner@example.com');
        $project = $this->project($em, $owner);
        $url = $this->url($project);

        $factory = static::getContainer()->get(FormFactoryInterface::class);
        $form = $factory->create(FormType::class, null, ['csrf_protection' => false])
            ->add('links', CollectionType::class, [
                'entry_type' => CardLinkAutocompleteField::class,
                'entry_options' => ['extra_options' => ['projectId' => (string) $project->id]],
                'allow_add' => true,
            ]);
        $twig = static::getContainer()->get(Environment::class);
        $html = $twig->createTemplate('{{ form_widget(form.links) }}')->render(['form' => $form->createView()]);

        $prototype = html_entity_decode((string) preg_replace('/.*data-prototype="([^"]*)".*/s', '$1', (string) $html));
        self::assertStringContainsString('data-controller="symfony--ux-autocomplete--autocomplete"', $prototype);
        self::assertStringContainsString(htmlspecialchars($url), $prototype);
    }

    /** The URL the card form hands the browser, signed as the bundle signs it. */
    private function url(Project $project, ?Card $exclude = null): string
    {
        $extraOptions = ['projectId' => (string) $project->id];
        if (null !== $exclude) {
            $extraOptions['excludeCardId'] = (string) $exclude->id;
        }

        $factory = static::getContainer()->get(FormFactoryInterface::class);
        $view = $factory->create(CardLinkAutocompleteField::class, options: ['extra_options' => $extraOptions])->createView();
        $url = $view->vars['attr'][self::URL_ATTRIBUTE] ?? null;
        self::assertIsString($url);

        return $url;
    }

    /** @return list<array{value: string, text: string}> */
    private function search(KernelBrowser $client, string $url, string $query): array
    {
        $client->request(Request::METHOD_GET, $url.'&'.http_build_query(['query' => $query]));
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /* @var list<array{value: string, text: string}> */
        return $payload['results'];
    }
}
