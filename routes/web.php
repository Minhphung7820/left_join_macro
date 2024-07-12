<?php

use App\Models\Bill;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    //
    $contact_id = 700;
    $sqlLatestPriceQuote = 'SELECT * FROM bills WHERE contact_id = ' . $contact_id . ' AND type = "price_quote" ORDER BY created_at DESC LIMIT 1';
    $sqlLatestSell = 'SELECT * FROM bills WHERE contact_id = ' . $contact_id . ' AND type = "sell" AND status = "approve" ORDER BY created_at DESC LIMIT 1';

    $lLatestPriceQuote = DB::select($sqlLatestPriceQuote);
    $latestSell = DB::select($sqlLatestSell);
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
        return response()->json([]);
    }
    $arrayLeftJoinLatestCondition = [
        DB::raw('(' . $sqlConditionGetLatest . ') as tbNewOrder'),
        'tbNewOrder.id',
        '=',
        'bill_items.bill_id'
    ];
    //
    $query = Product::leftJoin('bill_items', function ($join) {
        $join->on('bill_items.product_id', '=', 'products.id');
    })->leftJoin(
        ...$arrayLeftJoinLatestCondition
    )
        ->whereNotNull('tbNewOrder.id');

    $data = $query->get();

    return response()->json($data);
});
