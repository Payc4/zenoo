<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    public const MIN_PASSWORD = 8;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'First name',
                'attr' => ['autocomplete' => 'given-name'],
                'constraints' => [
                    new NotBlank(message: 'Please enter your first name.'),
                    new Length(max: 100),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last name',
                'required' => false,
                'attr' => ['autocomplete' => 'family-name'],
                'constraints' => [new Length(max: 100)],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => ['label' => 'Password', 'attr' => ['autocomplete' => 'new-password'], 'help' => 'At least 8 characters.'],
                'second_options' => ['label' => 'Repeat password', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'The two passwords don’t match.',
                'constraints' => [
                    new NotBlank(message: 'Please choose a password.'),
                    new Length(min: self::MIN_PASSWORD, max: 4096, minMessage: 'Your password needs at least {{ limit }} characters.'),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'label' => 'I agree to the privacy policy',
                'mapped' => false,
                'constraints' => [new IsTrue(message: 'Please agree to the privacy policy to continue.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'turnstile_enabled' => true,
        ]);
    }
}
