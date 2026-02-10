<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Obtiene (y bloquea) el registro de stock para actualización.
     */
    private function lockStockRow(int $companyId, int $warehouseId, int $variantId): Stock
    {
        $stock = Stock::where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        if (!$stock) {
            $stock = Stock::create([
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'variant_id' => $variantId,
                'qty' => 0,
                'reserved_qty' => 0,
            ]);
            // bloquear el recién creado
            $stock = Stock::whereKey($stock->id)->lockForUpdate()->first();
        }

        return $stock;
    }

    /**
     * Movimiento OUT (descuenta stock real). Si se descuenta desde reserva, usar commitReservation().
     */
    public function moveOut(int $companyId, int $warehouseId, int $variantId, float $qty, ?string $refType = null, ?int $refId = null, ?string $note = null): void
    {
        DB::transaction(function () use ($companyId, $warehouseId, $variantId, $qty, $refType, $refId, $note) {
            $stock = $this->lockStockRow($companyId, $warehouseId, $variantId);

            if ($stock->qty < $qty) {
                throw new \RuntimeException('Stock insuficiente');
            }

            $stock->qty = $stock->qty - $qty;
            $stock->save();

            StockMovement::create([
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'variant_id' => $variantId,
                'movement_date' => now(),
                'type' => 'out',
                'qty' => $qty,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'note' => $note,
            ]);
        });
    }

    public function moveIn(int $companyId, int $warehouseId, int $variantId, float $qty, ?string $refType = null, ?int $refId = null, ?string $note = null): void
    {
        DB::transaction(function () use ($companyId, $warehouseId, $variantId, $qty, $refType, $refId, $note) {
            $stock = $this->lockStockRow($companyId, $warehouseId, $variantId);

            $stock->qty = $stock->qty + $qty;
            $stock->save();

            StockMovement::create([
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'variant_id' => $variantId,
                'movement_date' => now(),
                'type' => 'in',
                'qty' => $qty,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'note' => $note,
            ]);
        });
    }

    /**
     * Reserva stock (e-commerce): incrementa reserved_qty y crea stock_reservations.
     */
    public function reserveForWeb(int $companyId, int $variantId, float $qty, int $minutesTtl, string $refType, int $refId): StockReservation
    {
        $warehouseId = $this->getCentralWarehouseIdForWeb($companyId);

        return DB::transaction(function () use ($companyId, $warehouseId, $variantId, $qty, $minutesTtl, $refType, $refId) {
            $stock = $this->lockStockRow($companyId, $warehouseId, $variantId);

            $available = $stock->qty - $stock->reserved_qty;
            if ($available < $qty) {
                throw new \RuntimeException('Stock insuficiente (disponible)');
            }

            $stock->reserved_qty = $stock->reserved_qty + $qty;
            $stock->save();

            return StockReservation::create([
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'variant_id' => $variantId,
                'qty' => $qty,
                'status' => 'active', // active|released|committed|expired
                'expires_at' => now()->addMinutes($minutesTtl),
                'reference_type' => $refType,
                'reference_id' => $refId,
            ]);
        });
    }

    public function releaseReservation(StockReservation $res): void
    {
        DB::transaction(function () use ($res) {
            if ($res->status !== 'active') {
                return;
            }

            $stock = $this->lockStockRow($res->company_id, $res->warehouse_id, $res->variant_id);

            $stock->reserved_qty = max(0, $stock->reserved_qty - $res->qty);
            $stock->save();

            $res->status = 'released';
            $res->save();
        });
    }

    /**
     * Confirma una reserva: baja stock real y reserved_qty, deja movimiento OUT.
     */
    public function commitReservation(StockReservation $res, ?string $refType = null, ?int $refId = null): void
    {
        DB::transaction(function () use ($res, $refType, $refId) {
            if ($res->status !== 'active') {
                return;
            }

            if ($res->expires_at && $res->expires_at->isPast()) {
                // expirar automáticamente
                $this->expireReservation($res);
                throw new \RuntimeException('Reserva expirada');
            }

            $stock = $this->lockStockRow($res->company_id, $res->warehouse_id, $res->variant_id);

            if ($stock->qty < $res->qty) {
                // si esto ocurre, hay inconsistencia; preferimos no dejar reserved_qty colgado.
                $this->releaseReservation($res);
                throw new \RuntimeException('Stock insuficiente al confirmar');
            }

            $stock->qty = $stock->qty - $res->qty;
            $stock->reserved_qty = max(0, $stock->reserved_qty - $res->qty);
            $stock->save();

            $res->status = 'committed';
            $res->save();

            StockMovement::create([
                'company_id' => $res->company_id,
                'warehouse_id' => $res->warehouse_id,
                'variant_id' => $res->variant_id,
                'movement_date' => now(),
                'type' => 'out',
                'qty' => $res->qty,
                'reference_type' => $refType ?: $res->reference_type,
                'reference_id' => $refId ?: $res->reference_id,
                'note' => 'Commit de reserva e-commerce',
            ]);
        });
    }

    public function expireReservation(StockReservation $res): void
    {
        DB::transaction(function () use ($res) {
            if ($res->status !== 'active') {
                return;
            }
            $stock = $this->lockStockRow($res->company_id, $res->warehouse_id, $res->variant_id);
            $stock->reserved_qty = max(0, $stock->reserved_qty - $res->qty);
            $stock->save();
            $res->status = 'expired';
            $res->save();
        });
    }

    public function getCentralWarehouseIdForWeb(int $companyId): int
    {
        $wh = Warehouse::where('company_id', $companyId)
            ->where('is_central_for_web', 1)
            ->first();

        if (!$wh) {
            throw new \RuntimeException('No hay depósito central configurado para web (warehouses.is_central_for_web=1)');
        }
        return (int)$wh->id;
    }
}
