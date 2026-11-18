<?php

namespace App\Tests\Support;

/** Sample products as Shopify's products.json lists them, for the catalogue and plan tests. */
final class FakeShop
{
    public static function products(): array
    {
        return [
            [
                'id' => 9001, 'title' => 'ZENOO RAW Jautiena šunims', 'handle' => 'raw-jautiena', 'product_type' => 'Šaldytas pašaras', 'tags' => ['šunims', 'raw'],
                'body_html' => '<p>Visavertis pašaras suaugusiems šunims.</p><p>Analitinės sudedamosios dalys: žali baltymai 16,5 %, žali riebalai 12 %, žalia ląsteliena 0,8 %, žali pelenai 2,1 %, drėgmė 67 %.</p><p>Metabolizuojama energija: 1 720 kcal/kg</p>',
                'variants' => [['id' => 91, 'title' => '300 g', 'price' => '28.75', 'grams' => 320, 'available' => true], ['id' => 92, 'title' => '6 x 300 g', 'price' => '150.00', 'grams' => 1900, 'available' => true]],
                'images' => [['src' => 'https://cdn.example/raw.jpg']],
            ],
            [
                'id' => 9002, 'title' => 'ZENOO Freeze-dried topper', 'handle' => 'fd-topper', 'product_type' => 'Topper', 'tags' => ['dogs'],
                'body_html' => '<p>Complementary food for dogs. Energy 450 kcal/100 g. Protein 50 %, fat 30 %, fibre 2 %, ash 6 %, moisture 5 %.</p>',
                'variants' => [['id' => 93, 'title' => '100 g', 'price' => '14.90', 'grams' => 120, 'available' => true]],
                'images' => [],
            ],
            [
                'id' => 9003, 'title' => 'Lašišų aliejus katėms', 'handle' => 'salmon-oil-cats', 'product_type' => 'Papildas', 'tags' => ['katėms'],
                'body_html' => '<p>Papildas katėms.</p>', 'variants' => [['id' => 94, 'title' => '250 ml', 'price' => '12.00', 'grams' => 300, 'available' => true]],
                'images' => [],
            ],
        ];
    }
}
