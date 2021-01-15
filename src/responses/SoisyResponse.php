<?php

/**
 * Soisy plugin for Craft CMS 3.x
 *
 * Soisy payment gateway for Craft Commerce
 *
 * @link      https://logisticdesign.it
 * @copyright Copyright (c) 2021 Logistic Design srl
 */

namespace logisticdesign\soisy\responses;

use craft\commerce\base\RequestResponseInterface;

class SoisyResponse implements RequestResponseInterface
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Returns whether or not the payment was successful.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->data['success'] ?? false;
    }

    /**
     * Returns whether or not the payment is being processed by gateway.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return false;
    }

    /**
     * Returns whether or not the user needs to be redirected.
     *
     * @return bool
     */
    public function isRedirect(): bool
    {
        return isset($this->data['redirectUrl']);
    }

    /**
     * Returns the redirect method to use, if any.
     *
     * @return string
     */
    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    /**
     * Returns the redirect data provided.
     *
     * @return array
     */
    public function getRedirectData(): array
    {
        return [];
    }

    /**
     * Returns the redirect URL to use, if any.
     *
     * @return string
     */
    public function getRedirectUrl(): string
    {
        return $this->data['redirectUrl'];
    }

    /**
     * Returns the transaction reference.
     *
     * @return string
     */
    public function getTransactionReference(): string
    {
        return $this->data['transactionHash'];
    }

    /**
     * Returns the response code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->data['code'] ?? '';
    }

    /**
     * Returns the data.
     *
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Returns the gateway message.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->data['eventMessage'] ?? '';
    }

    /**
     * Perform the redirect.
     *
     * @return mixed
     */
    public function redirect()
    {

    }
}
