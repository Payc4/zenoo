<?php

namespace App\Controller\Admin;

use App\Entity\Purchase;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

/**
 * Read-only: purchases are created by Stripe payments and changed by Stripe refunds.
 * To refund, use the Stripe dashboard; the webhook marks the purchase refunded here.
 */
class PurchaseCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Purchase::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Purchase')
            ->setEntityLabelInPlural('Purchases')
            ->setSearchFields(['stripeSessionId', 'stripePaymentIntentId', 'user.email'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp('index', 'Purchases come from Stripe. Refund in the Stripe dashboard and the status updates here automatically.');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices(['Completed' => Purchase::STATUS_COMPLETED, 'Refunded' => Purchase::STATUS_REFUNDED]))
            ->add(BooleanFilter::new('testMode'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield AssociationField::new('user', 'Pet parent');
        yield AssociationField::new('product');
        yield MoneyField::new('amount')->setCurrency('EUR')->setStoredAsCents(false)->setNumDecimals(2);
        yield MoneyField::new('discountAmount', 'Discount')->setCurrency('EUR')->setStoredAsCents(false)->setNumDecimals(2)->onlyOnDetail();
        yield ChoiceField::new('status')->setChoices(['Completed' => Purchase::STATUS_COMPLETED, 'Refunded' => Purchase::STATUS_REFUNDED])
            ->renderAsBadges([Purchase::STATUS_COMPLETED => 'success', Purchase::STATUS_REFUNDED => 'warning']);
        yield BooleanField::new('testMode', 'Test')->renderAsSwitch(false);
        yield TextField::new('stripeSessionId', 'Stripe session')->onlyOnDetail();
        yield TextField::new('stripePaymentIntentId', 'Stripe payment')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Paid at');
    }
}
