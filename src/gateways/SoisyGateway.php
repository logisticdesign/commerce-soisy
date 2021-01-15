<?php

/**
 * Soisy plugin for Craft CMS 3.x
 *
 * Soisy payment gateway for Craft Commerce
 *
 * @link      https://logisticdesign.it
 * @copyright Copyright (c) 2021 Logistic Design srl
 */

namespace logisticdesign\soisy\gateways;

use Craft;
use craft\commerce\base\Gateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\elements\Order;
use craft\commerce\errors\NotImplementedException;
use craft\commerce\errors\PaymentException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\helpers\UrlHelper;
use craft\web\Response as WebResponse;
use Exception;
use GuzzleHttp\Client;
use logisticdesign\soisy\responses\SoisyResponse;

class SoisyGateway extends Gateway
{
    /**
     * Soisy Shop ID
     *
     * @var string
     */
    public $shopId;

    /**
     * Soisy Authentication Token
     *
     * @var string
     */
    public $authToken;

    /**
     * Sandbox mode enabled.
     *
     * @var bool
     */
    public $sandboxEnabled;

    /**
     * Live API Endpoint.
     */
    CONST API_URL = 'https://api.soisy.it/api';

    /**
     * Sandbox API Endpoint.
     */
    CONST SANDBOX_API_URL = 'https://api.sandbox.soisy.it/api';

    /**
     * Minimum amount valid for order.
     */
    const MIN_AMOUNT = 100;

    /**
     * Maximum amount valid for order.
     */
    const MAX_AMOUNT = 15000;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'Soisy');
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('commerce-soisy/settings', [
            'gateway' => $this,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormHtml(array $params)
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new OffsitePaymentForm;
    }

    /**
     * Sandbox Mode is enabled?
     *
     * @return bool
     */
    public function isSandboxEnabled()
    {
        return !! $this->sandboxEnabled;
    }

    /**
     * API Client.
     *
     * @return \GuzzleHttp\Client
     */
    public function getClient()
    {
        $endpoint = $this->isSandboxEnabled() ? self::SANDBOX_API_URL : self::API_URL;

        return new Client([
            'base_uri' => "{$endpoint}/shops/{$this->shopId}/",
            'headers' => [
                'X-Auth-Token' => $this->authToken
            ],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function availableForUseWithOrder(Order $order): bool
    {
        if ( ! Craft::$app->request->getIsSiteRequest()) {
            return false;
        }

        return $order->total >= self::MIN_AMOUNT and $order->total <= self::MAX_AMOUNT;
    }

    /**
     * @inheritdoc
     */
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return $this->createSoisyOrder($transaction);
    }

    /**
     * @inheritdoc
     */
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return $this->createSoisyOrder($transaction);
    }

    /**
     * @inheritdoc
     */
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        return new SoisyResponse([
            'success' => true,
            'transactionHash' => $transaction->hash,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        $this->throwUnsupportedFunctionalityException();
    }

    /**
     * @inheritdoc
     */
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        $this->throwUnsupportedFunctionalityException();
    }

    /**
     * @inheritdoc
     */
    public function createPaymentSource(BasePaymentForm $sourceData, int $userId): PaymentSource
    {
        $this->throwUnsupportedFunctionalityException();
    }

    /**
     * @inheritdoc
     */
    public function deletePaymentSource($token): bool
    {
        $this->throwUnsupportedFunctionalityException();
    }

    /**
     * @inheritdoc
     */
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        $this->throwUnsupportedFunctionalityException();
    }

    /**
     * @inheritdoc
     */
    public function processWebHook(): WebResponse
    {
        return $this->handleSoisyCallback();
    }

    /**
     * @inheritdoc
     */
    public function supportsAuthorize(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsCapture(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsCompleteAuthorize(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function supportsCompletePurchase(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function supportsPaymentSources(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function supportsPurchase(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function supportsRefund(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function supportsPartialRefund(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function supportsWebhooks(): bool
    {
        return true;
    }


    // -----------------------------------------------------------------------------------------------------------------
    // -----------------------------------------------------------------------------------------------------------------


    protected function createSoisyOrder(Transaction $transaction): RequestResponseInterface
    {
        $order = $transaction->getOrder();
        $billingAddress = $order->getBillingAddress();

        $data = [
            'email' => $order->email,
            'firstname' => $billingAddress->firstName,
            'lastname' => $billingAddress->lastName,
            'amount' => $order->total * 100,
            'city' => $billingAddress->city,
            'address' => $billingAddress->address1,
            'postalCode' => $billingAddress->zipCode,
            'successUrl' => UrlHelper::url($order->returnUrl),
            'errorUrl' => UrlHelper::url($order->cancelUrl),
            'callbackUrl' => $this->getWebhookUrl(),
            'orderReference' => $transaction->hash,
        ];

        try {
            $apiResponse = $this->getClient()->post('orders', [
                'json' => $data,
            ]);
        } catch (Exception $e) {
            throw new PaymentException($e->getMessage());
        }

        $apiResponse = json_decode($apiResponse->getBody(), true) + [
            'code' => $apiResponse->getStatusCode(),
            'transactionHash' => $transaction->hash,
        ];

        return new SoisyResponse($apiResponse);
    }

    protected function handleSoisyCallback(): WebResponse
    {
        $request = Craft::$app->request;
        $response = Craft::$app->getResponse();

        $commerce = Commerce::getInstance();
        $transactionHash = $request->getBodyParam('orderReference');
        $transactionService = $commerce->getTransactions();

        $transaction = $transactionService->getTransactionByHash($transactionHash);

        // Check to see if the transaction exists.
        if ( ! $transaction) {
            Craft::warning('Transaction with the hash "'.$transactionHash.'" not found.', 'soisy');

            return $response;
        }

        // Check to see if a successful purchase child transaction already exists.
        $successfulChildTransaction = TransactionRecord::find()->where([
            'status' => TransactionRecord::STATUS_SUCCESS,
            'parentId' => $transaction->id,
        ])->one();

        if ($successfulChildTransaction) {
            Craft::warning('Successful child transaction for "'.$transactionHash.'" already exists.', 'soisy');

            return $response;
        }

        // Ensure that the order was marked as completed.
        if ( ! $transaction->order->isCompleted) {
            $transaction->order->markAsComplete();
        }

        $eventId = $request->getBodyParam('eventId');
        $childTransaction = $transactionService->createTransaction(null, $transaction, $transaction->type);

        switch ($eventId) {
            case 'LoanWasApproved':
            case 'RequestCompleted':
                $childTransaction->status = TransactionRecord::STATUS_PENDING;
                break;

            case 'LoanWasVerified':
                $childTransaction->status = TransactionRecord::STATUS_PROCESSING;
                break;

            case 'LoanWasDisbursed':
                $childTransaction->status = TransactionRecord::STATUS_SUCCESS;
                break;

            case 'UserWasRejected':
                $childTransaction->status = TransactionRecord::STATUS_FAILED;
                break;
        }

        $childTransaction->code = $eventId;
        $childTransaction->message = $this->translateEventMessage($request->getBodyParam('eventMessage'));
        $childTransaction->response = $request->getBodyParams();
        $childTransaction->reference = $request->getBodyParam('orderToken');

        $transactionService->saveTransaction($childTransaction);

        return $response;
    }

    protected function translateEventMessage($message): string
    {
        return Craft::t('commerce-soisy', str_replace('%20', ' ', $message));
    }

    protected function throwUnsupportedFunctionalityException()
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }
}
