<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ReplyToInboxItemRequest> */
final class ReplyToInboxItemFormType extends AbstractType
{
    public static function nameFor(InboxItem $item): string
    {
        return 'inbox_reply_'.$item->id;
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('body', TextareaType::class, ['label' => 'inbox.form.reply_to_inbox_item_form.body.label']);
        $builder->add('submissionId', HiddenType::class, ['error_bubbling' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReplyToInboxItemRequest::class]);
    }
}
