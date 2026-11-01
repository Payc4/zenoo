<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\Purchase;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function index(): Response
    {
        $paid = $this->em->createQuery('SELECT COUNT(p.id) AS n, COALESCE(SUM(p.amount), 0) AS total FROM App\Entity\Purchase p WHERE p.status = :s AND p.testMode = false')
            ->setParameter('s', Purchase::STATUS_COMPLETED)
            ->getSingleResult();

        return $this->render('admin/dashboard.html.twig', [
            'users' => $this->em->getRepository(User::class)->count([]),
            'products' => $this->em->getRepository(Product::class)->count(['active' => true]),
            'purchases' => $this->em->getRepository(Purchase::class)->count(['status' => Purchase::STATUS_COMPLETED]),
            'liveOrders' => (int) $paid['n'],
            'liveRevenue' => (float) $paid['total'],
            'latest' => $this->em->getRepository(Purchase::class)->findBy([], ['createdAt' => 'DESC'], 8),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<img src="/images/logo-forest.png" alt="ZENOO" style="height:28px;vertical-align:middle"> <span style="font-size:13px;opacity:.7">Admin</span>')
            ->setFaviconPath('favicon.png')
            ->generateRelativeUrls();
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('css/admin.css');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Overview', 'fa fa-home');
        yield MenuItem::section('Shop');
        yield MenuItem::linkToCrud('Products', 'fas fa-box', Product::class);
        yield MenuItem::linkToCrud('Purchases', 'fas fa-receipt', Purchase::class);
        yield MenuItem::section('Accounts');
        yield MenuItem::linkToCrud('Pet parents', 'fas fa-users', User::class);
        yield MenuItem::section();
        yield MenuItem::linkToUrl('View the site', 'fas fa-arrow-up-right-from-square', '/');
        yield MenuItem::linkToLogout('Log out', 'fas fa-sign-out-alt');
    }
}
