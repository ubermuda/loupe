<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\Card;
use App\Module\SiteReview\Entity\SiteReviewComment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<AttachSiteReviewCommentRequest> */
final class AttachSiteReviewCommentFormType extends AbstractType
{
    public static function nameFor(SiteReviewComment $comment): string
    {
        return 'attach_site_review_comment_'.($comment->id?->toRfc4122() ?? '');
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('card', ChoiceType::class, [
            'choices' => $options['cards'],
            'choice_label' => static fn (Card $card): string => \sprintf('#%d %s', $card->number, $card->title),
            'choice_value' => static fn (?Card $card): string => null === $card ? '' : (string) $card->id,
            'label' => 'board.form.attach_site_review_comment_form.card.label',
            'placeholder' => 'board.form.attach_site_review_comment_form.card.placeholder',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AttachSiteReviewCommentRequest::class]);
        $resolver->setRequired('cards');
        $resolver->setAllowedTypes('cards', Card::class.'[]');
    }
}
