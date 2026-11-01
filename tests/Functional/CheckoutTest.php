<?php

namespace App\Tests\Functional;

use App\Entity\Purchase;
use App\Tests\Support\FakeStripe;

class CheckoutTest extends AppTestCase
{
    public function testShopAndProductPages(): void
    {
        $this->createProduct();
        $this->createProduct('hidden', '2.00', false);

        $this->client->request('GET', '/shop');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.product-card', 'ZENOO Test Product');
        $this->assertSelectorCount(1, '.product-card');

        $this->client->request('GET', '/shop/hidden');
        $this->assertResponseStatusCodeSame(404, 'inactive products are hidden');

        $this->client->request('GET', '/shop/test-product');
        $this->assertSelectorTextContains('.price', '€1.00');
        $this->assertSelectorTextContains('.product-layout a.btn', 'Log in to buy');
    }

    public function testCheckoutNeedsLogin(): void
    {
        $this->createProduct();
        $this->client->request('POST', '/shop/checkout/test-product');
        $this->assertResponseRedirects('/login');
        $this->assertCount(0, FakeStripe::$requests);
    }

    public function testCheckoutSendsTheRightSessionToStripe(): void
    {
        $user = $this->createUser();
        $product = $this->createProduct();
        $this->loginViaForm('maria@example.com', 'Password123');

        $crawler = $this->client->request('GET', '/shop/test-product');
        $this->client->submit($crawler->selectButton('Buy now')->form());

        $this->assertResponseStatusCodeSame(303);
        $this->assertStringStartsWith('https://checkout.stripe.com/', $this->client->getResponse()->headers->get('Location'));

        $p = FakeStripe::lastCheckoutParams();
        $this->assertSame('payment', $p['mode']);
        $this->assertSame('eur', $p['line_items'][0]['price_data']['currency']);
        $this->assertSame('100', $p['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('maria@example.com', $p['customer_email']);
        $this->assertSame((string) $user->getId(), $p['metadata']['user_id']);
        $this->assertSame((string) $product->getId(), $p['metadata']['product_id']);
        $this->assertStringContainsString('/shop/return?session_id={CHECKOUT_SESSION_ID}', $p['success_url']);
        $this->assertNotEmpty(FakeStripe::$requests[0]['idempotencyKey']);
    }

    public function testCheckoutNeedsCsrfToken(): void
    {
        $this->createUser();
        $this->createProduct();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->client->request('POST', '/shop/checkout/test-product', ['_csrf_token' => 'forged']);
        $this->assertResponseRedirects('/shop/test-product');
        $this->assertCount(0, FakeStripe::$requests);
    }

    public function testStripeDownShowsAFriendlyMessage(): void
    {
        $this->createUser();
        $this->createProduct();
        $this->loginViaForm('maria@example.com', 'Password123');
        FakeStripe::$down = true;

        $crawler = $this->client->request('GET', '/shop/test-product');
        $this->client->submit($crawler->selectButton('Buy now')->form());
        $this->assertResponseRedirects('/shop/test-product');
        $this->client->followRedirect();
        $this->assertContains('We couldn’t open the secure checkout. Please try again in a moment.', $this->flashes());
    }

    public function testReturnPageRecordsThePurchaseAndEmailsAReceipt(): void
    {
        $user = $this->createUser();
        $product = $this->createProduct();
        $this->loginViaForm('maria@example.com', 'Password123');
        FakeStripe::$sessions['cs_test_paid_1'] = $this->paidSession($user, $product);

        $this->client->request('GET', '/shop/return?session_id=cs_test_paid_1');
        $purchase = $this->em->getRepository(Purchase::class)->findOneBy(['stripeSessionId' => 'cs_test_paid_1']);
        $this->assertNotNull($purchase);
        $this->assertResponseRedirects('/account/orders/' . $purchase->getId());
        $this->assertSame('1.00', $purchase->getAmount());
        $this->assertTrue($purchase->isTestMode());
        $this->assertEmailCount(1);
        $this->assertEmailHeaderSame($this->getMailerMessage(), 'Subject', 'Your ZENOO order #' . $purchase->getId());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('h1', 'ZENOO Test Product');

        // The webhook arriving afterwards changes nothing and sends no second email.
        $this->signedWebhook(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => $this->paidSession($user, $product)]]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->em->getRepository(Purchase::class)->count([]));
    }

    public function testUnpaidSessionRecordsNothing(): void
    {
        $user = $this->createUser();
        $product = $this->createProduct();
        $this->loginViaForm('maria@example.com', 'Password123');
        FakeStripe::$sessions['cs_test_open'] = ['payment_status' => 'unpaid'] + $this->paidSession($user, $product, 'cs_test_open');

        $this->client->request('GET', '/shop/return?session_id=cs_test_open');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Almost there');
        $this->assertSame(0, $this->em->getRepository(Purchase::class)->count([]));
    }

    public function testWebhookRecordsOnceAndRejectsForgeries(): void
    {
        $user = $this->createUser();
        $product = $this->createProduct();
        $event = ['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => $this->paidSession($user, $product)]];

        $this->client->request('POST', '/webhook/stripe', [], [], ['HTTP_STRIPE_SIGNATURE' => 't=' . time() . ',v1=' . str_repeat('0', 64)], json_encode($event));
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(0, $this->em->getRepository(Purchase::class)->count([]));

        $this->signedWebhook($event);
        $this->assertResponseIsSuccessful();
        $this->assertEmailCount(1);
        $this->signedWebhook($event);
        $this->assertResponseIsSuccessful();
        $this->assertSame(1, $this->em->getRepository(Purchase::class)->count([]), 'Stripe retries never double-record');
        $this->assertEmailCount(0, null, 'and no second receipt');
    }

    public function testOldWebhookTimestampIsRejected(): void
    {
        $payload = json_encode(['id' => 'evt', 'type' => 'charge.refunded', 'data' => ['object' => []]]);
        $old = time() - 3600;
        $signature = static::getContainer()->get(\App\Payment\StripeClient::class)->signPayload($payload, $old);
        $this->client->request('POST', '/webhook/stripe', [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testRefundWebhookMarksPurchaseRefunded(): void
    {
        $user = $this->createUser();
        $product = $this->createProduct();
        $this->signedWebhook(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => $this->paidSession($user, $product)]]);

        $this->signedWebhook(['id' => 'evt_2', 'type' => 'charge.refunded', 'data' => ['object' => ['payment_intent' => 'pi_cs_test_paid_1', 'refunded' => false]]]);
        $this->em->clear();
        $this->assertSame('completed', $this->em->getRepository(Purchase::class)->findOneBy([])->getStatus(), 'partial refund keeps it');

        $this->signedWebhook(['id' => 'evt_3', 'type' => 'charge.refunded', 'data' => ['object' => ['payment_intent' => 'pi_cs_test_paid_1', 'refunded' => true]]]);
        $this->em->clear();
        $this->assertSame('refunded', $this->em->getRepository(Purchase::class)->findOneBy([])->getStatus());
    }

    public function testOrdersArePrivate(): void
    {
        $maria = $this->createUser();
        $this->createUser('jonas@example.com');
        $product = $this->createProduct();
        $this->signedWebhook(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => $this->paidSession($maria, $product)]]);
        $id = $this->em->getRepository(Purchase::class)->findOneBy([])->getId();

        $this->loginViaForm('jonas@example.com', 'Password123');
        $this->client->request('GET', '/account/orders/' . $id);
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/logout');
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->client->request('GET', '/account/orders/' . $id);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/account');
        $this->assertSelectorTextContains('.order-title', 'ZENOO Test Product');
    }
}
