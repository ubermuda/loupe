<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One answer to one question. The option controls post nothing themselves:
 * the inbox answer controller copies the ticked indexes into selectedOptions,
 * as the decision controller does for a document's decision block.
 *
 * @extends AbstractType<AnswerInboxItemRequest>
 */
class AnswerInboxItemFormType extends AbstractType
{
    public static function nameFor(InboxItem $item): string
    {
        return 'inbox_answer_'.$item->id;
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('selectedOptions', HiddenType::class, [
            'required' => false,
            'attr' => ['data-inbox-answer-target' => 'selectedOptions'],
        ]);
        $builder->add('answerText', TextareaType::class, [
            'required' => false,
            'label' => 'inbox.form.answer_inbox_item_form.answer_text.label',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AnswerInboxItemRequest::class]);
    }
}
