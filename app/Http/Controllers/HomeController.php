<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    protected array $request;

    const RYCZALT = 1;
    const LINIOWKA = 2;
    const OGOLNE = 3;

//    const NO_ZUS = 270.9;
    const SMALL_ZUS = 285.71;
    const FULL_ZUS_NO_HEALTH = 1485.31;
    const FULL_ZUS_WITH_HEALTH = 1600.32;

    const RYCZALT_FEE = 0.12;
    const RYCZALT_MIN_HEALTH_FEE = 335.94;
    const RYCZALT_MIDDLE_HEALTH_FEE = 559.89;
    const RYCZALT_MAX_HEALTH_FEE = 1007.81;
    const RYCZALT_MIN_HEALTH_LIMIT = 60000;
    const RYCZALT_MIDDLE_HEALTH_LIMIT = 300000;

    const LINIOWKA_FEE = 0.19;
    const LINIOWKA_HEALTH_FEE = 0.049;

    const OGOLNE_SMALL_FEE = 0.12;
    const OGOLNE_BIG_FEE = 0.32;
    const OGOLNE_HEALTH_FEE = 0.09;
    const OGOLNE_FREE_FEE = 30000;
    const OGOLNE_LEVEL_OF_SMALL_FEE = 120000;

    const IP_BOX_FEE = 0.05;

    const VAT = 1.23;


    public function index(Request $request): View
    {
        $ryczalt = null;
        $liniowka = null;
        $ogolne = null;
        $this->request = $request->all();
        if (isset($this->request['nettoSalary'])) {
            $this->calculateVat();
            $ryczalt = $this->calculateRyczalt();
            $liniowka = $this->calculateLiniowka();
            $ogolne = $this->calculateOgolne();
        }
        return view('welcome')->with([
            'ryczalt' => $ryczalt,
            'liniowka' => $liniowka,
            'ogolne' => $ogolne,
        ]);
    }

    private function calculateVat(): void
    {
        $this->request['bruttoSalary'] = $this->request['nettoSalary'] * self::VAT;
        $this->request['vat'] = $this->request['bruttoSalary'] - $this->request['nettoSalary']
            - ($this->request['halfVatCosts'] - ($this->request['halfVatCosts'] / self::VAT) / 2)
            - ($this->request['fullVatCosts'] - $this->request['fullVatCosts'] / self::VAT);
    }

    private function getRyczaltHealthFee(): float
    {
        if ($this->request['nettoSalary'] < self::RYCZALT_MIN_HEALTH_LIMIT) {
            $healthFee = self::RYCZALT_MIN_HEALTH_FEE;
        } elseif ($this->request['nettoSalary'] < self::RYCZALT_MIDDLE_HEALTH_LIMIT) {
            $healthFee = self::RYCZALT_MIDDLE_HEALTH_FEE;
        } else {
            $healthFee = self::RYCZALT_MAX_HEALTH_FEE;
        }
        return $healthFee * 12;
    }

    private function getHealthFeeByType(int $type, $costs): float
    {
        return match ($type) {
            self::RYCZALT => $this->getRyczaltHealthFee(),
            self::LINIOWKA => ($this->request['nettoSalary'] - $this->getCosts($costs)) * self::LINIOWKA_HEALTH_FEE,
            self::OGOLNE => ($this->request['nettoSalary'] - $this->getCosts($costs)) * self::OGOLNE_HEALTH_FEE
        };
    }

    private function getZus(): float
    {
        return 12 * match ((int)$this->request['zus']) {
//                0 => self::NO_ZUS,
                1 => self::SMALL_ZUS,
                2 => $this->request['health'] ? self::FULL_ZUS_WITH_HEALTH : self::FULL_ZUS_NO_HEALTH
            };
    }

    private function getOgolneFee($costs): float
    {
        $taxBase = $this->request['nettoSalary'] - $this->getZus() - $this->getCosts($costs);
        $basePercentage = 100 - $this->request['ipBox'];

        $bigTaxBase = 0;
        if ($taxBase > self::OGOLNE_LEVEL_OF_SMALL_FEE) {
            $bigTaxBase = $taxBase - self::OGOLNE_LEVEL_OF_SMALL_FEE;
            $taxBase = self::OGOLNE_LEVEL_OF_SMALL_FEE;
        }
        return ($bigTaxBase * $basePercentage / 100) * self::OGOLNE_BIG_FEE
            + (($taxBase * $basePercentage / 100) - self::OGOLNE_FREE_FEE) * self::OGOLNE_SMALL_FEE
            + ($bigTaxBase * $this->request['ipBox'] / 100) * self::IP_BOX_FEE
            + (($taxBase * $this->request['ipBox'] / 100) - self::OGOLNE_FREE_FEE) * self::IP_BOX_FEE;
    }

    private function getCosts($costs, bool $hasCosts = true): float
    {
        if($costs AND $hasCosts) {
            return $costs;
        } else {
            $costs = 0;
        }
        if ($hasCosts) {
            $costs = ($this->request['noVatCosts']
                + (($this->request['halfVatCosts'] / self::VAT) * 1.115) * 0.75
                + $this->request['fullVatCosts'] / self::VAT);
        }
        return $costs;
    }

    private function getRyczaltFee(bool $hasCosts, $costs): float
    {
        return ($this->request['nettoSalary'] - $this->getZus() - $this->getCosts($costs, $hasCosts)) * self::RYCZALT_FEE;
    }

    private function getLiniowkaFee(bool $hasCosts, $costs): float
    {
        $cost = ($this->request['nettoSalary'] - $this->getZus() - $this->getCosts($costs, $hasCosts));
        $basePercentage = 100 - $this->request['ipBox'];

        return ($cost * $basePercentage / 100) * self::LINIOWKA_FEE
            + ($cost * $this->request['ipBox'] / 100) * self::IP_BOX_FEE;
    }

    private function getFeeByType(int $type, $costs): float
    {
        return match ($type) {
            self::OGOLNE => $this->getOgolneFee($costs),
            self::RYCZALT => $this->getRyczaltFee(false, $costs),
            self::LINIOWKA => $this->getLiniowkaFee(true, $costs)
        };
    }

    public function getMainValue(): float
    {
        return $this->request['bruttoSalary']
            - $this->request['vat']
            - $this->getZus();
    }

    private function calculateRyczalt($costs = null): float
    {
        return $this->getMainValue()
            - $this->getHealthFeeByType(self::RYCZALT, $costs)
            - $this->getFeeByType(self::RYCZALT, $costs);
    }

    private function calculateLiniowka($costs = null): float
    {
        return $this->getMainValue()
            - $this->getHealthFeeByType(self::LINIOWKA, $costs)
            - $this->getFeeByType(self::LINIOWKA, $costs);
    }

    private function calculateOgolne($costs = null): float
    {
        return $this->getMainValue()
            - $this->getHealthFeeByType(self::OGOLNE, $costs)
            - $this->getFeeByType(self::OGOLNE, $costs);
    }
}
