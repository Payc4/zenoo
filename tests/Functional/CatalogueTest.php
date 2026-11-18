<?php

namespace App\Tests\Functional;

use App\Entity\CatalogueProduct;
use App\Tests\Support\FakeShop;
use App\Tests\Support\FakeStripe;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Roadmap 02/01: the store's products, prices, pack sizes and energy, for plans and the coach. */
final class CatalogueTest extends AppTestCase
{
    private function sync(): string
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:catalogue:sync'));
        $tester->execute([]);
        return $tester->getDisplay();
    }

    public function testSyncReadsPricesPacksAndEnergy(): void
    {
        FakeStripe::$shopProducts = FakeShop::products();
        $this->assertStringContainsString('3 products', $this->sync());

        $raw = $this->em->getRepository(CatalogueProduct::class)->findOneBy(['handle' => 'raw-jautiena']);
        $this->assertSame(172.0, $raw->getKcal100());
        $this->assertSame([300, 1800], array_column($raw->getVariants(), 'packGrams'));
        $this->assertSame('complete', $raw->getKind());
        $this->assertTrue($raw->isUsable());
        $this->assertEqualsWithDelta(150 / 1800, $raw->cheapestPerGram()[1], 0.0001, 'the multipack is cheaper per gram');

        $topper = $this->em->getRepository(CatalogueProduct::class)->findOneBy(['handle' => 'fd-topper']);
        $this->assertSame('topper', $topper->getKind());
        $this->assertSame(450.0, $topper->getKcal100());
        $oil = $this->em->getRepository(CatalogueProduct::class)->findOneBy(['handle' => 'salmon-oil-cats']);
        $this->assertSame('cat', $oil->getSpecies());
        $this->assertFalse($oil->isUsable());

        // An admin's correction survives the next sync; a product that left the store is marked.
        $raw->applyAdmin(165.0, null, null, false, 'Rich in fat for a raw food.');
        $this->em->flush();
        FakeStripe::$shopProducts = \array_slice(FakeShop::products(), 0, 2);
        $this->assertStringContainsString('1 no longer in the store', $this->sync());
        $this->em->clear();
        $this->assertSame(165.0, $this->em->getRepository(CatalogueProduct::class)->findOneBy(['handle' => 'raw-jautiena'])->getKcal100());
        $this->assertFalse($this->em->getRepository(CatalogueProduct::class)->findOneBy(['handle' => 'salmon-oil-cats'])->isActive());
    }

    public function testAdminPage(): void
    {
        FakeStripe::$shopProducts = FakeShop::products();
        $this->client->loginUser($this->createUser('admin@example.com', 'Password123', ['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/admin/catalogue');
        $this->client->submit($crawler->selectButton('Sync from the store now')->form());
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Synced 3 products');
        $this->assertSelectorTextContains('main', '2 now');

        $form = $crawler->filter('#p' . $this->em->getRepository(CatalogueProduct::class)->findOneBy(['handle' => 'fd-topper'])->getId() . ' form')->form(['hidden' => true]);
        $this->client->submit($form);
        $this->em->clear();
        $this->assertTrue($this->em->getRepository(CatalogueProduct::class)->findOneBy(['handle' => 'fd-topper'])->isHidden());
    }
}
