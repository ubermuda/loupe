<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use App\Module\Inbox\Entity\InboxItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<SubmitInboxPullRequestReviewRequest> */
final class SubmitInboxPullRequestReviewFormType extends AbstractType
{
    public static function nameFor(InboxItem $item): string
    {
        return 'inbox_review_'.$item->id;
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('verdict', ChoiceType::class, [
            'expanded' => true,
            'placeholder' => false,
            'label' => 'inbox.form.submit_inbox_pull_request_review_form.verdict.label',
            'choices' => [
                'inbox.form.submit_inbox_pull_request_review_form.verdict.approve' => 'approved',
                'inbox.form.submit_inbox_pull_request_review_form.verdict.changes' => 'changes-requested',
            ],
        ]);
        $builder->add('expectedUrl', HiddenType::class, ['error_bubbling' => false]);
        $builder->add('note', TextareaType::class, [
            'required' => false,
            'label' => 'inbox.form.submit_inbox_pull_request_review_form.note.label',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SubmitInboxPullRequestReviewRequest::class]);
    }
}
