<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\adjusters;

use Craft;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\helpers\Currency;
use craft\commerce\models\Discount as DiscountModel;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin;
use craft\commerce\records\Discount as DiscountRecord;

/**
 * Discount Adjuster
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Discount implements AdjusterInterface
{
    // Constants
    // =========================================================================

    /**
     * The discount adjustment type.
     */
    const ADJUSTMENT_TYPE = 'discount';

    // Properties
    // =========================================================================

    /**
     * @var Order
     */
    private $_order;

    /**
     * @var
     */
    private $_discount;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function adjust(Order $order): array
    {
        $this->_order = $order;

        $discounts = Plugin::getInstance()->getDiscounts()->getAllDiscounts();

        // Find discounts with no coupon or the coupon that matches the order.
        $availableDiscounts = [];
        foreach ($discounts as $discount) {
            if ($discount->code == null) {
                $availableDiscounts[] = $discount;
            }

            if ($this->_order->couponCode && (strcasecmp($this->_order->couponCode, $discount->code) == 0)) {
                $availableDiscounts[] = $discount;
            }
        }

        $adjustments = [];
        foreach ($availableDiscounts as $discount) {
            $newAdjustments = $this->_getAdjustments($discount);
            if ($newAdjustments) {
                $adjustments = array_merge($adjustments, $newAdjustments);

                if ($discount->stopProcessing) {
                    break;
                }
            }
        }

        return $adjustments;
    }

    // Private Methods
    // =========================================================================

    /**
     * @param DiscountModel $discount
     * @return OrderAdjustment
     */
    private function _createOrderAdjustment(DiscountModel $discount): OrderAdjustment
    {
        //preparing model
        $adjustment = new OrderAdjustment();
        $adjustment->type = self::ADJUSTMENT_TYPE;
        $adjustment->name = $discount->name;
        $adjustment->orderId = $this->_order->id;
        $adjustment->description = $discount->description;
        $adjustment->sourceSnapshot = $discount->attributes;

        return $adjustment;
    }

    /**
     * @param DiscountModel $discount
     * @return OrderAdjustment[]|false
     */
    private function _getAdjustments(DiscountModel $discount)
    {
        $adjustments = [];

        $this->_discount = $discount;

        // If coupon matches, check the per email usage limit.
        if (strcasecmp($this->_order->couponCode, $discount->code) == 0) {
            if ($this->_order->email && $this->_discount->perEmailLimit) {
                $previousOrders = Plugin::getInstance()->getOrders()->getOrdersByEmail($this->_order->email);

                $usedCount = 0;

                foreach ($previousOrders as $previousOrder) {
                    if (strcasecmp($previousOrder->couponCode, $this->_discount->code) == 0) {
                        ++$usedCount;
                    }
                }

                if ($usedCount >= $this->_discount->perEmailLimit) {
                    return false;
                }
            }
        }

        $now = new \DateTime();
        $from = $this->_discount->dateFrom;
        $to = $this->_discount->dateTo;
        if (($from && $from > $now) || ($to && $to < $now)) {
            return false;
        }

        //checking items
        $matchingQty = 0;
        $matchingTotal = 0;
        $matchingLineIds = [];
        foreach ($this->_order->getLineItems() as $item) {
            if (Plugin::getInstance()->getDiscounts()->matchLineItem($item, $this->_discount)) {
                if (!$this->_discount->allGroups) {
                    $customer = $this->_order->getCustomer();
                    $user = $customer ? $customer->getUser() : null;
                    $userGroups = Plugin::getInstance()->getCustomers()->getUserGroupIdsForUser($user);
                    if ($user && array_intersect($userGroups, $this->_discount->getUserGroupIds())) {
                        $matchingLineIds[] = $item->id;
                        $matchingQty += $item->qty;
                        $matchingTotal += $item->getSubtotal();
                    }
                } else {
                    $matchingLineIds[] = $item->id;
                    $matchingQty += $item->qty;
                    $matchingTotal += $item->getSubtotal();
                }
            }
        }

        if (!$matchingQty) {
            return false;
        }

        // Have they entered a max qty?
        if ($this->_discount->maxPurchaseQty > 0 && $matchingQty > $this->_discount->maxPurchaseQty) {
            return false;
        }

        // Reject if they have not added enough matching items
        if ($matchingQty < $this->_discount->purchaseQty) {
            return false;
        }

        // Reject if the matching items values is not enough
        if ($matchingTotal < $this->_discount->purchaseTotal) {
            return false;
        }

        foreach ($this->_order->getLineItems() as $item) {
            if (in_array($item->id, $matchingLineIds, false)) {
                $adjustment = $this->_createOrderAdjustment($this->_discount);
                $adjustment->lineItemId = $item->id;

                $existingLineItemPrice = ($item->getSubtotal() + $item->getAdjustmentsTotalByType('discount'));

                $amountPerItem = Currency::round($this->_discount->perItemDiscount * $item->qty);

                //Default is percentage off already discounted price
                $amountPercentage = Currency::round($this->_discount->percentDiscount * $existingLineItemPrice);
                if ($this->_discount->percentageOffSubject == DiscountRecord::TYPE_ORIGINAL_SALEPRICE) {
                    $amountPercentage = Currency::round($this->_discount->percentDiscount * $item->getSubtotal());
                }

                $lineItemDiscount = $amountPerItem + $amountPercentage;

                $diff = null;
                // If the discount is now larger than the subtotal only make the discount amount the same as the total of the line.
                if ((($lineItemDiscount + $item->getAdjustmentsTotalByType('discount')) * -1) > $item->getSubtotal()) {
                    $diff = ($lineItemDiscount + $item->getAdjustmentsTotalByType('discount')) - $item->getSubtotal();
                }

                if ($diff !== null) {
                    $adjustment->amount = $diff;
                } else {
                    $adjustment->amount = $lineItemDiscount;
                }

                if ($adjustment->amount != 0) {
                    $adjustments[] = $adjustment;
                }
            }
        }

        foreach ($this->_order->getLineItems() as $item) {
            if (in_array($item->id, $matchingLineIds, false) && $discount->freeShipping) {
                $adjustment = $this->_createOrderAdjustment($this->_discount);
                $shippingCost = $item->getAdjustmentsTotalByType('shipping');
                if ($shippingCost > 0) {
                    $adjustment->lineItemId = $item->id;
                    $adjustment->amount = $shippingCost * -1;
                    $adjustment->description = Craft::t('commerce', 'Remove Shipping Cost');
                    $adjustments[] = $adjustment;
                }
            }
        }

        if ($discount->baseDiscount !== null && $discount->baseDiscount != 0) {
            $baseDiscountAdjustment = $this->_createOrderAdjustment($discount);
            $baseDiscountAdjustment->lineItemId = null;
            $baseDiscountAdjustment->amount = $discount->baseDiscount;
            if ($baseDiscountAdjustment->amount != 0) {
                $adjustments[] = $baseDiscountAdjustment;
            }
        }

        // only display adjustment if an amount was calculated

        if (count($adjustments)) {
            return $adjustments;
        }

        return false;
    }
}
