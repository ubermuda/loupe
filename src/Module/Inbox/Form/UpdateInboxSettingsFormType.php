<?php

declare(strict_types=1);

namespace App\Module\Inbox\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<UpdateInboxSettingsRequest>
 */
final class UpdateInboxSettingsFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('documentInReview', CheckboxType::class, [
            'required' => false,
            'label' => 'inbox.form.update_inbox_settings_form.document_in_review.label',
            'help' => 'inbox.form.update_inbox_settings_form.document_in_review.help',
        ]);
        $builder->add('runBlocked', CheckboxType::class, [
            'required' => false,
            'label' => 'inbox.form.update_inbox_settings_form.run_blocked.label',
            'help' => 'inbox.form.update_inbox_settings_form.run_blocked.help',
        ]);
        $builder->add('runGaveUp', CheckboxType::class, [
            'required' => false,
            'label' => 'inbox.form.update_inbox_settings_form.run_gave_up.label',
            'help' => 'inbox.form.update_inbox_settings_form.run_gave_up.help',
        ]);
        $builder->add('runWaitingForPerson', CheckboxType::class, [
            'required' => false,
            'label' => 'inbox.form.update_inbox_settings_form.run_waiting_for_person.label',
            'help' => 'inbox.form.update_inbox_settings_form.run_waiting_for_person.help',
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => UpdateInboxSettingsRequest::class]);
    }
}
