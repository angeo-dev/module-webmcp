<?php
/**
 * @package   Angeo_WebMcp
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\WebMcp\Model\Cart;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A cart problem the agent can fix itself (wrong SKU, missing option, too
 * many items). Carries a machine-readable code and details, so the agent gets
 * the allowed values instead of a dead end.
 *
 * @since 1.0.0
 */
class CartException extends LocalizedException
{
    /**
     * @param Phrase $phrase
     * @param string $errorCode
     * @param array<string, mixed> $details
     */
    public function __construct(
        Phrase $phrase,
        private readonly string $errorCode = 'cart_error',
        private readonly array $details = []
    ) {
        parent::__construct($phrase);
    }

    /**
     * Short machine-readable code, e.g. "options_required".
     *
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Extra data for the agent.
     *
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
