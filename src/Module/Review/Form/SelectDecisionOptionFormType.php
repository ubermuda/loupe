<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Carries one answer. Both fields are filled by the decision Stimulus controller
 * from the radio the reviewer clicked, the same shape as the comment composer's
 * anchor fields — the radios themselves live in the document's stored HTML,
 * which no form theme rendered and no CSRF token can be baked into.
 *
 * @extends AbstractType<SelectDecisionOptionRequest>
 */
class SelectDecisionOptionFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // required => false on both: the DTO's constraints are the real gate, and
        // the browser's own validation would otherwise block requestSubmit() —
        // an invalid control inside a form carrying `hidden` cannot be focused,
        // so the submit is refused with a console warning and no request.
        $builder->add('decisionId', HiddenType::class, [
            'required' => false,
            'attr' => ['data-decision-target' => 'decisionId'],
        ]);
        // IntegerType rather than HiddenType: the DTO property is an int, and
        // HiddenType applies no transformer, so a submitted "1" would fail to
        // map onto it. The whole form is hidden, so the widget type is invisible.
        $builder->add('optionIndex', IntegerType::class, [
            'required' => false,
            'attr' => ['data-decision-target' => 'optionIndex'],
        ]);
        // A real checkbox, so an unticked one submits nothing and maps to false.
        // The Stimulus controller mirrors the clicked control's own state onto
        // it, and a radio is always ticked by the click that fires this.
        $builder->add('chosen', CheckboxType::class, [
            'required' => false,
            'attr' => ['data-decision-target' => 'chosen'],
        ]);
        // Server-filled, not Stimulus-filled: it names the version whose option
        // list was rendered into the page, so it must come from the render and
        // not from anything the browser could recompute later.
        $builder->add('versionNumber', IntegerType::class, ['required' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SelectDecisionOptionRequest::class]);
    }
}
