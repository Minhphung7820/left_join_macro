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

    // $query = Product::leftJoin('bill_items', function ($join) {
    //     $join->on('bill_items.product_id', '=', 'products.id');
    // });
    // $leftJoinLatestSellOrPriceQuote = $query->leftJoinLatestSellOrPriceQuote($contact_id);
    // if (is_array($leftJoinLatestSellOrPriceQuote)) {
    //     return response()->json([]);
    // }
    // $data = $query->get();
    // Lấy các sản phẩm đã mua của contact_id là 8
    $products = Product::select('products.*', 'bill_items.amount', 'bills.type', 'bills.created_at')
        ->join('bill_items', 'products.id', '=', 'bill_items.product_id')
        ->join('bills', 'bill_items.bill_id', '=', 'bills.id')
        ->where('bills.contact_id', $contact_id)
        ->where(function ($query) {
            $query->where(function ($query) {
                $query->where('bills.type', 'sell');
            })->orWhere(function ($query) {
                $query->where('bills.type', 'price_quote');
            });
            $query->where('bills.status', 'approve');
        })
        ->whereIn('bill_items.id', function ($query) use ($contact_id) {
            $query->select(DB::raw('MAX(bill_items.id)'))
                ->from('bill_items')
                ->join('bills', 'bill_items.bill_id', '=', 'bills.id')
                ->where('bills.contact_id', $contact_id)
                ->groupBy('bill_items.product_id');
        })
        ->orderByRaw("FIELD(bills.type, 'sell', 'price_quote')")
        ->orderBy('bills.created_at', 'desc')
        ->get();

    return response()->json($products);
});
