<?php

namespace kernpfad\cleverreach\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Product;
use craft\elements\Asset;
use kernpfad\cleverreach\Plugin;

/**
 * Implements CleverReach's "My Content" product-search
 * contract (https://developers.cleverreach.com/mycontent/) against Craft
 * Commerce products, so editors can browse the real shop catalog from
 * inside CleverReach's drag-and-drop email editor.
 *
 * Only ever used when Craft Commerce is installed — see
 * CatalogController, which feature-detects before touching this service.
 */
class CatalogService extends Component
{
    /**
     * Filter definitions shown to the editor when they open the product
     * search inside CleverReach. Kept deliberately generic (free-text
     * search + product type) so it works on any Commerce install without
     * requiring a specific category taxonomy to be configured.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFilters(): array
    {
        $productTypeValues = [['text' => Craft::t('cleverreach', 'Alle'), 'value' => '']];

        $commerce = \craft\commerce\Plugin::getInstance();
        if ($commerce === null) {
            return [
                [
                    'name' => Craft::t('cleverreach', 'Suchbegriff'),
                    'description' => Craft::t('cleverreach', 'Sucht im Produkttitel.'),
                    'required' => false,
                    'query_key' => 'q',
                    'type' => 'input',
                ],
            ];
        }

        foreach ($commerce->getProductTypes()->getAllProductTypes() as $productType) {
            $productTypeValues[] = ['text' => $productType->name, 'value' => (string) $productType->id];
        }

        return [
            [
                'name' => Craft::t('cleverreach', 'Suchbegriff'),
                'description' => Craft::t('cleverreach', 'Sucht im Produkttitel.'),
                'required' => false,
                'query_key' => 'q',
                'type' => 'input',
            ],
            [
                'name' => Craft::t('cleverreach', 'Produkttyp'),
                'required' => false,
                'query_key' => 'productTypeId',
                'type' => 'dropdown',
                'values' => $productTypeValues,
            ],
        ];
    }

    /**
     * @return array{settings: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    public function search(?string $searchTerm, ?string $productTypeId): array
    {
        $query = Product::find()->status('live')->limit(20);

        if ($searchTerm !== null && trim($searchTerm) !== '') {
            $query->search(trim($searchTerm));
        }

        if ($productTypeId !== null && $productTypeId !== '') {
            $query->typeId((int) $productTypeId);
        }

        $items = [];

        foreach ($query->all() as $product) {
            /** @var Product $product */
            $items[] = $this->buildItem($product);
        }

        return [
            'settings' => [
                'type' => 'product',
                'link_editable' => false,
                'link_text_editable' => true,
                'image_size_editable' => true,
            ],
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildItem(Product $product): array
    {
        return [
            'title' => $product->title,
            'description' => $this->getDescription($product),
            'image' => $this->getImageUrl($product),
            'url' => $product->getUrl(),
            'price' => $this->getFormattedPrice($product),
        ];
    }

    private function getDescription(Product $product): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $handle = $settings->catalogDescriptionFieldHandle;

        if ($handle === null || $handle === '') {
            return '';
        }

        $value = $product->getFieldValue($handle);

        return is_string($value) ? $value : (string) $value;
    }

    private function getImageUrl(Product $product): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $handle = $settings->catalogImageFieldHandle;

        if ($handle === null || $handle === '') {
            return '';
        }

        /** @var Asset|null $asset */
        $asset = $product->getFieldValue($handle)?->one();

        if ($asset === null) {
            return '';
        }

        $transformHandle = $settings->catalogImageTransformHandle;

        if ($transformHandle !== null && $transformHandle !== '') {
            return (string) $asset->getUrl($transformHandle);
        }

        return (string) $asset->getUrl();
    }

    private function getFormattedPrice(Product $product): string
    {
        $variant = $product->getDefaultVariant();
        $basePrice = $variant?->getBasePrice();

        if ($basePrice === null) {
            return '';
        }

        // getBasePrice() rather than getPrice(): the latter returns Commerce
        // 5's calculated catalog-pricing-rule price, which depends on a
        // rule cache that may not be populated yet (and can return null in
        // that case). getBasePrice() is always the price actually set on
        // the variant, which is what belongs in a "browse the shop" list.
        //
        // getStore()->getCurrency() returns a Money\Currency object, not a
        // plain code string — it stringifies to "USD" via __toString(),
        // which makes the mistake easy to miss (var_export/echo look
        // correct), but passing the object straight into asCurrency()
        // fails deep inside Yii's NumberFormatter setup. Cast explicitly.
        $currency = (string) $product->getStore()->getCurrency();

        return Craft::$app->getFormatter()->asCurrency($basePrice, $currency);
    }
}
