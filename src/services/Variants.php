<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\services;

use Craft;
use craft\base\ElementInterface;
use craft\commerce\elements\Variant;
use yii\base\Component;

/**
 * Variant service.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Variants extends Component
{
    // Constants
    // =========================================================================

    // Public Methods
    // =========================================================================

    /**
     * Returns a product's variants, per the product's ID.
     *
     * @param int $productId product ID
     * @param int|null $siteId Site ID for which to return the variants. Defaults to `null` which is current site.
     * @return Variant[]
     */
    public function getAllVariantsByProductId(int $productId, int $siteId = null): array
    {
        $variants = Variant::find()->productId($productId)->status(null)->limit(null)->siteId($siteId)->all();

        return $variants;
    }

    /**
     * Returns the first variant, per its product's ID.
     *
     * @param int $productId the product's ID
     * @param int|null $siteId Site ID for which to return the variant. Defaults to `null` which is current site.
     * @return Variant
     */
    public function getDefaultVariantByProductId(int $productId, int $siteId = null): Variant
    {
        return $this->getAllVariantsByProductId($productId, $siteId)[0];
    }

    /**
     * Returns a variant by its ID.
     *
     * @param int $variantId The variant’s ID.
     * @param int|null $siteId The site ID for which to fetch the variant. Defaults to `null` which is current site.
     * @return ElementInterface|null
     */
    public function getVariantById(int $variantId, int $siteId = null)
    {
        return Craft::$app->getElements()->getElementById($variantId, Variant::class, $siteId);
    }
}
