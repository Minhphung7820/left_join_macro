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
    $contact_id = '700';

    $query = Product::leftJoin('bill_items', function ($join) {
        $join->on('bill_items.product_id', '=', 'products.id');
    });
    $leftJoinLatestSellOrPriceQuote = $query->leftJoinLatestSellOrPriceQuote($contact_id);
    if (is_array($leftJoinLatestSellOrPriceQuote)) {
        return response()->json([]);
    }
    $data = $query->get();

    return response()->json($data);
});
