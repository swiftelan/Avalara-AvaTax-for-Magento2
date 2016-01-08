<?php
/**
 * @category    ClassyLlama
 * @package     AvaTax
 * @author      Matt Johnson <matt.johnson@classyllama.com>
 * @copyright   Copyright (c) 2016 Matt Johnson & Classy Llama Studios, LLC
 */

namespace ClassyLlama\AvaTax\Plugin;

use ClassyLlama\AvaTax\Model\Queue;
use ClassyLlama\AvaTax\Model\QueueFactory;
use ClassyLlama\AvaTax\Model\Config;
use ClassyLlama\AvaTax\Model\Logger\AvaTaxLogger;
use Magento\Sales\Api\Data\InvoiceExtensionFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\Spi\InvoiceResourceInterface;
use Magento\Framework\Model\AbstractModel;

class SalesSpiInvoiceResource
{
    /**
     * @var AvaTaxLogger
     */
    protected $avaTaxLogger;

    /**
     * @var Config
     */
    protected $avaTaxConfig;

    /**
     * @var \Magento\Sales\Api\Data\InvoiceExtensionFactory
     */
    protected $invoiceExtensionFactory;

    /**
     * @var QueueFactory
     */
    protected $queueFactory;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $dateTime;

    /**
     * SalesSpiInvoiceResource constructor.
     * @param AvaTaxLogger $avaTaxLogger
     * @param Config $avaTaxConfig
     * @param InvoiceExtensionFactory $invoiceExtensionFactory
     * @param QueueFactory $queueFactory
     * @param DateTime $dateTime
     */
    public function __construct(
        AvaTaxLogger $avaTaxLogger,
        Config $avaTaxConfig,
        InvoiceExtensionFactory $invoiceExtensionFactory,
        QueueFactory $queueFactory,
        DateTime $dateTime
    ) {
        $this->avaTaxLogger = $avaTaxLogger;
        $this->avaTaxConfig = $avaTaxConfig;
        $this->invoiceExtensionFactory = $invoiceExtensionFactory;
        $this->queueFactory = $queueFactory;
        $this->dateTime = $dateTime;
    }

    /**
     * @param \Magento\Sales\Model\Spi\InvoiceResourceInterface $subject
     * @param \Closure $proceed
     *
     *        I include both the extended AbstractModel and implemented Interface here for the IDE's benefit
     * @param \Magento\Framework\Model\AbstractModel|\Magento\Sales\Api\Data\InvoiceInterface $entity
     * @return \Magento\Sales\Model\Spi\InvoiceResourceInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function aroundSave(
        InvoiceResourceInterface $subject,
        \Closure $proceed,
        AbstractModel $entity
    ) {
        // Check to see if this is a newly created entity and store the determination for later evaluation after
        // the entity is saved via plugin closure. After the entity is saved it will not be listed as new any longer.
        $isObjectNew = $entity->isObjectNew();

        // Save AvaTax extension attributes
        if ($this->avaTaxConfig->isModuleEnabled()) {
            // check to see if any extension attributes exist and set them on the model for saving to the db
            $extensionAttributes = $entity->getExtensionAttributes();
            if ($extensionAttributes && $extensionAttributes->getAvataxIsUnbalanced() !== null) {
                $entity->setData('avatax_is_unbalanced', $extensionAttributes->getAvataxIsUnbalanced());
            }
            if ($extensionAttributes && $extensionAttributes->getBaseAvataxTaxAmount() !== null) {
                $entity->setData('base_avatax_tax_amount', $extensionAttributes->getBaseAvataxTaxAmount());
            }

            // Updating a field to trigger a change to the record when avatax_is_unbalanced and base_avatax_tax_amount
            // are both false or 0 which evaluate the same as null in the isModified check
            if (
                $extensionAttributes &&
                (
                    // Something was set to one of the fields and it's not the same as any existing data
                    (
                        $extensionAttributes->getAvataxIsUnbalanced() !== null &&
                        (
                            $entity->getOrigData('avatax_is_unbalanced') === null ||
                            $extensionAttributes->getAvataxIsUnbalanced() <> $entity->getOrigData('avatax_is_unbalanced')
                        )
                    ) ||
                    (
                        $extensionAttributes->getBaseAvataxTaxAmount() !== null &&
                        (
                            $entity->getOrigData('base_avatax_tax_amount') === null ||
                            $extensionAttributes->getBaseAvataxTaxAmount() <> $entity->getOrigData('base_avatax_tax_amount')
                        )
                    )
                )
            ) {
                $entity->setUpdatedAt($this->dateTime->gmtDate());
            }
        }

        /** @var \Magento\Sales\Model\Spi\InvoiceResourceInterface $resultEntity */
        $resultEntity = $proceed($entity);

        // Queue the entity to be sent to AvaTax
        if ($this->avaTaxConfig->isModuleEnabled() && $this->avaTaxConfig->getQueueSubmissionEnabled()) {

            // Add this entity to the avatax processing queue if this is a new entity
            if ($isObjectNew) {
                /** @var Queue $queue */
                $queue = $this->queueFactory->create();
                $queue->build(
                    $entity->getStoreId(),
                    Queue::ENTITY_TYPE_CODE_INVOICE,
                    $entity->getEntityId(),
                    $entity->getIncrementId(),
                    Queue::QUEUE_STATUS_PENDING
                );
                $queue->save();

                $this->avaTaxLogger->debug(
                    __('Added entity to the queue'),
                    [ /* context */
                        'queue_id' => $queue->getId(),
                        'entity_type_code' => Queue::ENTITY_TYPE_CODE_INVOICE,
                        'entity_id' => $entity->getEntityId(),
                    ]
                );
            }
        }

        return $resultEntity;
    }

    /**
     * @param \Magento\Sales\Model\Spi\InvoiceResourceInterface $subject
     * @param \Closure $proceed
     *
     *        Include both the extended AbstractModel and implemented Interface here for the IDE's benefit
     * @param \Magento\Framework\Model\AbstractModel|\Magento\Sales\Api\Data\InvoiceInterface $entity
     * @param mixed $value
     * @param string $field field to load by (defaults to model id)
     * @return \Magento\Framework\Model\ResourceModel\Db\AbstractDb
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function aroundLoad(
        InvoiceResourceInterface $subject,
        \Closure $proceed,
        AbstractModel $entity,
        $value,
        $field = null
    ) {
        /** @var \Magento\Framework\Model\ResourceModel\Db\AbstractDb $resultEntity */
        $resultEntity = $proceed($entity, $value, $field);

        // Load AvaTax extension attributes
        if ($this->avaTaxConfig->isModuleEnabled()) {

            // Get the AvaTax Attributes from the AbstractModel
            $avataxIsUnbalanced = $entity->getData('avatax_is_unbalanced');
            $baseAvataxTaxAmount = $entity->getData('base_avatax_tax_amount');

            // Check the AvaTax Entity to see if we need to add extension attributes
            if ($avataxIsUnbalanced !== null || $baseAvataxTaxAmount !== null) {
                // Get any existing extension attributes or create a new one
                $entityExtension = $entity->getExtensionAttributes();
                if (!$entityExtension) {
                    $entityExtension = $this->invoiceExtensionFactory->create();
                }

                // Set the attributes
                if ($avataxIsUnbalanced !== null) {
                    $entityExtension->setAvataxIsUnbalanced($avataxIsUnbalanced);
                }
                if ($baseAvataxTaxAmount !== null) {
                    $entityExtension->setBaseAvataxTaxAmount($baseAvataxTaxAmount);
                }

                // save the ExtensionAttributes on the entity object
                $entity->setExtensionAttributes($entityExtension);
            }
        }

        return $resultEntity;
    }
}