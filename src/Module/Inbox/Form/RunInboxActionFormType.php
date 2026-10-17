<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One form for every action of an item: the action is the address a button posts to, and the note
 * is the same text whichever button the owner presses. The placeholder comes from the template.
 *
 * @extends AbstractType<RunInboxActionRequest>
 */
class RunInboxActionFormType extends AbstractType
{
    public static function nameFor(InboxItem $item): string
    {
        return 'inbox_action_'.$item->id;
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('note', TextareaType::class, ['required' => false]);
        $builder->add('sessionPrompt', TextareaType::class, ['required' => false]);
        $builder->add('branch', TextType::class, ['required' => false]);
        $builder->add('runDigest', TextType::class, ['required' => false]);
        // An empty value stays an empty string, so the parameter is checked against its pattern rather than dropped.
        $builder->add('params', CollectionType::class, [
            'entry_type' => TextType::class,
            'entry_options' => ['required' => false, 'empty_data' => ''],
            'allow_add' => true,
            'required' => false,
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RunInboxActionRequest::class]);
    }
}
