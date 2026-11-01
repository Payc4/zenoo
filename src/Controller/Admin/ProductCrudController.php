<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ProductCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Product')
            ->setEntityLabelInPlural('Products')
            ->setDefaultSort(['createdAt' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name');
        yield SlugField::new('slug')->setTargetFieldName('name')->setHelp('The web address: /shop/<slug>.');
        yield TextField::new('tagline')->hideOnIndex()->setHelp('One short line, shown under the name and on Stripe’s page.');
        yield TextareaField::new('description')->hideOnIndex();
        yield MoneyField::new('price')->setCurrency('EUR')->setStoredAsCents(false)->setNumDecimals(2)
            ->setHelp('At least €0.50 (Stripe’s minimum).');
        yield TextField::new('image')->hideOnIndex()->setHelp('A file name from public/images, e.g. intro-hero.jpg.');
        yield BooleanField::new('active')->setHelp('Inactive products are hidden and can’t be bought.');
    }
}
