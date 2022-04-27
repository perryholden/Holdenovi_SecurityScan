<?php
/**
 * @category    Holdenovi
 * @package     SecurityScan
 * @copyright   Copyright (c) 2022 Holdenovi LLC
 * @license     https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
namespace Holdenovi\SecurityScan\Model;

use Magento\Framework\App\State;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;

class EmailNotify
{
    protected const SECURITY_SCAN_TEMPLATE = 'security_scan_message';

    /**
     * @var State
     */
    protected $state;

    /**
     * @var TransportBuilder
     */
    protected $transportBuilder;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param TransportBuilder $transportBuilder
     * @param State $state
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        TransportBuilder $transportBuilder,
        State $state,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->transportBuilder = $transportBuilder;
        $this->state = $state;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param array $contentVars
     * @return void
     * @throws \Exception
     */
    public function sendEmail(array $contentVars)
    {
        $from = [
            'name' => $this->getDefaultFromName(),
            'email' => $this->getDefaultFromEmail(),
        ];
        $to = $this->getDefaultToEmails();
        $options = [
            'area' => \Magento\Framework\App\Area::AREA_ADMINHTML,
            'store' => \Magento\Store\Model\Store::DEFAULT_STORE_ID,
        ];
        $template = self::SECURITY_SCAN_TEMPLATE;
        $postObject = new \Magento\Framework\DataObject();
        $postObject->setData($contentVars);

        // In order to ensure we can properly setup the transport, we must emulate the area in which the template will
        // be built. This code simply changes the area code on the state, executes our callback, then reverts the change
        $this->state->emulateAreaCode(
            \Magento\Framework\App\Area::AREA_ADMINHTML,
            function () use ($template, $options, $postObject, $from, $to) {
                $transport = $this->transportBuilder->setTemplateIdentifier($template)
                    ->setTemplateOptions($options)
                    ->setTemplateVars($postObject->getData())
                    ->setFrom($from)
                    ->addTo($to)
                    ->getTransport();
                $transport->sendMessage();
            }
        );
    }

    /**
     * @return array
     */
    public function getDefaultToEmails()
    {
        return array_map(
            "trim",
            explode(',', $this->scopeConfig->getValue('trans_email/security_scan_emails/email_recipient'))
        );
    }

    /**
     * @return string
     */
    public function getDefaultFromEmail()
    {
        return trim($this->scopeConfig->getValue('trans_email/security_scan_emails/email'));
    }

    /**
     * @return string
     */
    public function getDefaultFromName()
    {
        return trim($this->scopeConfig->getValue('trans_email/security_scan_emails/name'));
    }
}
