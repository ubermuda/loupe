<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\LabelTone;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardParentPolicy;
use App\Module\Board\Service\CardTypeCatalog;
use App\Module\Board\Service\CardTypeDefinition;
use App\Module\Board\Service\CardTypes;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** The template decides which type may be a parent, so a type other than epic can be one. */
final class CardParentPolicyTest extends TestCase
{
    private Project $project;

    #[\Override]
    protected function setUp(): void
    {
        $this->project = new Project(new User(fullName: 'Owner', email: 'owner@example.com', password: 'hashed'), 'p');
    }

    public function test_a_card_of_a_type_with_the_children_capability_can_be_a_parent(): void
    {
        $parent = $this->card('initiative', 1);

        self::assertNull($this->policy()->refusal($this->project, null, 'feature', $parent, true));
    }

    public function test_a_card_of_a_type_without_the_children_capability_cannot_be_a_parent(): void
    {
        $parent = $this->card('epic', 1);

        $refusal = $this->policy()->refusal($this->project, null, 'feature', $parent, true);

        self::assertSame(['parent' => CardParentPolicy::NOT_EPIC], $refusal?->errors);
    }

    public function test_a_type_with_the_children_capability_cannot_have_a_parent(): void
    {
        $refusal = $this->policy()->refusal($this->project, null, 'initiative', $this->card('initiative', 1), true);

        self::assertSame(['parent' => CardParentPolicy::EPIC_WITH_PARENT], $refusal?->errors);
    }

    public function test_a_parent_type_the_template_dropped_cannot_be_a_parent(): void
    {
        $refusal = $this->policy()->refusal($this->project, null, 'feature', $this->card('retired', 1), true);

        self::assertSame(['parent' => CardParentPolicy::NOT_EPIC], $refusal?->errors);
    }

    public function test_a_card_with_children_cannot_leave_a_type_with_the_children_capability(): void
    {
        $card = $this->card('initiative', 1);

        $refusal = $this->policy(children: 2)->refusal($this->project, $card, 'feature', null, false);

        self::assertSame(['type' => CardParentPolicy::TYPE_LOCKED], $refusal?->errors);
    }

    public function test_a_card_without_children_can_leave_a_type_with_the_children_capability(): void
    {
        $card = $this->card('initiative', 1);

        self::assertNull($this->policy(children: 0)->refusal($this->project, $card, 'feature', null, false));
    }

    private function policy(int $children = 0): CardParentPolicy
    {
        $cards = $this->createStub(CardRepository::class);
        $cards->method('freshType')->willReturnCallback(static fn (Card $card): string => $card->type);
        $cards->method('countChildren')->willReturn($children);

        return new CardParentPolicy($cards, new class implements CardTypeCatalog {
            #[\Override]
            public function forProject(Project $project): CardTypes
            {
                return new CardTypes([
                    new CardTypeDefinition('feature', 'feature', LabelTone::Lime, false, false),
                    new CardTypeDefinition('epic', 'epic', LabelTone::Blue, false, false),
                    new CardTypeDefinition('initiative', 'initiative', LabelTone::Purple, true, true),
                ], 'feature');
            }
        });
    }

    private function card(string $type, int $number): Card
    {
        $column = new BoardColumn(project: $this->project, label: 'Column', slug: 'column', position: 0);
        $card = new Card(project: $this->project, column: $column, title: 'Card', body: '', number: $number, type: $type);
        new \ReflectionProperty(Card::class, 'id')->setValue($card, Uuid::v7());

        return $card;
    }
}
