<?php
/**
 * Created by Q-Solutions Studio
 *
 * @category    Magespices
 * @package     Magespices_FixedCurrencyPrices
 * @author      Sebastian Strojwas <sebastian@qsolutionsstudio.com>
 */

namespace Magespices\FixedCurrencyPrices\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Catalog\Model\Product;
use Magento\Directory\Model\Currency\Import\Factory as CurrencyImportFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Api\StoreWebsiteRelationInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Class Data
 * @package Magespices\FixedCurrencyPrices\Helper
 */
class Data extends AbstractHelper
{
    /** @var int  */
    const DEFAULT_SCOPE_ID = 0;

    /** @var string  */
    const RECALCULATE_STATUS_XPATH = 'currency/recalculate/active';

    /** @var string  */
    const EXCLUDED_SKU_XPATH = 'currency/recalculate/excluded';

    /** @var string  */
    const IMPORT_SERVICE_XPATH = 'currency/import/service';

    /** @var string  */
    const BASE_CURRENCY_XPATH = 'currency/options/base';

    /** @var string  */
    const ERROR_LOG_FILE = '/var/log/fixed_currency_prices.log';

    /** @var RequestInterface */
    protected $request;

    /** @var ScopeConfigInterface */
    protected $scopeConfig;

    /** @var CurrencyImportFactory */
    protected $currencyImportFactory;

    /** @var StoreWebsiteRelationInterface */
    protected $storeWebsiteRelation;

    /** @var ResourceConnection */
    protected $resourceConnection;

    /**
     * ProductSaveAfter constructor.
     * @param RequestInterface $request
     * @param ScopeConfigInterface $scopeConfig
     * @param CurrencyImportFactory $currencyImportFactory
     * @param StoreWebsiteRelationInterface $storeWebsiteRelation
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        RequestInterface $request,
        ScopeConfigInterface $scopeConfig,
        CurrencyImportFactory $currencyImportFactory,
        StoreWebsiteRelationInterface $storeWebsiteRelation,
        ResourceConnection $resourceConnection
    ) {
        $this->request = $request;
        $this->scopeConfig = $scopeConfig;
        $this->currencyImportFactory = $currencyImportFactory;
        $this->storeWebsiteRelation = $storeWebsiteRelation;
        $this->resourceConnection = $resourceConnection;
    }

    public function fixCurrencyPrices(Product $product)
    {
        // should work only for changes made in default scope
        if ($this->getScopeId() !== self::DEFAULT_SCOPE_ID) {
            return;
        }

        // skip if SKU is excluded
        if (in_array($product->getSku(), $this->getExcludedSku())) {
            return;
        }

        if ($this->checkIfPriceHasBeenChanged($product) ||
            $this->checkIfSpecialPriceHasBeenChanged($product) ||
            $this->checkIfSpecialPriceHasBeenRemoved($product) ||
            $this->checkIfSpecialPriceHasBeenAdded($product)
        ) {
            $rates = $this->getRates();
            $websiteIds = $product->getWebsiteIds();

            if ($rates && $websiteIds) {
                foreach ($websiteIds as $websiteId) {
                    $storeIds = $this->getStoreIds($websiteId);
                    foreach ($storeIds as $storeId) {
                        if ($this->isRecalculationDisabledForStore($storeId)) {
                            continue;
                        }

                        $storeCurrency = $this->getStoreCurrency($storeId);
                        if (array_key_exists($storeCurrency, $rates) && $rates[$storeCurrency])  {
                            $rate = $rates[$storeCurrency];

                            if ($this->checkIfPriceHasBeenChanged($product)) {
                                $this->updateProductPriceForSpecificStore($product, $storeId, $rate);
                            }

                            if ($this->checkIfSpecialPriceHasBeenAdded($product)) {
                                $this->updateProductSpecialPriceForSpecificStore($product, $storeId, $rate);
                            } elseif ($this->checkIfSpecialPriceHasBeenRemoved($product)) {
                                $this->deleteProductSpecialPriceForSpecificStore($product, $storeId);
                            } elseif ($this->checkIfSpecialPriceHasBeenChanged($product)) {
                                $this->updateProductSpecialPriceForSpecificStore($product, $storeId, $rate);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @return int
     */
    protected function getScopeId(): int
    {
        return (int) $this->request->getParam('store', self::DEFAULT_SCOPE_ID);
    }

    /**
     * @param int $storeId
     * @return bool
     */
    protected function isRecalculationDisabledForStore(int $storeId): bool
    {
        return !$this->scopeConfig->isSetFlag(self::RECALCULATE_STATUS_XPATH, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @return array
     */
    protected function getExcludedSku(): array
    {
        $excluded = $this->scopeConfig->getValue(self::EXCLUDED_SKU_XPATH, ScopeInterface::SCOPE_STORE);
        $excludedArray = explode(PHP_EOL, $excluded);

        return array_map('trim', $excludedArray);
    }

    /**
     * @param Product $product
     * @return bool
     */
    protected function checkIfPriceHasBeenChanged(Product $product): bool
    {
        return $product->dataHasChangedFor('price');
    }

    /**
     * @param Product $product
     * @return bool
     */
    protected function checkIfSpecialPriceHasBeenChanged(Product $product): bool
    {
        return $product->dataHasChangedFor('special_price');
    }

    /**
     * @param Product $product
     * @return bool
     */
    protected function checkIfSpecialPriceHasBeenAdded(Product $product): bool
    {
        return $product->getData('special_price') && !$product->getOrigData('special_price');
    }

    /**
     * @param Product $product
     * @return bool
     */
    protected function checkIfSpecialPriceHasBeenRemoved(Product $product): bool
    {
        return !$product->getData('special_price') && $product->getOrigData('special_price');
    }

    /**
     * @return array
     */
    protected function getRates(): array
    {
        $rates = [];
        $baseCurrency = $this->scopeConfig->getValue(self::BASE_CURRENCY_XPATH, ScopeInterface::SCOPE_WEBSITE);
        $service = $this->scopeConfig->getValue(self::IMPORT_SERVICE_XPATH, ScopeInterface::SCOPE_STORE);

        if ($service) {
            try {
                $importModel = $this->currencyImportFactory->create($service);
                $allRates = $importModel->fetchRates();

                if (array_key_exists($baseCurrency, $allRates) && is_array($allRates[$baseCurrency])) {
                    foreach ($allRates[$baseCurrency] as $currency => $rate) {
                        if ($rate) {
                            $rates[$currency] = $rate;
                        }
                    }
                }
            } catch (\Exception $exception) {
                $this->logError($exception->getMessage());
            }
        }

        return $rates;
    }

    /**
     * @return string
     */
    protected function getBaseCurrency(): string
    {
        return (string) $this->scopeConfig->getValue(self::BASE_CURRENCY_XPATH, ScopeInterface::SCOPE_WEBSITE);
    }

    /**
     * @param $storeId
     * @return string
     */
    protected function getStoreCurrency($storeId): string
    {
        return (string) $this->scopeConfig->getValue(self::BASE_CURRENCY_XPATH, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @param $websiteId
     * @return array
     */
    protected function getStoreIds($websiteId): array
    {
        $storeIds = [];
        try {
            $storeIds = $this->storeWebsiteRelation->getStoreByWebsiteId($websiteId);
        } catch (\Exception $exception) {
            $this->logError($exception->getMessage());
        }

        return $storeIds;
    }

    /**
     * @param Product $product
     * @param int $storeId
     * @param float $rate
     */
    protected function updateProductPriceForSpecificStore(Product $product, int $storeId, float $rate)
    {
        $connection = $this->resourceConnection->getConnection();

        $eavTable = $connection->getTableName('eav_attribute');
        $attributeId = $connection->fetchOne(
            $connection->select()
                ->from($eavTable, ['attribute_id'])
                ->where('attribute_code = \'price\' AND entity_type_id = 4')
        );

        $data = [
            'attribute_id' => $attributeId,
            'store_id' => $storeId,
            'entity_id' => $product->getId(),
            'value' => $product->getPrice() * $rate,
        ];

        $connection->insertOnDuplicate(
            $connection->getTableName('catalog_product_entity_decimal'),
            $data
        );
    }

    /**
     * @param Product $product
     * @param int $storeId
     * @param float $rate
     */
    protected function updateProductSpecialPriceForSpecificStore(Product $product, int $storeId, float $rate)
    {
        $connection = $this->resourceConnection->getConnection();

        $eavTable = $connection->getTableName('eav_attribute');
        $attributeId = $connection->fetchOne(
            $connection->select()
                ->from($eavTable, ['attribute_id'])
                ->where('attribute_code = \'special_price\' AND entity_type_id = 4')
        );

        $data = [
            'attribute_id' => $attributeId,
            'store_id' => $storeId,
            'entity_id' => $product->getId(),
            'value' => $product->getSpecialPrice() * $rate,
        ];

        $connection->insertOnDuplicate(
            $connection->getTableName('catalog_product_entity_decimal'),
            $data
        );
    }

    /**
     * @param Product $product
     * @param int $storeId
     */
    protected function deleteProductSpecialPriceForSpecificStore(Product $product, int $storeId)
    {
        $connection = $this->resourceConnection->getConnection();

        $eavTable = $connection->getTableName('eav_attribute');
        $attributeId = $connection->fetchOne(
            $connection->select()
                ->from($eavTable, ['attribute_id'])
                ->where('attribute_code = \'special_price\' AND entity_type_id = 4')
        );

        $query = 'DELETE FROM ' . $connection->getTableName('catalog_product_entity_decimal') .
            ' WHERE `attribute_id` = ' . $attributeId .
            ' AND `store_id` = ' . $storeId .
            ' AND `entity_id` = ' . $product->getId();
        $connection->query($query);
    }

    /**
     * @param $message
     */
    protected function logError($message)
    {
        $writer = new \Zend\Log\Writer\Stream(BP . self::ERROR_LOG_FILE);
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $logger->info($message);
    }
}
