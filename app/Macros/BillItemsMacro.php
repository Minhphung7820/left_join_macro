
<?php

use Illuminate\Database\Eloquent\Builder;

Builder::macro('leftJoinItemsBill', function () {
  $this->leftJoin('bill_items', function ($join) {
    $join->on('bill_items.bill_id', '=', 'bills.id')
      ->where('bill_items.type', 'paid')
      ->whereNotIn('bill_items.id', [2, 4]);
  })
    ->whereNotNull('bill_items.type');
  return $this;
});

?>