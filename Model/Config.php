<?php
/**
 * @package   Angeo_WebMcp
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\WebMcp\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Configuration helper. All nodes under angeo_webmcp/*.
 *
 * @since 1.0.0
 */
class Config
{
    public const MODULE_VERSION = '1.0.1';

    public const XML_PATH_ENABLED      = 'angeo_webmcp/general/enabled';
    public const XML_PATH_OT_TOKEN     = 'angeo_webmcp/general/origin_trial_token';
    public const XML_PATH_TOOL_PREFIX  = 'angeo_webmcp/tools/';
    public const XML_PATH_MAX_RESULTS  = 'angeo_webmcp/limits/max_results';
    public const XML_PATH_MAX_QTY      = 'angeo_webmcp/limits/max_qty';

    /** Hard ceilings, whatever the admin types in. */
    public const HARD_MAX_RESULTS = 50;
    public const HARD_MAX_QTY     = 10000;

    /** Tool switches as stored in config. "cart" covers add_to_cart and get_cart. */
    public const TOOL_SWITCHES = ['search_products', 'get_product', 'cart', 'go_to_checkout'];

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether the module is on for the store.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Whether one tool switch is on. Always false when the module is off.
     *
     * @param string $switch One of TOOL_SWITCHES
     * @param int|null $storeId
     * @return bool
     */
    public function isToolEnabled(string $switch, ?int $storeId = null): bool
    {
        if (!in_array($switch, self::TOOL_SWITCHES, true) || !$this->isEnabled($storeId)) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_TOOL_PREFIX . $switch,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Enabled tool switches.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getEnabledTools(?int $storeId = null): array
    {
        return array_values(array_filter(
            self::TOOL_SWITCHES,
            fn (string $switch): bool => $this->isToolEnabled($switch, $storeId)
        ));
    }

    /**
     * Chrome origin trial token, trimmed. Empty string when not set.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getOriginTrialToken(?int $storeId = null): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_OT_TOKEN, ScopeInterface::SCOPE_STORE, $storeId);
        return trim((string) $value);
    }

    /**
     * Max products a search may return.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getMaxResults(?int $storeId = null): int
    {
        return self::clamp(
            (int) $this->scopeConfig->getValue(self::XML_PATH_MAX_RESULTS, ScopeInterface::SCOPE_STORE, $storeId),
            10,
            self::HARD_MAX_RESULTS
        );
    }

    /**
     * Max quantity one add_to_cart call may add.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getMaxQty(?int $storeId = null): int
    {
        return self::clamp(
            (int) $this->scopeConfig->getValue(self::XML_PATH_MAX_QTY, ScopeInterface::SCOPE_STORE, $storeId),
            100,
            self::HARD_MAX_QTY
        );
    }

    /**
     * Keep a configured number inside 1..$max; use $default when unset or invalid.
     *
     * @param int $value
     * @param int $default
     * @param int $max
     * @return int
     */
    public static function clamp(int $value, int $default, int $max): int
    {
        if ($value < 1) {
            return $default;
        }
        return min($value, $max);
    }
}
