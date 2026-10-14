<?php
/**
 * @package   Angeo_WebMcp
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */
declare(strict_types=1);

namespace Angeo\WebMcp\Model\Cart;

/**
 * Turns agent-friendly options ({"color": "Blue", "size": "M"}) into the
 * super_attribute map Magento needs ([93 => 50, 141 => 168]).
 *
 * Pure logic: works on the array from
 * Configurable::getConfigurableAttributesAsArray(), so it is unit-tested
 * without Magento.
 *
 * Matching is case-insensitive. An option key may be the attribute code or its
 * label; a value may be the option label or the numeric option id.
 *
 * @since 1.0.0
 */
class OptionResolver
{
    /**
     * Resolve options against configurable attributes.
     *
     * @param array<int|string, array<string, mixed>> $attributes getConfigurableAttributesAsArray() result
     * @param array<string, mixed> $options Options the agent sent
     * @return array{super_attribute: array<int, int>, missing: array<string, string[]>, invalid: array<string, string[]>}
     */
    public function resolve(array $attributes, array $options): array
    {
        $given = [];
        foreach ($options as $key => $value) {
            if (is_scalar($value)) {
                $given[$this->norm((string) $key)] = trim((string) $value);
            }
        }

        $super = [];
        $missing = [];
        $invalid = [];

        foreach ($attributes as $attribute) {
            $attributeId = (int) ($attribute['attribute_id'] ?? 0);
            $code = (string) ($attribute['attribute_code'] ?? '');
            $label = (string) ($attribute['store_label'] ?? $attribute['label'] ?? $code);
            $allowed = $this->valueLabels($attribute);

            $value = $given[$this->norm($code)] ?? $given[$this->norm($label)] ?? null;
            if ($value === null || $value === '') {
                $missing[$code] = $allowed;
                continue;
            }

            $valueIndex = $this->matchValue($attribute, $value);
            if ($valueIndex === null) {
                $invalid[$code] = $allowed;
                continue;
            }
            $super[$attributeId] = $valueIndex;
        }

        return ['super_attribute' => $super, 'missing' => $missing, 'invalid' => $invalid];
    }

    /**
     * Find the option id for a label or id.
     *
     * @param array<string, mixed> $attribute
     * @param string $value
     * @return int|null
     */
    private function matchValue(array $attribute, string $value): ?int
    {
        $wanted = $this->norm($value);
        foreach ((array) ($attribute['values'] ?? []) as $option) {
            if (!is_array($option)) {
                continue;
            }
            $index = (int) ($option['value_index'] ?? 0);
            $labels = [
                (string) ($option['store_label'] ?? ''),
                (string) ($option['label'] ?? ''),
                (string) ($option['default_label'] ?? ''),
            ];
            foreach ($labels as $candidate) {
                if ($candidate !== '' && $this->norm($candidate) === $wanted) {
                    return $index;
                }
            }
            if (ctype_digit($value) && (int) $value === $index) {
                return $index;
            }
        }
        return null;
    }

    /**
     * Labels the agent may choose from, for error messages.
     *
     * @param array<string, mixed> $attribute
     * @return string[]
     */
    private function valueLabels(array $attribute): array
    {
        $labels = [];
        foreach ((array) ($attribute['values'] ?? []) as $option) {
            if (is_array($option)) {
                $label = (string) ($option['store_label'] ?? $option['label'] ?? '');
                if ($label !== '') {
                    $labels[] = $label;
                }
            }
        }
        return array_values(array_unique($labels));
    }

    /**
     * Normalise for comparison.
     *
     * @param string $text
     * @return string
     */
    private function norm(string $text): string
    {
        return mb_strtolower(trim($text));
    }
}
