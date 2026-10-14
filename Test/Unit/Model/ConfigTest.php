<?php
/**
 * @package   Angeo_WebMcp
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\WebMcp\Test\Unit\Model;

use Angeo\WebMcp\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testClamp(): void
    {
        $this->assertSame(10, Config::clamp(0, 10, 50));
        $this->assertSame(10, Config::clamp(-5, 10, 50));
        $this->assertSame(7, Config::clamp(7, 10, 50));
        $this->assertSame(50, Config::clamp(5000, 10, 50));
    }

    public function testToolsOffWhenModuleOff(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => $path !== Config::XML_PATH_ENABLED
        );

        $config = new Config($scope);
        $this->assertFalse($config->isToolEnabled('cart'));
        $this->assertSame([], $config->getEnabledTools());
    }

    public function testUnknownSwitchIsOff(): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(true);

        $config = new Config($scope);
        $this->assertFalse($config->isToolEnabled('place_order'));
        $this->assertSame(Config::TOOL_SWITCHES, $config->getEnabledTools());
    }
}
