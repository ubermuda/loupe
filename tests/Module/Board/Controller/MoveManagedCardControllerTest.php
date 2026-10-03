<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Controller\MoveCardController;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Form\MoveCardFormType;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Tests\Module\Workflow\WorkflowProjects;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\TurboBundle;

final class MoveManagedCardControllerTest extends WebTestCase
{
    use BoardScenario;
    use WorkflowProjects;

    private KernelBrowser $client;
    private Project $project;
    private Card $card;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** The checker must be replaced before any fixture write builds the real one. */
    private function given(bool $canManage = true): void
    {
        if (!$canManage) {
            $this->denyManage();
        }
        $this->enableBoard();

        $em = $this->em();
        $owner = $this->user($em, 'managed-move-'.uniqid().'@example.com');
        $this->project = $this->project($em, $owner, 'managed-move');
        foreach (['product-design', 'tech-design', 'in-review'] as $position => $slug) {
            $em->persist(new BoardColumn(project: $this->project, label: $slug, slug: $slug, position: 10 + $position));
        }
        $em->flush();
        $this->bindLifecycle($this->project);
        $this->card = $this->card($em, $this->project, 'Managed', 'next');

        $this->client->loginUser($owner);
    }

    public function test_a_refused_drop_offers_a_manager_to_make_the_card_unmanaged(): void
    {
        $this->given();
        $this->move(stream: true);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(
            'This card is managed. Make it unmanaged and move it?',
            $this->client->getResponse()->headers->get(MoveCardController::MANAGED_OFFER_HEADER),
        );
        self::assertSame(
            'This card is managed. Make it unmanaged to move it there.',
            $this->client->getResponse()->headers->get(MoveCardController::REFUSAL_HEADER),
        );
        self::assertSame('next', $this->storedColumn());
    }

    public function test_a_refused_drop_makes_no_offer_without_the_right_to_manage(): void
    {
        $this->given(canManage: false);

        $this->move(stream: true);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertFalse($this->client->getResponse()->headers->has(MoveCardController::MANAGED_OFFER_HEADER));
        self::assertSame('next', $this->storedColumn());
    }

    public function test_a_refused_form_move_sends_a_manager_back_to_the_card_with_the_way_out(): void
    {
        $this->given();
        $this->move(stream: false);

        self::assertResponseRedirects('/projects/'.$this->project->id.'/board/cards/'.$this->card->id);
        self::assertSame(['This card is managed. Select Make unmanaged on this page, then move it.'], $this->errorFlashes());
        self::assertSame('next', $this->storedColumn());
    }

    public function test_a_refused_form_move_flashes_the_plain_refusal_without_the_right_to_manage(): void
    {
        $this->given(canManage: false);
        $this->move(stream: false);

        self::assertResponseRedirects('/projects/'.$this->project->id.'/board');
        self::assertSame(['This card is managed. Make it unmanaged to move it there.'], $this->errorFlashes());
        self::assertSame('next', $this->storedColumn());
    }

    /** @return array<mixed> */
    private function errorFlashes(): array
    {
        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return $session->getFlashBag()->peek('error');
    }

    public function test_a_drop_sent_again_with_unmanage_holds_the_card_and_moves_it(): void
    {
        $this->given();
        $this->move(stream: true, unmanage: true);

        self::assertResponseIsSuccessful();
        self::assertSame('in-progress', $this->storedColumn());
        self::assertTrue($this->holds()->isHeld($this->project, $this->cardId()));
    }

    public function test_a_card_already_held_moves_with_unmanage_too(): void
    {
        $this->given();
        $this->holds()->hold($this->project, $this->cardId(), null);

        $this->move(stream: true, unmanage: true);

        self::assertResponseIsSuccessful();
        self::assertSame('in-progress', $this->storedColumn());
    }

    public function test_a_move_that_fails_after_the_offer_is_accepted_leaves_the_card_managed(): void
    {
        $this->given();
        // A card that is no epic cannot be a parent, so the move fails after the hold.
        $notAnEpic = $this->card($this->em(), $this->project, 'Not an epic', 'next');
        $this->em()->clear();

        $this->move(stream: true, unmanage: true, parent: (string) $notAnEpic->id);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('next', $this->storedColumn());
        self::assertFalse($this->holds()->isHeld($this->project, $this->cardId()));
    }

    public function test_unmanage_is_ignored_without_the_right_to_manage(): void
    {
        $this->given(canManage: false);

        $this->move(stream: true, unmanage: true);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('next', $this->storedColumn());
        self::assertFalse($this->holds()->isHeld($this->project, $this->cardId()));
    }

    /** Card writes and project management are both the owner's today, so a checker stands in for a writer who cannot manage. */
    private function denyManage(): void
    {
        $container = static::getContainer();
        $tokens = $container->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $decisions = $container->get('security.access.decision_manager');
        self::assertInstanceOf(AccessDecisionManagerInterface::class, $decisions);
        $real = new AuthorizationChecker($tokens, $decisions);

        $container->set('security.authorization_checker', new readonly class($real) implements AuthorizationCheckerInterface {
            public function __construct(
                private AuthorizationCheckerInterface $real,
            ) {
            }

            #[\Override]
            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return ProjectVoter::MANAGE !== $attribute && $this->real->isGranted($attribute, $subject, $accessDecision);
            }
        });
    }

    private function move(bool $stream, bool $unmanage = false, ?string $parent = null): void
    {
        $url = '/projects/'.$this->project->id.'/board/cards/'.$this->card->id.'/move';
        $server = ['HTTP_REFERER' => 'http://localhost'.$url];
        if ($stream) {
            $server['HTTP_ACCEPT'] = TurboBundle::STREAM_MEDIA_TYPE;
        }

        $this->client->request(Request::METHOD_POST, $url, [MoveCardFormType::nameFor($this->card) => [
            'column' => (string) $this->column($this->project, 'in-progress')->id,
            'position' => '',
            '_token' => 'csrf-token',
            ...($unmanage ? ['unmanage' => '1'] : []),
            ...(null === $parent ? [] : ['parent' => $parent]),
        ]], [], $server);
    }

    private function storedColumn(): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $card = $em->find(Card::class, $this->cardId());
        self::assertInstanceOf(Card::class, $card);

        return $card->column->slug;
    }

    private function holds(): CardHolds
    {
        $holds = static::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);

        return $holds;
    }

    private function cardId(): Uuid
    {
        return $this->card->id ?? throw new \LogicException('A stored card has an id.');
    }
}
