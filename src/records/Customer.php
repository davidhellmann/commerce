<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\records;

use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * Customer record.
 *
 * @property Address[] $addresses
 * @property CustomerAddress[] $customerAddresses
 * @property int $id
 * @property int $lastUsedBillingAddressId
 * @property int $lastUsedShippingAddressId
 * @property Order[] $orders
 * @property int $userId
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 2.0
 */
class Customer extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%commerce_customers}}';
    }

    /**
     * @return ActiveQueryInterface
     */
    public function getCustomerAddresses(): ActiveQueryInterface
    {
        return $this->hasMany(CustomerAddress::class, ['customerId' => 'id']);
    }

    /**
     * @return ActiveQueryInterface
     */
    public function getAddresses(): ActiveQueryInterface
    {
        return $this->hasMany(Address::class, ['id' => 'addressId'])->via('customerAddresses');
    }

    /**
     * @return ActiveQueryInterface
     */
    public function getOrders(): ActiveQueryInterface
    {
        return $this->hasMany(Order::class, ['id' => 'customerId']);
    }
}
