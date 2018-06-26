<?php
/**
 * ClassyLlama_AvaTax
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @copyright  Copyright (c) 2016 Avalara, Inc.
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace ClassyLlama\AvaTax\Observer;

use ClassyLlama\AvaTax\Api\RestInterface;
use ClassyLlama\AvaTax\Helper\Config;
use Magento\Framework\Event\ObserverInterface;

/**
 * Class ConfigSaveObserver
 */
class ConfigSaveObserver implements ObserverInterface
{
    /**
     * @var Config
     */
    protected $config = null;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \ClassyLlama\AvaTax\Helper\ModuleChecks
     */
    protected $moduleChecks;

    /**
     * @var RestInterface
     */
    protected $interactionRest;

    /**
     * Constructor
     *
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     * @param Config $config
     * @param \ClassyLlama\AvaTax\Helper\ModuleChecks $moduleChecks
     * @param RestInterface $interactionRest
     */
    public function __construct(
        \Magento\Framework\Message\ManagerInterface $messageManager,
        Config $config,
        \ClassyLlama\AvaTax\Helper\ModuleChecks $moduleChecks,
        RestInterface $interactionRest
    ) {
        $this->messageManager = $messageManager;
        $this->config = $config;
        $this->moduleChecks = $moduleChecks;
        $this->interactionRest = $interactionRest;
    }

    /**
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return $this
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if ($observer->getStore()) {
            $scopeId = $observer->getStore();
            $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        } elseif ($observer->getWebsite()) {
            $scopeId = $observer->getWebsite();
            $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITE;
        } else {
            $scopeId = \Magento\Store\Model\Store::DEFAULT_STORE_ID;
            $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        }

        foreach ($this->getErrors($scopeId, $scopeType) as $error) {
            $this->messageManager->addError($error);
        }

        foreach ($this->getNotices() as $notice) {
            $this->messageManager->addNotice($notice);
        }

        return $this;
    }

    /**
     * Get all errors that should display when tax config is saved
     *
     * @param $scopeId
     * @param $scopeType
     * @return array
     */
    protected function getErrors($scopeId, $scopeType)
    {
        $errors = array();
        $errors = array_merge(
            $errors,
            $this->sendPing($scopeId, $scopeType)
        );

        return $errors;
    }

    /**
     * Get all notices  that should display when tax config is saved
     *
     * @return array
     */
    protected function getNotices()
    {
        $notices = array();
        $notices = array_merge(
            $notices,
            // This check is also being displayed at the top of the page via
            // \ClassyLlama\AvaTax\Model\Message\ConfigNotification, but it's not as visible as a notice message, so
            // also add it as a notice.
            $this->moduleChecks->checkNativeTaxRules()
        );

        return $notices;
    }

    /**
     * Ping AvaTax using configured live/production mode
     *
     * @param $scopeId
     * @param $scopeType
     *
     * @return array
     */
    protected function sendPing( $scopeId, $scopeType )
    {
        $errors = [];
        $message = '';

        if (!$this->config->isModuleEnabled( $scopeId, $scopeType ))
        {
            return $errors;
        }

        $isProduction = $this->config->isProductionMode( $scopeId, $scopeType );
        $mode = $this->config->getMode( $isProduction );

        if ($this->checkCredentialsForMode( $scopeId, $scopeType, $isProduction ))
        {
            try
            {
                $result = $this->interactionRest->ping( $isProduction, $scopeId, $scopeType );

                if ($result)
                {
                    $this->messageManager->addSuccess(
                        __(
                            'Successfully connected to AvaTax using the '
                            . '<a href="#row_tax_avatax_connection_settings_header">%1 credentials</a>',
                            $mode
                        )
                    );
                }
                else
                {
                    $message = __( 'Authentication failed' );
                }
            }
            catch (\Exception $exception)
            {
                $message = $exception->getMessage();
            }

            if ($message)
            {
                $errors[] = __(
                    'Error connecting to AvaTax using the '
                    . '<a href="#row_tax_avatax_connection_settings_header">%1 credentials</a>: %2',
                    $mode,
                    $message
                );
            }
        }

        return $errors;
    }

    /**
     * Check that credentials have been set for the supplied mode
     *
     * @param $scopeId
     * @param $scopeType
     * @param $isProduction
     *
     * @return bool
     */
    protected function checkCredentialsForMode( $scopeId, $scopeType, $isProduction )
    {
        // Check that credentials have been set for whichever mode has been chosen
        if ($isProduction)
        {
            if (
                $this->config->getAccountNumber( $scopeId, $scopeType ) != ''
                && $this->config->getLicenseKey( $scopeId, $scopeType ) != ''
                && $this->config->getCompanyCode( $scopeId, $scopeType ) != ''
            )
            {
                return true;
            }
        }
        else
        {
            if (
                $this->config->getDevelopmentAccountNumber( $scopeId, $scopeType ) != ''
                && $this->config->getDevelopmentLicenseKey( $scopeId, $scopeType ) != ''
                && $this->config->getDevelopmentCompanyCode( $scopeId, $scopeType ) != ''
            )
            {
                return true;
            }
        }
        // One or more of the supplied mode's credentials is blank
        $this->messageManager->addWarningMessage(
            __(
                'The AvaTax extension is set to "%1" mode, but %2 credentials are incomplete.',
                $isProduction,
                strtolower( $isProduction )
            )
        );

        return false;
    }
}
