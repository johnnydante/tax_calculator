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
    const FULL_ZUS_NO_HEALTH = 1124.23;
    const FULL_ZUS_WITH_HEALTH = 1211.28;

    const RYCZALT_FEE = 0.12;
    const RYCZALT_MIN_HEALTH_FEE = 335.94;
    const RYCZALT_MIDDLE_HEALTH_FEE = 559.89;
    const RYCZALT_MAX_HEALTH_FEE = 1007.81;
    const RYCZALT_MIN_HEALTH_LIMIT = 60000;
    const RYCZALT_MIDDLE_HEALTH_LIMIT = 300000;

    const LINIOWKA_FEE = 0.19;
    const LINIOWKA_HEALTH_FEE = 0.049;

    const OGOLNE_SMALL_FEE = 0.17;
    const OGOLNE_BIG_FEE = 0.33;
    const OGOLNE_HEALTH_FEE = 0.09;
    const OGOLNE_FREE_FEE = 30000;
    const OGOLNE_LEVEL_OF_SMALL_FEE = 120000;

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

    public function calculateCostsMin(): void
    {
        $this->request['liniowkaCostsMin'] = 0;
        $this->request['ogolneCostsMin'] = 0;
        $this->request['liniowkaCostsMinVat'] = 0;
        $this->request['ogolneCostsMinVat'] = 0;
        do {
            $this->request['liniowkaCostsMin'] += 10;
            $this->request['ogolneCostsMin'] += 10;
            $ryczalt = $this->calculateRyczalt($this->request['liniowkaCostsMin']);
            $liniowka = $this->calculateLiniowka($this->request['liniowkaCostsMin']);
            $ogolne = $this->calculateOgolne($this->request['liniowkaCostsMin']);
        } while ($ryczalt >= $liniowka AND $ryczalt >= $ogolne);
        do {
            $this->request['liniowkaCostsMinVat'] += 10;
            $this->request['ogolneCostsMinVat'] += 10;
            $ryczalt = $this->calculateRyczalt($this->request['liniowkaCostsMinVat'] / self::VAT);
            $liniowka = $this->calculateLiniowka($this->request['liniowkaCostsMinVat'] / self::VAT);
            $ogolne = $this->calculateOgolne($this->request['liniowkaCostsMinVat'] / self::VAT);
        } while ($ryczalt >= $liniowka AND $ryczalt >= $ogolne);
    }

    public function calculateLiniowkaCostsMax($first = false): void
    {
        $this->request['liniowkaCostsMax'] = $this->request['liniowkaCostsMin'];
        do {
            $this->request['liniowkaCostsMax'] += 100;
            if($first) {
                $this->request['ogolneCostsMin'] += 100;
            }
            $liniowka = $this->calculateLiniowka($this->request['liniowkaCostsMax']);
            $ogolne = $this->calculateOgolne($this->request['liniowkaCostsMax']);
        } while ($liniowka >= $ogolne);
    }

    public function calculateOgolneCostsMax($first = false): void
    {
        $this->request['ogolneCostsMax'] = $this->request['ogolneCostsMin'];
        do {
            $this->request['ogolneCostsMax'] += 100;
            if($first) {
                $this->request['liniowkaCostsMin'] += 100;
            }
            $liniowka = $this->calculateLiniowka($this->request['ogolneCostsMax']);
            $ogolne = $this->calculateOgolne($this->request['ogolneCostsMax']);
        } while ($ogolne >= $liniowka);
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
//                0 => self::SMALL_ZUS,
                1 => self::SMALL_ZUS,
                2 => $this->request['health'] ? self::FULL_ZUS_WITH_HEALTH : self::FULL_ZUS_NO_HEALTH
            };
    }

    private function getOgolneFee($costs): float
    {
        $taxBase = $this->request['nettoSalary'] - $this->getZus() - $this->getCosts($costs);
        $bigTaxBase = 0;
        if ($taxBase > self::OGOLNE_LEVEL_OF_SMALL_FEE) {
            $bigTaxBase = $taxBase - self::OGOLNE_LEVEL_OF_SMALL_FEE;
            $taxBase = self::OGOLNE_LEVEL_OF_SMALL_FEE;
        }
        return $bigTaxBase * self::OGOLNE_BIG_FEE
            + ($taxBase - self::OGOLNE_FREE_FEE) * self::OGOLNE_SMALL_FEE;
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
                + ($this->request['halfVatCosts'] / self::VAT) * 1.115
                + $this->request['fullVatCosts'] / self::VAT);
        }
        return $costs;
    }

    private function getFee(float $feeBase, bool $hasCosts, $costs): float
    {
        return ($this->request['nettoSalary'] - $this->getZus() - $this->getCosts($costs, $hasCosts)) * $feeBase;
    }

    private function getFeeByType(int $type, $costs): float
    {
        return match ($type) {
            self::OGOLNE => $this->getOgolneFee($costs),
            self::RYCZALT => $this->getFee(self::RYCZALT_FEE, false, $costs),
            self::LINIOWKA => $this->getFee(self::LINIOWKA_FEE, true, $costs)
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
