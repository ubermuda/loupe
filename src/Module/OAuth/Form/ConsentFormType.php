<?php

declare(strict_types=1);

namespace App\Module\OAuth\Form;

use App\Module\Project\Entity\Project;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The project choice lists only the projects the user owns, so a submitted id
 * for any other project fails validation before the handler runs.
 *
 * @extends AbstractType<ConsentRequest>
 */
class ConsentFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<Project> $projects */
        $projects = $options['projects'];
        $names = [];
        foreach ($projects as $project) {
            $names[(string) $project->id] = $project->name;
        }

        if ($options['needs_project']) {
            $builder->add('project', ChoiceType::class, [
                'label' => 'oauth.form.consent_form.project.label',
                'placeholder' => 'oauth.form.consent_form.project.placeholder',
                'choices' => array_keys($names),
                'choice_label' => static fn (string $id): string => $names[$id],
                'choice_translation_domain' => false,
            ]);
        }

        $builder
            ->add('approve', SubmitType::class, ['label' => 'oauth.form.consent_form.approve.label'])
            ->add('deny', SubmitType::class, ['label' => 'oauth.form.consent_form.deny.label', 'validation_groups' => false]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        // A session-stored token, not the stateless 'submit' one: that one needs
        // csrf_protection_controller.js, and a click before it loads got a 422.
        $resolver->setDefaults(['data_class' => ConsentRequest::class, 'csrf_token_id' => 'oauth-consent']);
        $resolver->setRequired(['projects', 'needs_project']);
        $resolver->setAllowedTypes('projects', 'array');
        $resolver->setAllowedTypes('needs_project', 'bool');
    }
}
