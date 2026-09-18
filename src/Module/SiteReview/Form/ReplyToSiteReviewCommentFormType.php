<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Form;

use App\Module\SiteReview\Entity\SiteReviewComment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ReplyToSiteReviewCommentRequest> */
final class ReplyToSiteReviewCommentFormType extends AbstractType
{
    public static function nameFor(SiteReviewComment $comment, string $surface): string
    {
        return 'site_reply_'.$surface.'_'.$comment->id;
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('body', TextareaType::class, ['label' => 'sitereview.form.reply_to_site_review_comment_form.body.label']);
        $builder->add('submissionId', HiddenType::class, ['error_bubbling' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReplyToSiteReviewCommentRequest::class]);
    }
}
