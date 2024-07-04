<?php

namespace App\Module\Warehouse\Macros;

use Illuminate\Database\Eloquent\Builder;

Builder::macro('leftJoinStockProductsCondition', function () {
  $this->leftJoin('stock_products', function ($join) {
    $join->on('stock_products.product_id', '=', 'products.id')
      ->where(function ($mainQ) {
        $hasStockDefault = request()->is_stock_default == 1;
        $hasStockId = request()->has('stock_id');
        $alwaysHaveStockId = !$hasStockDefault && !$hasStockId;

        $mainQ->where(function ($subQ) use ($hasStockDefault, $hasStockId, $alwaysHaveStockId) {
          $subQ->where('products.type', '=', 'variable')
            ->where('stock_products.is_main_stock', '=', 0)
            ->where('stock_products.is_sale', '=', 1)
            ->where('stock_products.status', 'approve');

          if (request()->filled('except_variant_condition_ids')) {
            $exceptVariantIds = explode(",", request()->except_variant_condition_ids);
            $subQ->whereNotIn('stock_products.id', $exceptVariantIds);
          }

          $subQ->where(function ($subQ1) use ($hasStockDefault, $hasStockId, $alwaysHaveStockId) {
            if ($hasStockDefault || $hasStockId || $alwaysHaveStockId) {
              $subQ1->when($hasStockDefault || $alwaysHaveStockId, function ($subQ2) {
                $idStockDefault = request()->header('stock-id');
                $subQ2->where('stock_products.stock_id', $idStockDefault);
              })
                ->when($hasStockId, function ($subQ2) {
                  $subQ2->where('stock_products.stock_id', request()->stock_id);
                });
            }
          });
        });

        $mainQ->orWhere(function ($subQ) use ($hasStockDefault, $hasStockId, $alwaysHaveStockId) {
          $subQ->where('products.type', '!=', 'variable')
            ->where('stock_products.is_main_stock', '=', 1)
            ->where('stock_products.status', 'approve');

          $subQ->where(function ($subQ1) use ($hasStockDefault, $hasStockId, $alwaysHaveStockId) {
            if ($hasStockDefault || $hasStockId || $alwaysHaveStockId) {
              $subQ1->when($hasStockDefault || $alwaysHaveStockId, function ($subQ2) {
                $idStockDefault = request()->header('stock-id');
                $subQ2->where('stock_products.stock_id', $idStockDefault);
              })
                ->when($hasStockId, function ($subQ2) {
                  $subQ2->where('stock_products.stock_id', request()->stock_id);
                });
            }
          });
        });
      });
  })->whereNotNull('stock_products.stock_id');

  return $this;
});
