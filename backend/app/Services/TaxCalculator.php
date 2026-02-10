<?php

namespace App\Services;

/**
 * Calculadora de impuestos (IVA incluido en el precio).
 *
 * - unit_price y line_total son BRUTOS (IVA incluido)
 * - subtotal = neto
 * - tax_total = iva
 */
class TaxCalculator
{
    /**
     * Mapeo de alícuotas AFIP/ARCA WSFEv1.
     * 0%=>3, 10.5%=>4, 21%=>5, 27%=>6, 5%=>8, 2.5%=>9.
     */
    public function afipAlicuotaId(float $taxRate): int
    {
        $r = round($taxRate, 2);
        return match (true) {
            abs($r - 0.00) < 0.01 => 3,
            abs($r - 10.50) < 0.01 => 4,
            abs($r - 21.00) < 0.01 => 5,
            abs($r - 27.00) < 0.01 => 6,
            abs($r - 5.00) < 0.01 => 8,
            abs($r - 2.50) < 0.01 => 9,
            default => 5, // fallback 21%
        };
    }

    /**
     * Calcula neto e IVA a partir de un importe BRUTO (IVA incluido) y alícuota.
     */
    public function splitGross(float $gross, float $taxRate): array
    {
        $gross = (float) $gross;
        $rate = (float) $taxRate;

        if ($rate <= 0) {
            return ['net' => $this->round2($gross), 'iva' => 0.00];
        }

        $net = $gross / (1.0 + ($rate / 100.0));
        $iva = $gross - $net;

        return ['net' => $this->round2($net), 'iva' => $this->round2($iva)];
    }

    /**
     * Calcula totales y alícuotas WSFE a partir de líneas.
     *
     * Cada línea debe incluir:
     * - gross (float) : importe bruto (IVA incluido) de la línea
     * - tax_rate (float) : alícuota
     */
    public function computeTotals(array $lines): array
    {
        $totalGross = 0.0;
        $totalNet = 0.0;
        $totalIva = 0.0;

        $byRate = []; // rate => ['base'=>..., 'iva'=>...]

        foreach ($lines as $ln) {
            $gross = (float)($ln['gross'] ?? 0);
            $rate = (float)($ln['tax_rate'] ?? 0);
            $split = $this->splitGross($gross, $rate);

            $totalGross += $gross;
            $totalNet += $split['net'];
            $totalIva += $split['iva'];

            $key = (string) round($rate, 2);
            if (!isset($byRate[$key])) {
                $byRate[$key] = ['rate' => (float) $rate, 'base' => 0.0, 'iva' => 0.0];
            }
            $byRate[$key]['base'] += $split['net'];
            $byRate[$key]['iva'] += $split['iva'];
        }

        // Redondeo final por buckets
        $ivaAlicuotas = [];
        foreach ($byRate as $bucket) {
            $rate = (float) $bucket['rate'];
            $base = $this->round2($bucket['base']);
            $iva = $this->round2($bucket['iva']);
            if ($base == 0.0 && $iva == 0.0) continue;
            $ivaAlicuotas[] = [
                'Id' => $this->afipAlicuotaId($rate),
                'BaseImp' => $base,
                'Importe' => $iva,
                'Rate' => $rate,
            ];
        }

        return [
            'total_gross' => $this->round2($totalGross),
            'total_net' => $this->round2($totalNet),
            'total_iva' => $this->round2($totalIva),
            'iva_alicuotas' => $ivaAlicuotas,
        ];
    }

    private function round2(float $v): float
    {
        return round($v, 2);
    }
}
