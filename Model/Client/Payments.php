<?php
/**
 * Copyright © 2018 Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Mollie\Payment\Model\Client;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\OrderRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment as MolliePayment;
use Mollie\Api\Types\PaymentStatus;
use Mollie\Payment\Config;
use Mollie\Payment\Helper\General as MollieHelper;
use Mollie\Payment\Service\Mollie\DashboardUrl;
use Mollie\Payment\Service\Order\BuildTransaction;
use Mollie\Payment\Service\Order\OrderCommentHistory;
use Mollie\Payment\Service\Order\Transaction;
use Mollie\Payment\Service\Order\TransactionProcessor;

/**
 * Class Payments
 *
 * @package Mollie\Payment\Model\Client
 */
class Payments extends AbstractModel
{

    const CHECKOUT_TYPE = 'payment';

    /**
     * @var MollieHelper
     */
    private $mollieHelper;
    /**
     * @var OrderRepository
     */
    private $orderRepository;
    /**
     * @var OrderSender
     */
    private $orderSender;
    /**
     * @var InvoiceSender
     */
    private $invoiceSender;
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;
    /**
     * @var OrderCommentHistory
     */
    private $orderCommentHistory;
    /**
     * @var BuildTransaction
     */
    private $buildTransaction;
    /**
     * @var Config
     */
    private $config;
    /**
     * @var DashboardUrl
     */
    private $dashboardUrl;
    /**
     * @var Transaction
     */
    private $transaction;
    /**
     * @var TransactionProcessor
     */
    private $transactionProcessor;

    /**
     * @var EventManager
     */
    private $eventManager;

    /**
     * Payments constructor.
     *
     * @param OrderSender $orderSender
     * @param InvoiceSender $invoiceSender
     * @param OrderRepository $orderRepository
     * @param CheckoutSession $checkoutSession
     * @param MollieHelper $mollieHelper
     * @param OrderCommentHistory $orderCommentHistory
     * @param BuildTransaction $buildTransaction
     * @param Config $config
     * @param DashboardUrl $dashboardUrl
     * @param Transaction $transaction
     * @param TransactionProcessor $transactionProcessor
     * @param EventManager $eventManager
     */
    public function __construct(
        OrderSender $orderSender,
        InvoiceSender $invoiceSender,
        OrderRepository $orderRepository,
        CheckoutSession $checkoutSession,
        MollieHelper $mollieHelper,
        OrderCommentHistory $orderCommentHistory,
        BuildTransaction $buildTransaction,
        Config $config,
        DashboardUrl $dashboardUrl,
        Transaction $transaction,
        TransactionProcessor $transactionProcessor,
        EventManager $eventManager
    ) {
        $this->orderSender = $orderSender;
        $this->invoiceSender = $invoiceSender;
        $this->orderRepository = $orderRepository;
        $this->checkoutSession = $checkoutSession;
        $this->mollieHelper = $mollieHelper;
        $this->orderCommentHistory = $orderCommentHistory;
        $this->buildTransaction = $buildTransaction;
        $this->config = $config;
        $this->dashboardUrl = $dashboardUrl;
        $this->transaction = $transaction;
        $this->transactionProcessor = $transactionProcessor;
        $this->eventManager = $eventManager;
    }

    /**
     * @param Order                       $order
     * @param \Mollie\Api\MollieApiClient $mollieApi
     *
     * @return string
     * @throws \Mollie\Api\Exceptions\ApiException
     */
    public function startTransaction(Order $order, $mollieApi)
    {
        $storeId = $order->getStoreId();
        $orderId = $order->getEntityId();
        $additionalData = $order->getPayment()->getAdditionalInformation();

        $transactionId = $order->getMollieTransactionId();
        if (!empty($transactionId) && !preg_match('/^ord_\w+$/', $transactionId)) {
            $payment = $mollieApi->payments->get($transactionId);
            return $payment->getCheckoutUrl();
        }

        $paymentToken = $this->mollieHelper->getPaymentToken();
        $method = $this->mollieHelper->getMethodCode($order);
        $paymentData = [
            'amount'         => $this->mollieHelper->getOrderAmountByOrder($order),
            'description'    => $this->mollieHelper->getPaymentDescription($method, $order->getIncrementId(), $storeId),
            'billingAddress' => $this->getAddressLine($order->getBillingAddress()),
            'redirectUrl'    => $this->transaction->getRedirectUrl($order, $paymentToken),
            'webhookUrl'     => $this->transaction->getWebhookUrl($storeId),
            'method'         => $method,
            'metadata'       => [
                'order_id'      => $orderId,
                'store_id'      => $order->getStoreId(),
                'payment_token' => $paymentToken
            ],
            'locale'         => $this->mollieHelper->getLocaleCode($storeId, self::CHECKOUT_TYPE)
        ];

        if (!$order->getIsVirtual() && $order->hasData('shipping_address_id')) {
            $paymentData['shippingAddress'] = $this->getAddressLine($order->getShippingAddress());
        }

        if ($method == 'banktransfer') {
            $paymentData['billingEmail'] = $order->getCustomerEmail();
            $paymentData['dueDate'] = $this->mollieHelper->getBanktransferDueDate($storeId);
        }

        if ($method == 'przelewy24') {
            $paymentData['billingEmail'] = $order->getCustomerEmail();
        }

        $paymentData = $this->buildTransaction->execute($order, static::CHECKOUT_TYPE, $paymentData);

        $paymentData = $this->mollieHelper->validatePaymentData($paymentData);
        $this->mollieHelper->addTolog('request', $paymentData);
        $payment = $mollieApi->payments->create($paymentData);
        $this->processResponse($order, $payment);

        return $payment->getCheckoutUrl();
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderAddressInterface $address
     *
     * @return array
     */
    public function getAddressLine($address)
    {
        return [
            'streetAndNumber' => rtrim(implode(' ', $address->getStreet()), ' '),
            'postalCode'      => $address->getPostcode(),
            'city'            => $address->getCity(),
            'region'          => $address->getRegion(),
            'country'         => $address->getCountryId(),
        ];
    }

    /**
     * @param Order $order
     * @param MolliePayment $payment
     */
    public function processResponse(Order $order, $payment)
    {
        $eventData = [
            'order' => $order,
            'mollie_payment' => $payment,
        ];

        $this->eventManager->dispatch('mollie_process_response', $eventData);
        $this->eventManager->dispatch('mollie_process_response_payments_api', $eventData);

        $this->mollieHelper->addTolog('response', $payment);
        $order->getPayment()->setAdditionalInformation('checkout_url', $payment->getCheckoutUrl());
        $order->getPayment()->setAdditionalInformation('checkout_type', self::CHECKOUT_TYPE);
        $order->getPayment()->setAdditionalInformation('payment_status', $payment->status);
        if (isset($payment->expiresAt)) {
            $order->getPayment()->setAdditionalInformation('expires_at', $payment->expiresAt);
        }

        $status = $this->mollieHelper->getPendingPaymentStatus($order);

        $order->addStatusToHistory($status, __('Customer redirected to Mollie'), false);
        $order->setMollieTransactionId($payment->id);
        $this->orderRepository->save($order);
    }

    /**
     * @param Order                       $order
     * @param \Mollie\Api\MollieApiClient $mollieApi
     * @param string                      $type
     * @param null                        $paymentToken
     *
     * @return array
     * @throws LocalizedException
     * @throws \Mollie\Api\Exceptions\ApiException
     */
    public function processTransaction(Order $order, $mollieApi, $type = 'webhook', $paymentToken = null)
    {
        $orderId = $order->getId();
        $storeId = $order->getStoreId();
        $transactionId = $order->getMollieTransactionId();
        $paymentData = $mollieApi->payments->get($transactionId);
        $this->mollieHelper->addTolog($type, $paymentData);
        $dashboardUrl = $this->dashboardUrl->forPaymentsApi($order->getStoreId(), $paymentData->id);
        $order->getPayment()->setAdditionalInformation('dashboard_url', $dashboardUrl);
        $order->getPayment()->setAdditionalInformation('mollie_id', $paymentData->id);

        $status = $paymentData->status;
        $payment = $order->getPayment();
        if ($type == 'webhook' && $payment->getAdditionalInformation('payment_status') != $status) {
            $payment->setAdditionalInformation('payment_status', $status);
            $this->orderRepository->save($order);
        }

        $refunded = isset($paymentData->_links->refunds) ? true : false;

        if ($status == 'paid' && !$refunded) {
            $amount = $paymentData->amount->value;
            $currency = $paymentData->amount->currency;
            $orderAmount = $this->mollieHelper->getOrderAmountByOrder($order);
            if ($currency != $orderAmount['currency']) {
                $msg = ['success' => false, 'status' => 'paid', 'order_id' => $orderId, 'type' => $type];
                $this->mollieHelper->addTolog('error', __('Currency does not match.'));
                return $msg;
            }
            if ($paymentData->details !== null) {
                $payment->setAdditionalInformation('details', json_encode($paymentData->details));
            }

            if (!$payment->getIsTransactionClosed() && $type == 'webhook') {
                if ($order->isCanceled()) {
                    $order = $this->mollieHelper->uncancelOrder($order);
                }

                if (abs($amount - $orderAmount['value']) < 0.01) {
                    $payment->setTransactionId($transactionId);
                    $payment->setCurrencyCode($order->getBaseCurrencyCode());
                    $payment->setIsTransactionClosed(true);
                    $payment->registerCaptureNotification($order->getBaseGrandTotal(), true);
                    $order->setState(Order::STATE_PROCESSING);
                    $this->transactionProcessor->process($order, null, $paymentData);

                    if ($paymentData->settlementAmount !== null) {
                        if ($paymentData->amount->currency != $paymentData->settlementAmount->currency) {
                            $message = __(
                                'Mollie: Captured %1, Settlement Amount %2',
                                $paymentData->amount->currency . ' ' . $paymentData->amount->value,
                                $paymentData->settlementAmount->currency . ' ' . $paymentData->settlementAmount->value
                            );
                            $this->orderCommentHistory->add($order, $message);
                        }
                    }

                    if (!$order->getIsVirtual()) {
                        $defaultStatusProcessing = $this->mollieHelper->getStatusProcessing($storeId);
                        if ($defaultStatusProcessing && ($defaultStatusProcessing != $order->getStatus())) {
                            $order->setStatus($defaultStatusProcessing);
                        }
                    }

                    $this->orderRepository->save($order);
                }

                /** @var Order\Invoice $invoice */
                $invoice = $payment->getCreatedInvoice();
                $sendInvoice = $this->mollieHelper->sendInvoice($storeId);

                if (!$order->getEmailSent()) {
                    try {
                        $this->orderSender->send($order);
                        $message = __('New order email sent');
                        $this->orderCommentHistory->add($order, $message, true);
                    } catch (\Throwable $exception) {
                        $message = __('Unable to send the new order email: %1', $exception->getMessage());
                        $this->orderCommentHistory->add($order, $message, false);
                    }
                }

                if ($invoice && !$invoice->getEmailSent() && $sendInvoice) {
                    try {
                        $this->invoiceSender->send($invoice);
                        $message = __('Notified customer about invoice #%1', $invoice->getIncrementId());
                        $this->orderCommentHistory->add($order, $message, true);
                    } catch (\Throwable $exception) {
                        $message = __('Unable to send the invoice: %1', $exception->getMessage());
                        $this->orderCommentHistory->add($order, $message, true);
                    }
                }
            }

            $msg = ['success' => true, 'status' => 'paid', 'order_id' => $orderId, 'type' => $type];
            $this->mollieHelper->addTolog('success', $msg);
            $this->checkCheckoutSession($order, $paymentToken, $paymentData, $type);
            return $msg;
        }
        if ($refunded) {
            $msg = ['success' => true, 'status' => 'refunded', 'order_id' => $orderId, 'type' => $type];
            $this->mollieHelper->addTolog('success', $msg);
            return $msg;
        }
        if ($status == 'open') {
            if ($paymentData->method == 'banktransfer' && !$order->getEmailSent()) {
                try {
                    $this->orderSender->send($order);
                    $message = __('New order email sent');
                } catch (\Throwable $exception) {
                    $message = __('Unable to send the new order email: %1', $exception->getMessage());
                }

                if (!$statusPending = $this->mollieHelper->getStatusPendingBanktransfer($storeId)) {
                    $statusPending = $order->getStatus();
                }
                $order->setState(Order::STATE_PENDING_PAYMENT);
                $this->transactionProcessor->process($order, null, $paymentData);
                $order->addStatusToHistory($statusPending, $message, true);
                $this->orderRepository->save($order);
            }
            $msg = ['success' => true, 'status' => 'open', 'order_id' => $orderId, 'type' => $type];
            $this->mollieHelper->addTolog('success', $msg);
            $this->checkCheckoutSession($order, $paymentToken, $paymentData, $type);
            return $msg;
        }
        if ($status == 'pending') {
            $msg = ['success' => true, 'status' => 'pending', 'order_id' => $orderId, 'type' => $type];
            $this->mollieHelper->addTolog('success', $msg);
            return $msg;
        }
        if ($status == 'canceled' || $status == 'failed' || $status == 'expired') {
            if ($type == 'webhook') {
                $this->mollieHelper->registerCancellation($order, $status);
                $order->cancel();
                $this->transactionProcessor->process($order, null, $paymentData);
            }

            $msg = ['success' => false, 'status' => $status, 'order_id' => $orderId, 'type' => $type];
            $this->mollieHelper->addTolog('success', $msg);
            return $msg;
        }
        $msg = ['success' => false, 'status' => $status, 'order_id' => $orderId, 'type' => $type];
        $this->mollieHelper->addTolog('success', $msg);
        return $msg;
    }

    public function orderHasUpdate(OrderInterface $order, MollieApiClient $mollieApi)
    {
        $transactionId = $order->getMollieTransactionId();
        $paymentData = $mollieApi->payments->get($transactionId);

        $mapping = [
            PaymentStatus::STATUS_OPEN => Order::STATE_NEW,
            PaymentStatus::STATUS_PENDING => Order::STATE_PENDING_PAYMENT,
            PaymentStatus::STATUS_AUTHORIZED => Order::STATE_PROCESSING,
            PaymentStatus::STATUS_CANCELED => Order::STATE_CANCELED,
            PaymentStatus::STATUS_EXPIRED => Order::STATE_CLOSED,
            PaymentStatus::STATUS_PAID => Order::STATE_PROCESSING,
            PaymentStatus::STATUS_FAILED => Order::STATE_CANCELED,
        ];

        $expectedStatus = $mapping[$paymentData->status];

        return $expectedStatus != $order->getState();
    }

    /**
     * @param Order $order
     * @param       $paymentToken
     * @param       $paymentData
     * @param       $type
     */
    public function checkCheckoutSession(Order $order, $paymentToken, $paymentData, $type)
    {
        if ($type == 'webhook') {
            return;
        }
        if ($this->checkoutSession->getLastOrderId() != $order->getId()) {
            if ($paymentToken && isset($paymentData->metadata->payment_token)) {
                if ($paymentToken == $paymentData->metadata->payment_token) {
                    $this->checkoutSession->setLastQuoteId($order->getQuoteId())
                        ->setLastSuccessQuoteId($order->getQuoteId())
                        ->setLastOrderId($order->getId())
                        ->setLastRealOrderId($order->getIncrementId());
                }
            }
        }
    }
}
