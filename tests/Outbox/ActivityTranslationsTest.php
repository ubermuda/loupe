<?php

declare(strict_types=1);

namespace App\Tests\Outbox;

use App\Module\Board\BoardEventType;
use App\Module\Bridge\BridgeEventType;
use App\Module\Forge\ForgeEventType;
use App\Module\Inbox\InboxEventType;
use App\Module\Project\ProjectEventType;
use App\Module\Review\ReviewEventType;
use App\Outbox\ActivityFamily;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;

final class ActivityTranslationsTest extends KernelTestCase
{
    private const array EVENT_TYPE_CLASSES = [
        BoardEventType::class,
        BridgeEventType::class,
        ForgeEventType::class,
        InboxEventType::class,
        ProjectEventType::class,
        ReviewEventType::class,
    ];

    public function test_every_event_type_has_a_label(): void
    {
        $catalogue = $this->catalogue();
        $types = [];
        foreach (self::EVENT_TYPE_CLASSES as $class) {
            foreach (new \ReflectionClass($class)->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
                $value = $constant->getValue();
                if (\is_string($value) && str_contains($value, '.')) {
                    $types[] = $value;
                }
            }
        }

        self::assertContains(ForgeEventType::MERGED, $types);
        foreach ($types as $type) {
            self::assertTrue($catalogue->has('activity.event_type.'.$type), \sprintf('No label for event type "%s".', $type));
        }
    }

    public function test_every_family_has_a_label(): void
    {
        $catalogue = $this->catalogue();
        foreach (ActivityFamily::cases() as $family) {
            self::assertTrue($catalogue->has($family->translationKey()), \sprintf('No label for family "%s".', $family->value));
        }
    }

    private function catalogue(): MessageCatalogueInterface
    {
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);

        return $translator->getCatalogue('en');
    }
}
