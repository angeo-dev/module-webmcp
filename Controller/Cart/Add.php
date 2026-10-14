<?php
/**
 * @package   Angeo_WebMcp
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\WebMcp\Controller\Cart;

use Angeo\WebMcp\Model\Cart\CartException;
use Angeo\WebMcp\Model\Cart\CartService;
use Angeo\WebMcp\Model\Config;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Psr\Log\LoggerInterface;

/**
 * POST /webmcp/cart/add — backend of the add_to_cart WebMCP tool.
 *
 * A POST in the frontend area, so Magento's CsrfValidator checks the form key
 * before this runs: the same protection as the add-to-cart button. Params:
 * sku, qty, options (JSON object), form_key.
 *
 * @since 1.0.0
 */
class Add implements HttpPostActionInterface
{
    /**
     * @param RequestInterface $request
     * @param JsonFactory $jsonFactory
     * @param JsonSerializer $serializer
     * @param CartService $cartService
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly JsonSerializer $serializer,
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
            $cart = $this->cartService->add(
                (string) $this->request->getParam('sku', ''),
                (float) ($this->request->getParam('qty', 1) ?: 1),
                $this->readOptions()
            );
            return $result->setData(['ok' => true, 'cart' => $cart]);
        } catch (CartException $e) {
            return $result->setHttpResponseCode(422)->setData([
                'ok'      => false,
                'error'   => $e->getErrorCode(),
                'message' => $e->getMessage(),
                'details' => $e->getDetails(),
            ]);
        } catch (LocalizedException $e) {
            // Quote rules (stock, min/max sale qty) speak to the shopper already.
            return $result->setHttpResponseCode(422)->setData([
                'ok'      => false,
                'error'   => 'not_added',
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo_WebMcp] add_to_cart failed: ' . $e->getMessage());
            return $result->setHttpResponseCode(500)->setData([
                'ok'      => false,
                'error'   => 'server_error',
                'message' => (string) __('The product could not be added. Please try on the product page.'),
            ]);
        }
    }

    /**
     * Options arrive as a JSON object string; anything else counts as none.
     *
     * @return array<string, mixed>
     */
    private function readOptions(): array
    {
        $raw = $this->request->getParam('options');
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '' || strlen($raw) > 4096) {
            return [];
        }
        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (\InvalidArgumentException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }
}
