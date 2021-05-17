<?php
/**
 * Created by Q-Solutions Studio
 *
 * @category    Magespices
 * @package     Magespices_FixedCurrencyPrices
 * @author      Sebastian Strojwas <sebastian@qsolutionsstudio.com>
 */

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Magespices_FixedCurrencyPrices',
    __DIR__
);
