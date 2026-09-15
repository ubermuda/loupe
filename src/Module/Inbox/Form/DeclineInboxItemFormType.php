<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
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
        $builder->add('closeNote', TextareaType::class, [
            'required' => false,
            'label' => 'inbox.form.decline_inbox_item_form.close_note.label',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DeclineInboxItemRequest::class]);
    }
}
