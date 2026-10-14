<?php
/**
 * @package   Angeo_WebMcp
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\WebMcp\Controller\Cart;

use Angeo\WebMcp\Model\Cart\CartService;
use Angeo\WebMcp\Model\Config;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * GET /webmcp/cart/view — backend of the get_cart WebMCP tool.
 *
 * Read-only; never creates a cart. Returns only what the mini cart shows.
 * Sent with no-store so no cache keeps one shopper's cart.
 *
 * @since 1.0.0
 */
class View implements HttpGetActionInterface
{
    /**
     * @param JsonFactory $jsonFactory
     * @param CartService $cartService
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CartService $cartService,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store', true);

        if (!$this->config->isToolEnabled('cart')) {
            return $result->setHttpResponseCode(404)->setData(['ok' => false, 'error' => 'disabled']);
        }

        try {
            return $result->setData(['ok' => true, 'cart' => $this->cartService->view()]);
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo_WebMcp] get_cart failed: ' . $e->getMessage());
            return $result->setHttpResponseCode(500)->setData(['ok' => false, 'error' => 'server_error']);
        }
    }
}
