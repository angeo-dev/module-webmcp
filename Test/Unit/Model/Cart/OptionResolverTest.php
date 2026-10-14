<?php
/**
 * @package   Angeo_WebMcp
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\WebMcp\Test\Unit\Model\Cart;

use Angeo\WebMcp\Model\Cart\OptionResolver;
use PHPUnit\Framework\TestCase;

class OptionResolverTest extends TestCase
{
    /**
     * Shape of Configurable::getConfigurableAttributesAsArray().
     *
     * @return array<int, array<string, mixed>>
     */
    private function attributes(): array
    {
        return [
            93 => [
                'attribute_id' => '93',
                'attribute_code' => 'color',
                'label' => 'Color',
                'store_label' => 'Colour',
                'values' => [
                    ['value_index' => '50', 'label' => 'Blue', 'store_label' => 'Blue'],
                    ['value_index' => '57', 'label' => 'Grey', 'store_label' => 'Grey'],
                ],
            ],
            141 => [
                'attribute_id' => '141',
                'attribute_code' => 'size',
                'label' => 'Size',
                'store_label' => 'Size',
                'values' => [
                    ['value_index' => '167', 'label' => 'S', 'store_label' => 'S'],
                    ['value_index' => '168', 'label' => 'M', 'store_label' => 'M'],
                ],
            ],
        ];
    }

    public function testResolvesByCodeCaseInsensitive(): void
    {
        $result = (new OptionResolver())->resolve($this->attributes(), ['COLOR' => 'grey', 'size' => ' m ']);

        $this->assertSame([93 => 57, 141 => 168], $result['super_attribute']);
        $this->assertSame([], $result['missing']);
        $this->assertSame([], $result['invalid']);
    }

    public function testResolvesByStoreLabelAndOptionId(): void
    {
        $result = (new OptionResolver())->resolve($this->attributes(), ['Colour' => '50', 'Size' => 'S']);

        $this->assertSame([93 => 50, 141 => 167], $result['super_attribute']);
    }

    public function testReportsMissingWithAllowedValues(): void
    {
        $result = (new OptionResolver())->resolve($this->attributes(), ['color' => 'Blue']);

        $this->assertSame(['size' => ['S', 'M']], $result['missing']);
        $this->assertSame([93 => 50], $result['super_attribute']);
    }

    public function testReportsInvalidValue(): void
    {
        $result = (new OptionResolver())->resolve($this->attributes(), ['color' => 'Purple', 'size' => 'M']);

        $this->assertSame(['color' => ['Blue', 'Grey']], $result['invalid']);
    }

    public function testIgnoresNonScalarInput(): void
    {
        $result = (new OptionResolver())->resolve($this->attributes(), ['color' => ['Blue'], 'size' => 'M']);

        $this->assertArrayHasKey('color', $result['missing']);
    }
}
