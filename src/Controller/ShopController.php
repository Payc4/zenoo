<?php

namespace App\Controller;

use App\Entity\User;
use App\Payment\StripeCheckout;
use App\Payment\StripeClient;
use App\Payment\StripeException;
use App\Repository\ProductRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Products and checkout. Payment happens on Stripe's hosted Checkout page; the purchase is
 * recorded by the Stripe webhook (WebhookController) or the return page, whichever comes first.
 */
#[Route('/shop')]
class ShopController extends AbstractController
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly StripeClient $stripe,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'app_shop', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('shop/index.html.twig', [
            'products' => $this->products->findActive(),
            'testMode' => $this->stripe->isTestMode(),
        ]);
    }

    /** Stripe sends the buyer back here after paying. */
    #[Route('/return', name: 'app_shop_return', methods: ['GET'], priority: 10)]
    public function return(Request $request, StripeCheckout $checkout): Response
    {
        $sessionId = (string) $request->query->get('session_id', '');
        $session = null;
        $purchase = null;
        try {
            $session = $this->stripe->retrieveCheckoutSession($sessionId);
            $purchase = $checkout->fulfil($session);
        } catch (StripeException $e) {
            $this->logger->warning('Stripe return page could not load the session', ['session' => $sessionId, 'error' => $e->getMessage()]);
        }

        if ($purchase && $purchase->getUser() === $this->getUser()) {
            $this->addFlash('success', 'Thank you! Your order is confirmed.');

            return $this->redirectToRoute('app_account_order', ['id' => $purchase->getId()]);
        }

        return $this->render('shop/return.html.twig', [
            'paid' => $session && StripeCheckout::isPaid($session),
        ]);
    }

    #[Route('/{slug}', name: 'app_product', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function product(string $slug, Request $request): Response
    {
        $product = $this->products->findActiveBySlug($slug) ?? throw $this->createNotFoundException();
        $user = $this->getUser();

        return $this->render('shop/product.html.twig', [
            'product' => $product,
            'owned' => $user instanceof User && $user->hasPurchased($product),
            'cancelled' => $request->query->has('cancelled'),
            'checkoutReady' => $this->stripe->isReady(),
            'testMode' => $this->stripe->isTestMode(),
        ]);
    }

    /** Creates a Stripe Checkout Session and sends the browser to Stripe's payment page. */
    #[Route('/checkout/{slug}', name: 'app_checkout', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function checkout(string $slug, Request $request, StripeCheckout $checkout): Response
    {
        $product = $this->products->findActiveBySlug($slug) ?? throw $this->createNotFoundException();
        $back = $this->redirectToRoute('app_product', ['slug' => $slug]);

        if (!$this->isCsrfTokenValid('checkout-' . $slug, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Your session expired. Please try again.');

            return $back;
        }
        if (!$this->stripe->isReady()) {
            $this->logger->error('Checkout attempted but STRIPE_SECRET_KEY or STRIPE_WEBHOOK_SECRET is not set');
            $this->addFlash('error', 'Payments are switched off at the moment. Please try again later.');

            return $back;
        }

        /** @var User $user */
        $user = $this->getUser();
        try {
            return new RedirectResponse($checkout->start($product, $user), Response::HTTP_SEE_OTHER);
        } catch (StripeException $e) {
            $this->logger->error('Could not create Stripe Checkout Session', ['product' => $product->getId(), 'error' => $e->getMessage()]);
            $this->addFlash('error', 'We couldn’t open the secure checkout. Please try again in a moment.');

            return $back;
        }
    }
}
