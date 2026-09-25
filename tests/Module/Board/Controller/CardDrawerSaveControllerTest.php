<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\TurboBundle;

/** How the card drawer saves: in place, with the progress, the clash and the deleted card it shows. */
final class CardDrawerSaveControllerTest extends WebTestCase
{
    use BoardScenario;

    private const array DRAWER = ['HTTP_TURBO_FRAME' => 'card-drawer-frame'];

    public function test_a_drawer_save_renders_the_saved_form_in_place(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'drawer-save@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Before', body: 'Old body');
        $url = '/projects/'.$project->id.'/board/cards/'.$card->id.'/edit';
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url, server: self::DRAWER);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="create_card_form"]');
        self::assertSame((string) $card->id, $form->attr('data-card-drawer-card-id'));
        self::assertNull($form->attr('data-submit-feedback-saved-value'));
        self::assertSame(Card::contentFingerprint('Before', 'Old body'), $crawler->filter('#create_card_form_contentFingerprint')->attr('value'));
        self::assertSame('Saving…', $crawler->filter('button[data-turbo-submits-with]')->first()->attr('data-turbo-submits-with'));
        // Enter in a field submits the first button, which must never overwrite.
        self::assertSame('Save card', trim($form->filter('[type="submit"]')->first()->text()));

        $crawler = $client->submitForm('Save card', [
            'create_card_form[title]' => 'After',
            'create_card_form[body]' => 'New body',
        ], serverParameters: self::DRAWER);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('true', $crawler->filter('form[name="create_card_form"]')->attr('data-submit-feedback-saved-value'));
        self::assertSame('After', $crawler->filter('#create_card_form_title')->attr('value'));
        self::assertSame(Card::contentFingerprint('After', 'New body'), $crawler->filter('#create_card_form_contentFingerprint')->attr('value'));
        self::assertCount(1, $crawler->filter('[data-card-drawer-target="clash"][hidden]'));
        $em->clear();
        $saved = $em->find(Card::class, $card->id);
        self::assertInstanceOf(Card::class, $saved);
        self::assertSame(['After', 'New body'], [$saved->title, $saved->body]);
    }

    public function test_a_save_outside_the_drawer_still_redirects_to_the_card(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'page-save@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Before');
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id.'/edit');
        $client->submitForm('Save card', ['create_card_form[title]' => 'After']);

        self::assertResponseRedirects('/projects/'.$project->id.'/board/cards/'.$card->id);
    }

    public function test_a_drawer_create_answers_with_the_card_placed_on_the_board(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'drawer-create@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/new', server: self::DRAWER);
        self::assertSame('Creating…', $crawler->filter('button[data-turbo-submits-with]')->attr('data-turbo-submits-with'));
        self::assertCount(1, $crawler->filter('form[name="create_card_form"][data-card-drawer-creates-card]'));
        $client->submitForm('Create card', ['create_card_form[title]' => 'Placed at once'], serverParameters: self::DRAWER + [
            'HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE.', text/html',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith(TurboBundle::STREAM_MEDIA_TYPE, (string) $client->getResponse()->headers->get('Content-Type'));
        $created = static::getContainer()->get(CardRepository::class)->findOneBy(['title' => 'Placed at once']);
        self::assertInstanceOf(Card::class, $created);
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('action="board-place" target="board-card-'.$created->id.'"', $body);
        self::assertStringContainsString('data-column-id="'.$created->column->id.'"', $body);
    }

    public function test_a_save_over_a_change_it_did_not_see_shows_the_clash_and_keeps_the_text(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'drawer-clash@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Opened', body: 'Opened body');
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id.'/edit', server: self::DRAWER);
        $em->getConnection()->executeStatement("UPDATE board_cards SET body = 'Their body' WHERE id = :id", ['id' => (string) $card->id]);

        $crawler = $client->submitForm('Save card', ['create_card_form[body]' => 'My body'], serverParameters: self::DRAWER);

        self::assertResponseStatusCodeSame(422);
        $clash = $crawler->filter('[data-card-drawer-target="clash"]');
        self::assertNull($clash->attr('hidden'));
        self::assertStringContainsString('This card changed since you opened it', $clash->text());
        self::assertSame('/projects/'.$project->id.'/board/cards/'.$card->id, $clash->filter('a')->attr('href'));
        self::assertSame('My body', $crawler->filter('#create_card_form_body')->text());
        self::assertSame('Their body', $this->storedBody($em, $card));

        $client->submitForm('Save anyway', [], serverParameters: self::DRAWER);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('My body', $this->storedBody($em, $card));
    }

    public function test_a_save_on_a_deleted_card_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'drawer-deleted@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Doomed');
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id.'/edit', server: self::DRAWER);
        $em->getConnection()->executeStatement('DELETE FROM board_cards WHERE id = :id', ['id' => (string) $card->id]);

        $client->submitForm('Save card', ['create_card_form[title]' => 'Too late'], serverParameters: self::DRAWER);

        self::assertResponseStatusCodeSame(404);
    }

    private function storedBody(EntityManagerInterface $em, Card $card): string
    {
        return (string) $em->getConnection()->fetchOne('SELECT body FROM board_cards WHERE id = :id', ['id' => (string) $card->id]);
    }
}
