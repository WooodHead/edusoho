<?php

namespace Biz\OrderFacade\Command;

use AppBundle\Common\NumberToolkit;
use Biz\Cash\Service\CashAccountService;
use Biz\OrderFacade\Product\Product;
use Biz\System\Service\SettingService;

class ProductCoinMarketingCommand extends Command
{
    public function execute(Product $product)
    {
        $coinSetting = $this->getSettingService()->get('coin');

        if (empty($coinSetting['coin_enabled'])) {
            return;
        }

        $user = $this->biz['user'];

        $account = $this->getCashAccountService()->getAccountByUserId($user['id']);

        $maxRate = property_exists($product->maxRate) ? 100 : $product->maxRate;
        $maxCoin = NumberToolkit::roundUp($maxRate * $product->price / 100 * $coinSetting['cash_rate']);
        $coinMarketing['maxCoin'] = $maxCoin;
        $coinMarketing['account'] = $account;
        $isVerifiedMobile = (isset($currentUser['verifiedMobile'])) && (strlen($currentUser['verifiedMobile']) > 0);
        $coinMarketing['verifiedMobile'] = $isVerifiedMobile ? $user['verifiedMobile'] : '';
        $coinMarketing['hasPayPassword'] = strlen($user['payPassword']) > 0;

        $product->marketing['coin'] = $coinMarketing;
    }

    /**
     * @return CashAccountService
     */
    private function getCashAccountService()
    {
        return $this->biz->service('Cash:CashAccountService');
    }

    /**
     * @return SettingService
     */
    private function getSettingService()
    {
        return $this->biz->service('System:SettingService');
    }
}