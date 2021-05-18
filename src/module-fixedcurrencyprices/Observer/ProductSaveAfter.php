<?php
/**
 * Created by Q-Solutions Studio
 *
 * @category    Magespices
 * @package     Magespices_FixedCurrencyPrices
 * @author      Sebastian Strojwas <sebastian@qsolutionsstudio.com>
 */

namespace Magespices\FixedCurrencyPrices\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magespices\FixedCurrencyPrices\Helper\Data as FixedCurrencyPricesHelper;

/**
 * Class ProductSaveAfter
 * @package Magespices\FixedCurrencyPrices\Observer
 */
class ProductSaveAfter implements ObserverInterface
{
    /** @var FixedCurrencyPricesHelper */
    protected $fixedCurrencyPricesHelper;

    /**
     * ProductSaveAfter constructor.
     * @param FixedCurrencyPricesHelper $fixedCurrencyPricesHelper
     */
    public function __construct(
        FixedCurrencyPricesHelper $fixedCurrencyPricesHelper
    ) {
        $this->fixedCurrencyPricesHelper = $fixedCurrencyPricesHelper;
    }

    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        /** @var Product $product */
        $product = $observer->getEvent()->getProduct();

        $this->fixedCurrencyPricesHelper->fixCurrencyPrices($product);
    }
}
