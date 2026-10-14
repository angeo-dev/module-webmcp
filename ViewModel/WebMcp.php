<?php
/**
 * @package   Angeo_WebMcp
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\WebMcp\ViewModel;

use Angeo\WebMcp\Model\Config;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Data for the storefront templates. Theme-neutral (Luma and Hyvä): no
 * RequireJS, no Alpine, no inline script — the config goes out as a JSON
 * data block, which strict CSP allows.
 *
 * @since 1.0.0
 */
class WebMcp implements ArgumentInterface
{
    /**
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     * @param Json $json
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
        private readonly Json $json
    ) {
    }

    /**
     * Whether anything should be printed at all.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        $storeId = $this->storeId();
        return $this->config->isEnabled($storeId) && $this->config->getEnabledTools($storeId) !== [];
    }

    /**
     * Chrome origin trial token for the head meta tag.
     *
     * @return string
     */
    public function getOriginTrialToken(): string
    {
        return $this->isActive() ? $this->config->getOriginTrialToken($this->storeId()) : '';
    }

    /**
     * Script config as JSON, safe to print inside a script element.
     *
     * @return string
     */
    public function getConfigJson(): string
    {
        $store = $this->storeManager->getStore();
        $storeId = (int) $store->getId();

        $data = [
            'version'    => Config::MODULE_VERSION,
            'tools'      => $this->config->getEnabledTools($storeId),
            'storeCode'  => (string) $store->getCode(),
            'storeName'  => (string) $store->getFrontendName(),
            'currency'   => (string) $store->getCurrentCurrencyCode(),
            'maxResults' => $this->config->getMaxResults($storeId),
            'maxQty'     => $this->config->getMaxQty($storeId),
            'urls'       => [
                'graphql'  => $this->urlBuilder->getDirectUrl('graphql'),
                'cartAdd'  => $this->urlBuilder->getUrl('webmcp/cart/add'),
                'cartView' => $this->urlBuilder->getUrl('webmcp/cart/view'),
                'cart'     => $this->urlBuilder->getUrl('checkout/cart'),
                'checkout' => $this->urlBuilder->getUrl('checkout'),
            ],
        ];

        $encoded = $this->json->serialize($data);
        // Json::serialize() does not escape "<"; do it so "</script>" in a
        // store name cannot close the element early.
        return str_replace(['<', '>', '&'], ['<', '>', '&'], (string) $encoded);
    }

    /**
     * Current store id.
     *
     * @return int
     */
    private function storeId(): int
    {
        return (int) $this->storeManager->getStore()->getId();
    }
}
