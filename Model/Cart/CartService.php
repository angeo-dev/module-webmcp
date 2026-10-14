<?php
/**
 * @package   Angeo_WebMcp
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\WebMcp\Model\Cart;

use Angeo\WebMcp\Model\Config;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Adds products to, and reads, the shopper's own session cart.
 *
 * Runs with the shopper's session and the same rules as the add-to-cart
 * button: only enabled, visible products of the current website; stock and
 * quantity rules are enforced by Quote::addProduct(). It never places orders.
 *
 * @since 1.0.0
 */
class CartService
{
    /** Product types an agent can add without the product page. */
    private const SUPPORTED_TYPES = [
        ProductType::TYPE_SIMPLE,
        ProductType::TYPE_VIRTUAL,
        Configurable::TYPE_CODE,
    ];

    private const MAX_SKU_LENGTH = 64;

    /**
     * @param CheckoutSession $checkoutSession
     * @param CartRepositoryInterface $cartRepository
     * @param ProductRepositoryInterface $productRepository
     * @param StoreManagerInterface $storeManager
     * @param OptionResolver $optionResolver
     * @param Config $config
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly OptionResolver $optionResolver,
        private readonly Config $config
    ) {
    }

    /**
     * Add a product to the session cart.
     *
     * @param string $sku
     * @param float $qty
     * @param array<string, mixed> $options Configurable options, by code or label
     * @return array<string, mixed> Cart summary after the change
     * @throws CartException
     */
    public function add(string $sku, float $qty, array $options = []): array
    {
        $sku = trim($sku);
        if ($sku === '' || mb_strlen($sku) > self::MAX_SKU_LENGTH) {
            throw new CartException(__('A valid SKU is required.'), 'invalid_sku');
        }

        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();
        $maxQty = $this->config->getMaxQty($storeId);
        if (!is_finite($qty) || $qty <= 0 || $qty > $maxQty) {
            throw new CartException(
                __('Quantity must be more than 0 and at most %1.', $maxQty),
                'invalid_qty',
                ['max_qty' => $maxQty]
            );
        }

        $product = $this->loadSellableProduct($sku, $storeId, (int) $store->getWebsiteId());

        $request = ['qty' => $qty];
        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            $request['super_attribute'] = $this->resolveConfigurable($product, $options);
        } elseif ((bool) $product->getData('required_options')) {
            throw new CartException(
                __('This product needs options that can only be chosen on its product page.'),
                'needs_product_page',
                ['url' => $product->getProductUrl()]
            );
        }

        $quote = $this->checkoutSession->getQuote();
        $result = $quote->addProduct($product, new DataObject($request));
        if (is_string($result)) {
            throw new CartException(__($result), 'not_added');
        }

        $quote->collectTotals();
        $this->cartRepository->save($quote);
        $this->checkoutSession->setQuoteId((int) $quote->getId());

        return $this->summary($quote);
    }

    /**
     * Read the session cart without creating one.
     *
     * @return array<string, mixed>
     */
    public function view(): array
    {
        if (!$this->checkoutSession->getQuoteId()) {
            return $this->emptySummary();
        }
        return $this->summary($this->checkoutSession->getQuote());
    }

    /**
     * Load a product the current shopper could buy.
     *
     * @param string $sku
     * @param int $storeId
     * @param int $websiteId
     * @return Product
     * @throws CartException
     */
    private function loadSellableProduct(string $sku, int $storeId, int $websiteId): Product
    {
        try {
            $product = $this->productRepository->get($sku, false, $storeId);
        } catch (NoSuchEntityException) {
            throw new CartException(__('No product with SKU "%1".', $sku), 'not_found');
        }

        if (!$product instanceof Product
            || (int) $product->getStatus() !== Status::STATUS_ENABLED
            || (int) $product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE
            || !in_array($websiteId, array_map('intval', (array) $product->getWebsiteIds()), true)
        ) {
            // Same answer as a missing SKU: do not reveal hidden products.
            throw new CartException(__('No product with SKU "%1".', $sku), 'not_found');
        }

        if (!in_array($product->getTypeId(), self::SUPPORTED_TYPES, true)) {
            throw new CartException(
                __('This product type must be added on its product page.'),
                'needs_product_page',
                ['url' => $product->getProductUrl()]
            );
        }

        return $product;
    }

    /**
     * Map agent options to super_attribute, or explain what is missing.
     *
     * @param Product $product
     * @param array<string, mixed> $options
     * @return array<int, int>
     * @throws CartException
     */
    private function resolveConfigurable(Product $product, array $options): array
    {
        /** @var Configurable $type */
        $type = $product->getTypeInstance();
        $resolved = $this->optionResolver->resolve($type->getConfigurableAttributesAsArray($product), $options);

        if ($resolved['missing'] || $resolved['invalid']) {
            throw new CartException(
                __('Choose a value for each option, then try again.'),
                'options_required',
                ['missing' => $resolved['missing'], 'invalid' => $resolved['invalid']]
            );
        }
        return $resolved['super_attribute'];
    }

    /**
     * Agent-facing cart summary. Only what the mini cart already shows.
     *
     * @param Quote $quote
     * @return array<string, mixed>
     */
    private function summary(Quote $quote): array
    {
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = [
                'name'      => (string) $item->getName(),
                'sku'       => (string) $item->getSku(),
                'qty'       => (float) $item->getQty(),
                'row_total' => round((float) ($item->getRowTotalInclTax() ?: $item->getRowTotal()), 2),
            ];
        }

        return [
            'items'       => $items,
            'items_qty'   => (float) $quote->getItemsQty(),
            'subtotal'    => round((float) $quote->getSubtotal(), 2),
            'grand_total' => round((float) $quote->getGrandTotal(), 2),
            'currency'    => (string) $quote->getQuoteCurrencyCode(),
        ];
    }

    /**
     * Summary for a shopper without a cart.
     *
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'items'       => [],
            'items_qty'   => 0.0,
            'subtotal'    => 0.0,
            'grand_total' => 0.0,
            'currency'    => (string) $this->storeManager->getStore()->getCurrentCurrencyCode(),
        ];
    }
}
