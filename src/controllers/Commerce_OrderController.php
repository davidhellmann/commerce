<?php
namespace Craft;

/**
 * Class Commerce_OrderController
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   http://craftcommerce.com/license Craft Commerce License Agreement
 * @see       http://craftcommerce.com
 * @package   craft.plugins.commerce.controllers
 * @since     1.0
 */
class Commerce_OrderController extends Commerce_BaseController
{
	protected $allowAnonymous = false;

	/**
	 * Index of orders
	 */
	public function actionOrderIndex ()
	{
		$this->requireAdmin();

		// Remove all incomplete carts older than a certain date in config.
		craft()->commerce_cart->purgeIncompleteCarts();

		$this->renderTemplate('commerce/orders/_index');
	}

	/**
	 * @param array $variables
	 *
	 * @throws HttpException
	 */
	public function actionEditOrder (array $variables = [])
	{
		$this->requireAdmin();

		$variables['orderSettings'] = craft()->commerce_orderSettings->getByHandle('order');

		if (empty($variables['order']))
		{
			if (!empty($variables['orderId']))
			{
				$variables['order'] = craft()->commerce_order->getById($variables['orderId']);

				if (!$variables['order']->id)
				{
					throw new HttpException(404);
				}
			}
			else
			{
				$variables['order'] = new Commerce_OrderModel();
			};
		}

		if (!empty($variables['orderId']))
		{
			$variables['title'] = "Order ".substr($variables['order']->number, 0, 7);
		}
		else
		{
			$variables['title'] = Craft::t('Create a new Order');
		}

		$variables['countries'] = craft()->commerce_country->getFormList();
		$variables['states'] = craft()->commerce_state->getGroupedByCountries();

		$variables['orderStatuses'] = \CHtml::listData(craft()->commerce_orderStatus->getAll(),
			'id', 'name');
		if ($variables['order']->orderStatusId == null)
		{
			$variables['orderStatuses'] = ['0' => 'No Status'] + $variables['orderStatuses'];
		}

		$this->prepVariables($variables);

		$this->renderTemplate('commerce/orders/_edit', $variables);
	}

	/**
	 * Modifies the variables of the request.
	 *
	 * @param $variables
	 */
	private function prepVariables (&$variables)
	{
		$variables['tabs'] = [];

		foreach ($variables['orderSettings']->getFieldLayout()->getTabs() as $index => $tab)
		{
			// Do any of the fields on this tab have errors?
			$hasErrors = false;

			if ($variables['order']->hasErrors())
			{
				foreach ($tab->getFields() as $field)
				{
					if ($variables['order']->getErrors($field->getField()->handle))
					{
						$hasErrors = true;
						break;
					}
				}
			}

			$variables['tabs'][] = [
				'label' => Craft::t($tab->name),
				'url'   => '#tab'.($index + 1),
				'class' => ($hasErrors ? 'error' : null)
			];
		}
	}

	/**
	 * Capture Transaction
	 */
	public function actionTransactionCapture ()
	{
		$this->requireAdmin();

		$id = craft()->request->getParam('id');
		$transaction = craft()->commerce_transaction->getById($id);

		if ($transaction->canCapture())
		{
			// capture transaction and display result
			$child = craft()->commerce_payment->captureTransaction($transaction);

			$message = $child->message ? ' ('.$child->message.')' : '';

			if ($child->status == Commerce_TransactionRecord::SUCCESS)
			{
				craft()->commerce_order->updateOrderPaidTotal($child->order);
				craft()->userSession->setNotice(Craft::t('Transaction has been successfully captured: ').$message);
			}
			else
			{
				craft()->userSession->setError(Craft::t('Capturing error: ').$message);
			}
		}
		else
		{
			craft()->userSession->setError(Craft::t('Wrong transaction id'));
		}
	}

	/**
	 * Refund Transaction
	 */
	public function actionTransactionRefund ()
	{
		$this->requireAdmin();

		$id = craft()->request->getParam('id');
		$transaction = craft()->commerce_transaction->getById($id);

		if ($transaction->canRefund())
		{
			// capture transaction and display result
			$child = craft()->commerce_payment->refundTransaction($transaction);

			$message = $child->message ? ' ('.$child->message.')' : '';

			if ($child->status == Commerce_TransactionRecord::SUCCESS)
			{
				craft()->userSession->setNotice(Craft::t('Transaction has been successfully refunded: ').$message);
			}
			else
			{
				craft()->userSession->setError(Craft::t('Refunding error: ').$message);
			}
		}
		else
		{
			craft()->userSession->setError(Craft::t('Wrong transaction id'));
		}
	}

	/**
	 *
	 * @throws Exception
	 * @throws HttpException
	 * @throws \Exception
	 */
	public function actionSaveOrder ()
	{
		$this->requireAdmin();

		$this->requirePostRequest();

		$order = $this->_setOrderFromPost();
		$this->_setContentFromPost($order);

		if (craft()->commerce_order->save($order))
		{
			$this->redirectToPostedUrl($order);
		}

		craft()->userSession->setNotice(Craft::t("Couldn't save order."));
		craft()->urlManager->setRouteVariables([
			'order' => $order
		]);
	}

	/**
	 * @return Commerce_OrderModel
	 * @throws Exception
	 */
	private function _setOrderFromPost ()
	{
		$orderId = craft()->request->getPost('orderId');

		if ($orderId)
		{
			$order = craft()->commerce_order->getById($orderId);

			if (!$order)
			{
				throw new Exception(Craft::t('No order with the ID “{id}”',
					['id' => $orderId]));
			}
		}
		else
		{
			$order = new Commerce_OrderModel();
		}

		$orderStatusId = craft()->request->getPost('orderStatusId');
		if (!$orderStatusId)
		{
			$order->orderStatusId = null;
		}
		else
		{
			$order->orderStatusId = $orderStatusId;
		}

		$order->message = craft()->request->getPost('message');

		/** @var Commerce_AddressModel $billingAddress */
		$billingAddress = Commerce_AddressModel::populateModel(craft()->request->getPost('billingAddress'));
		$order->setBillingAddress($billingAddress);
		$shippingAddress = Commerce_AddressModel::populateModel(craft()->request->getPost('shippingAddress'));
		$order->setShippingAddress($shippingAddress);

		$order->billingAddressId = null;
		$order->shippingAddressId = null;

		return $order;
	}

	/**
	 * @param Commerce_OrderModel $order
	 */
	private function _setContentFromPost ($order)
	{
		$order->setContentFromPost('fields');
	}

	/**
	 * Deletes a order.
	 *
	 * @throws Exception if you try to edit a non existing Id.
	 */
	public function actionDeleteOrder ()
	{
		$this->requireAdmin();

		$this->requirePostRequest();

		$orderId = craft()->request->getRequiredPost('orderId');
		$order = craft()->commerce_order->getById($orderId);

		if (!$order)
		{
			throw new Exception(Craft::t('No order exists with the ID “{id}”.',
				['id' => $orderId]));
		}

		if (craft()->commerce_order->delete($order))
		{
			if (craft()->request->isAjaxRequest())
			{
				$this->returnJson(['success' => true]);
			}
			else
			{
				craft()->userSession->setNotice(Craft::t('Order deleted.'));
				$this->redirectToPostedUrl($order);
			}
		}
		else
		{
			if (craft()->request->isAjaxRequest())
			{
				$this->returnJson(['success' => false]);
			}
			else
			{
				craft()->userSession->setError(Craft::t('Couldn’t delete order.'));
				craft()->urlManager->setRouteVariables(['order' => $order]);
			}
		}
	}
}