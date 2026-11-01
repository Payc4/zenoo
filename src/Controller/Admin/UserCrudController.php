<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Length;

class UserCrudController extends AbstractCrudController
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Pet parent')
            ->setEntityLabelInPlural('Pet parents')
            ->setSearchFields(['email', 'firstName', 'lastName'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield EmailField::new('email');
        yield TextField::new('firstName');
        yield TextField::new('lastName')->hideOnIndex();
        yield ChoiceField::new('roles', 'Roles')
            ->setChoices(['Pet parent' => 'ROLE_USER', 'Admin' => 'ROLE_ADMIN'])
            ->allowMultipleChoices()
            ->renderExpanded()
            ->renderAsBadges(['ROLE_USER' => 'secondary', 'ROLE_ADMIN' => 'success'])
            ->setHelp('Every account is a pet parent. Tick Admin to also give access to this panel.');
        yield TextField::new('plainPassword', $pageName === Crud::PAGE_NEW ? 'Password' : 'New password')
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('attr', ['autocomplete' => 'new-password'])
            ->setFormTypeOption('constraints', [new Length(min: 8)])
            ->setRequired($pageName === Crud::PAGE_NEW)
            ->setHelp($pageName === Crud::PAGE_NEW ? 'At least 8 characters.' : 'Leave empty to keep the current password.')
            ->onlyOnForms();
        yield AssociationField::new('purchases')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Joined')->hideOnForm();
        yield DateTimeField::new('lastLoginAt', 'Last login')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $em, $entity): void
    {
        $this->hashPassword($entity);
        parent::persistEntity($em, $entity);
    }

    public function updateEntity(EntityManagerInterface $em, $entity): void
    {
        $this->hashPassword($entity);
        parent::updateEntity($em, $entity);
    }

    private function hashPassword(User $user): void
    {
        if ($user->getPlainPassword()) {
            $user->setPassword($this->hasher->hashPassword($user, $user->getPlainPassword()));
            $user->setPlainPassword(null);
        }
    }
}
