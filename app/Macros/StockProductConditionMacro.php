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
        // Lọc sản phẩm biến thể
        $mainQ->where(function ($subQ) use (
          $hasStockDefault,
          $hasStockId,
          $alwaysHaveStockId
        ) {
          $subQ->where('products.type', '=', 'variable')
            ->where('stock_products.is_main_stock', '=', 0)
            ->where('stock_products.is_sale', '=', 1)
            ->where('stock_products.status', 'approve')
            ->when(
              request()->filled('except_variant_condition_ids'),
              function ($query) {
                $exceptVariantIds = explode(",", request()->except_variant_condition_ids);
                $query->whereNotIn('stock_products.id', $exceptVariantIds);
              }
            );

          $subQ->where(function ($subQ1) use (
            $hasStockDefault,
            $hasStockId,
            $alwaysHaveStockId
          ) {
            $subQ1->when($hasStockDefault || $hasStockId || $alwaysHaveStockId, function ($subQ2) use (
              $hasStockDefault,
              $hasStockId,
              $alwaysHaveStockId
            ) {
              $subQ2->when($hasStockDefault || $alwaysHaveStockId, function ($subQ3) {
                $idStockDefault = request()->header('stock-id');
                $subQ3->where('stock_products.stock_id', $idStockDefault);
              })
                ->when($hasStockId, function ($subQ3) {
                  $subQ3->where('stock_products.stock_id', request()->stock_id);
                });
            });
          });
        });
        // Lọc sản phẩm thường  , combo
        $mainQ->orWhere(function ($subQ) use (
          $hasStockDefault,
          $hasStockId,
          $alwaysHaveStockId
        ) {
          $subQ->where('products.type', '!=', 'variable')
            ->where('stock_products.is_main_stock', '=', 1)
            ->where('stock_products.status', 'approve');

          $subQ->where(function ($subQ1) use (
            $hasStockDefault,
            $hasStockId,
            $alwaysHaveStockId
          ) {
            $subQ1->when($hasStockDefault || $hasStockId || $alwaysHaveStockId, function ($subQ2) use (
              $hasStockDefault,
              $hasStockId,
              $alwaysHaveStockId
            ) {
              $subQ2->when($hasStockDefault || $alwaysHaveStockId, function ($subQ3) {
                $idStockDefault = request()->header('stock-id');
                $subQ3->where('stock_products.stock_id', $idStockDefault);
              })
                ->when($hasStockId, function ($subQ3) {
                  $subQ3->where('stock_products.stock_id', request()->stock_id);
                });
            });
          });
        });
      });
  })->whereNotNull('stock_products.stock_id');
  $this->when(isset(request()->except_type) && request()->except_type, function ($query) {
    $query->where('products.type', '!=', request()->except_type);
  });
  return $this;
});
