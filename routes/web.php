<?php

use App\Models\Bill;
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
    $data = Bill::leftJoin('bill_items', function ($join) {
        $join->on('bill_items.bill_id', '=', 'bills.id')
            ->where('bill_items.type', 'paid')
            ->whereNotIn('bill_items.id', [2, 4]);
    })
        ->whereNotNull('bill_items.type')
        ->get();

    return response()->json($data);
});
