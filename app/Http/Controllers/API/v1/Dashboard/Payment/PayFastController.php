<?php

namespace App\Http\Controllers\API\v1\Dashboard\Payment;

use App\Helpers\ResponseError;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\IyzicoRequest;
use App\Http\Requests\Payment\StripeRequest;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\PaymentPayload;
use App\Models\PaymentProcess;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\PaymentService\PayFastService;
use App\Traits\ApiResponse;
use App\Traits\OnResponse;
use Http;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class PayFastController extends Controller
{
    use OnResponse, ApiResponse;

    public function __construct(private PayFastService $service)
    {
        parent::__construct();
    }

    /**
     * process transaction.
     *
     * @param StripeRequest $request
     * @return Response|JsonResponse
     */
    public function orderProcessTransaction(StripeRequest $request): Response|JsonResponse
    {
        try {
            return $this->service->orderProcessTransaction($request->all());
        } catch (Throwable $e) {
            $this->error($e);
            return $this->onErrorResponse([
                'message' => $e->getMessage() . $e->getFile() . $e->getLine(),
            ]);
        }
    }

    /**
     * process transaction.
     *
     * @param IyzicoRequest $request
     * @return Response|JsonResponse
     */
    public function subscriptionProcessTransaction(IyzicoRequest $request)
    {
        $shop     = auth('sanctum')->user()?->shop ?? auth('sanctum')->user()?->moderatorShop;
        $currency = Currency::currenciesList()->where('active', 1)->where('default', 1)->first()?->title;

        if (empty($shop)) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_404,
                'message' => __('errors.' . ResponseError::SHOP_NOT_FOUND, locale: $this->language)
            ]);
        }

        if (empty($currency)) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_404,
                'message' => __('errors.' . ResponseError::CURRENCY_NOT_FOUND)
            ]);
        }

        try {
            return $this->service->subscriptionProcessTransaction($request->all(), $shop);
        } catch (Throwable $e) {
            $this->error($e);
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_501,
                'message' => $e->getMessage()
            ]);
        }

    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function orderResultTransaction(Request $request): RedirectResponse
    {
		$cartId   = (int)$request->input('cart_id');
		$parcelId = (int)$request->input('parcel_id');

		$to = config('app.front_url') . ($cartId ? '/' : "parcels/$parcelId");

		return Redirect::to($to);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function subscriptionResultTransaction(Request $request): RedirectResponse
    {
        $subscription = Subscription::find((int)$request->input('subscription_id'));

        $to = config('app.admin_url') . "seller/subscriptions/$subscription->id";

        return Redirect::to($to);
    }

    /**
     * @param Request $request
     * @return void
     */
    public function paymentWebHook(Request $request): void
    {
        $token = $request->input('signature');

        $status = match ($request->input('payment_status')) {
            'COMPLETE' => Transaction::STATUS_PAID,
            'CANCELED' => Transaction::STATUS_CANCELED,
            default				  		 => 'progress',
        };

        $this->service->afterHook($token, $status);
    }
}
