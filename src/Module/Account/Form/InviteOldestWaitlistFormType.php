<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Binds the admin waitlist page's "invite oldest N" field. The field is a
 * plain `<input type="number" name="invite_oldest_waitlist_form[count]">` in
 * the template rather than a rendered widget. CSRF is already enforced by
 * the `#[CsrfToken('admin-waitlist-invite')]` stateless-token attribute on
 * the controller, so the form's own CSRF extension is disabled here to
 * avoid a redundant second token.
 *
 * @extends AbstractType<InviteOldestWaitlistRequest>
 */
final class InviteOldestWaitlistFormType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('count', IntegerType::class, [
            'required' => false,
            'label' => false,
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InviteOldestWaitlistRequest::class,
            'csrf_protection' => false,
        ]);
    }
}
