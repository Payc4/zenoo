<?php

namespace App\Tests\Functional;

use App\Entity\Product;
use App\Entity\User;
use App\Tests\Support\FakeStripe;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Fresh SQLite schema, an empty fake Stripe and an empty cache for every test. */
abstract class AppTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->followRedirects(false);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        static::getContainer()->get('cache.app')->clear();
        FakeStripe::reset();
        \App\Tests\Support\FakeClaude::reset();
    }

    protected function createUser(string $email = 'maria@example.com', string $password = 'Password123', array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setFirstName('Maria')->setRoles($roles);
        $user->setPassword(static::getContainer()->get('security.user_password_hasher')->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function createProduct(string $slug = 'test-product', string $price = '1.00', bool $active = true): Product
    {
        $product = (new Product())->setName('ZENOO Test Product')->setSlug($slug)->setPrice($price)->setTagline('A test')->setActive($active);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    /** Logs in through the real form, so CSRF, the authenticator and the limiter all run. */
    protected function loginViaForm(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('#panel-password form')->form(['_username' => $email, '_password' => $password]);
        $this->client->submit($form);
    }

    protected function signedWebhook(array $event): void
    {
        $payload = json_encode($event);
        $signature = static::getContainer()->get(\App\Payment\StripeClient::class)->signPayload($payload);
        $this->client->request('POST', '/webhook/stripe', [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    protected function paidSession(User $user, Product $product, string $id = 'cs_test_paid_1', int $amount = 100): array
    {
        return [
            'id' => $id, 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => 'paid', 'livemode' => false,
            'amount_total' => $amount, 'currency' => 'eur', 'payment_intent' => 'pi_' . $id, 'client_reference_id' => (string) $user->getId(),
            'metadata' => ['user_id' => (string) $user->getId(), 'product_id' => (string) $product->getId()], 'total_details' => ['amount_discount' => 0],
        ];
    }

    protected function flashes(): array
    {
        return $this->client->getCrawler()->filter('.flash')->each(fn ($n) => trim($n->text()));
    }
}
