<?php

namespace App\Controller\Admin;

use App\Catalogue\CatalogueSync;
use App\Entity\CatalogueProduct;
use App\Repository\CatalogueProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The main store's products as the app sees them: sync from Shopify, then check each one's kind,
 * species and energy (kcal per 100 g). Only products marked usable feed the personal plan and the coach.
 */
#[Route('/admin/catalogue')]
#[IsGranted('ROLE_ADMIN')]
final class CatalogueController extends AbstractController
{
    public function __construct(
        private readonly CatalogueProductRepository $products,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'admin_catalogue', methods: ['GET'])]
    public function index(CatalogueSync $sync): Response
    {
        $all = $this->products->findBy([], ['active' => 'DESC', 'title' => 'ASC']);
        return $this->render('admin/catalogue/index.html.twig', [
            'products' => $all,
            'usable' => \count(array_filter($all, fn (CatalogueProduct $p) => $p->isUsable())),
            'store' => $sync->storeUrl(),
            'kinds' => CatalogueProduct::KINDS,
            'species' => CatalogueProduct::SPECIES,
        ]);
    }

    #[Route('/sync', name: 'admin_catalogue_sync', methods: ['POST'])]
    public function sync(Request $request, CatalogueSync $sync, LoggerInterface $logger): Response
    {
        if ($this->isCsrfTokenValid('catalogue-sync', (string) $request->request->get('_token'))) {
            try {
                $r = $sync->sync();
                $this->addFlash('success', sprintf('Synced %d products (%d new, %d gone). %d have an energy value.', $r['found'], $r['created'], $r['gone'], $r['withKcal']));
            } catch (\Throwable $e) {
                $logger->error('Catalogue sync failed', ['exception' => $e]);
                $this->addFlash('error', 'The sync failed: ' . $e->getMessage());
            }
        }
        return $this->redirectToRoute('admin_catalogue');
    }

    #[Route('/{id}', name: 'admin_catalogue_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(CatalogueProduct $product, Request $request): Response
    {
        if ($this->isCsrfTokenValid('catalogue' . $product->getId(), (string) $request->request->get('_token'))) {
            $kcal = trim((string) $request->request->get('kcal', ''));
            $product->applyAdmin(
                $kcal === '' ? null : (float) str_replace(',', '.', $kcal),
                $request->request->get('kind') ?: null,
                $request->request->get('species') ?: null,
                $request->request->getBoolean('hidden'),
                (string) $request->request->get('notes', ''),
            );
            $this->em->flush();
            $this->addFlash('success', 'Saved: ' . $product->getTitle());
        }
        return $this->redirect($this->generateUrl('admin_catalogue') . '#p' . $product->getId());
    }
}
