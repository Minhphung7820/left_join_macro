<?php

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

Builder::macro('leftJoinItemsBill', function () {
  $this->leftJoin('bill_items', function ($join) {
    $join->on('bill_items.bill_id', '=', 'bills.id')
      ->where('bill_items.type', 'paid')
      ->whereNotIn('bill_items.id', [2, 4]);
  })
    ->whereNotNull('bill_items.type');
  return $this;
});

Builder::macro('leftJoinLatestSellOrPriceQuote', function ($contact_id) {

  $sqlLatestPriceQuote = '
  SELECT
   *
  FROM bills
    WHERE contact_id = ?
       AND type = "price_quote"
    ORDER BY created_at
    DESC LIMIT 1';

  $sqlLatestSell = '
  SELECT
  *
  FROM bills
    WHERE contact_id = ?
        AND type = "sell"
        AND status = "approve"
    ORDER BY created_at
    DESC LIMIT 1';

  $lLatestPriceQuote = DB::select($sqlLatestPriceQuote, [$contact_id]);
  $latestSell = DB::select($sqlLatestSell, [$contact_id]);
  $sqlConditionGetLatest = '';
  if (!empty($lLatestPriceQuote) && !empty($latestSell)) {
    $latestPriceQuoteCreatedAt = $lLatestPriceQuote[0]->created_at;
    $latestSellCreatedAt = $latestSell[0]->created_at;
    $priceQuoteTime = Carbon::parse($latestPriceQuoteCreatedAt);
    $sellTime = Carbon::parse($latestSellCreatedAt);
    $sqlConditionGetLatest = $priceQuoteTime->greaterThan($sellTime) ? $sqlLatestPriceQuote : $sqlLatestSell;
  } elseif (empty($lLatestPriceQuote) && !empty($latestSell)) {
    $sqlConditionGetLatest = $sqlLatestSell;
  } elseif (!empty($lLatestPriceQuote) && empty($latestSell)) {
    $sqlConditionGetLatest = $sqlLatestPriceQuote;
  } else {
    return [];
  }
  $arrayLeftJoinLatestCondition = [
    DB::raw('(' . $sqlConditionGetLatest . ') as tbNewOrder'),
    'tbNewOrder.id',
    '=',
    'bill_items.bill_id'
  ];
  $this->leftJoin(
    ...$arrayLeftJoinLatestCondition
  )->setBindings([$contact_id])
    ->whereNotNull('tbNewOrder.id');
  return $this;
});
