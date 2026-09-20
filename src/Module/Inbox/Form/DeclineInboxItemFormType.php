<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<DeclineInboxItemRequest>
 */
class DeclineInboxItemFormType extends AbstractType
{
    public static function nameFor(InboxItem $item): string
    {
        return 'inbox_decline_'.$item->id;
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // The note comes from the item's answer or review note field, which a
        // reader with script copies in. Without script a decline carries none.
        $builder->add('closeNote', HiddenType::class, ['required' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DeclineInboxItemRequest::class]);
    }
}
