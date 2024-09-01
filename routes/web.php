<?php

use App\Http\Controllers\Web\ConvertController;
use App\Http\Controllers\API\v1\Dashboard\Payment\{MercadoPagoController,
    MollieController,
    PayFastController,
    PayStackController,
    PayTabsController,
    RazorPayController,
    StripeController,
    WalletPaymentController};
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dashboard Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('iyzico-3d', [WalletPaymentController::class, 'Dprocess']);
Route::get('wallet-success', [WalletPaymentController::class, 'success']);

Route::any('order-stripe-success', [StripeController::class, 'orderResultTransaction']);
Route::any('parcel-order-stripe-success', [StripeController::class, 'orderResultTransaction']);

//Route::get('order-paypal-success', [PayPalController::class, 'orderResultTransaction']);

Route::get('order-razorpay-success', [RazorPayController::class, 'orderResultTransaction']);

Route::get('order-paystack-success', [PayStackController::class, 'orderResultTransaction']);

Route::get('order-mercado-pago-success', [MercadoPagoController::class, 'orderResultTransaction']);

Route::any('order-moya-sar-success', [MollieController::class, 'orderResultTransaction']);

Route::any('order-paytabs-success', [PayTabsController::class, 'orderResultTransaction']);

Route::any('order-pay-fast-success', [PayFastController::class, 'orderResultTransaction']);

Route::get('/', function () {
    return view('welcome');
});

Route::get('convert', [ConvertController::class, 'index'])->name('convert');
Route::post('convert-post', [ConvertController::class, 'getFile'])->name('convertPost');
