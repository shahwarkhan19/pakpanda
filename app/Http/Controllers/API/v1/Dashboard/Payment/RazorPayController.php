<?php

namespace App\Http\Controllers\API\v1\Dashboard\Payment;

use App\Helpers\ResponseError;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\SplitRequest;
use App\Http\Requests\Payment\StripeRequest;
use App\Http\Requests\Shop\SubscriptionRequest;
use App\Models\Currency;
use App\Models\Shop;
use App\Models\WalletHistory;
use App\Services\PaymentService\RazorPayService;
use App\Traits\ApiResponse;
use App\Traits\OnResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Redirect;
use Throwable;

class RazorPayController extends Controller
{
    use OnResponse, ApiResponse;

    public function __construct(private RazorPayService $service)
    {
        parent::__construct();
    }

    /**
     * process transaction.
     *
     * @param StripeRequest $request
     * @return JsonResponse
     */
    public function orderProcessTransaction(StripeRequest $request): JsonResponse
    {
        try {
            $paymentProcess = $this->service->orderProcessTransaction($request->all());

            return $this->successResponse('success', $paymentProcess);
        } catch (Throwable $e) {
            return $this->onErrorResponse(['message' => $e->getMessage()]);
        }
    }

	/**
	 * process transaction.
	 *
	 * @param SplitRequest $request
	 * @return JsonResponse
	 */
	public function splitTransaction(SplitRequest $request): JsonResponse
	{
		try {
			$result = $this->service->splitTransaction($request->all());

			return $this->successResponse('success', $result);
		} catch (Throwable $e) {
			$this->error($e);
			return $this->onErrorResponse([
				'message' => $e->getMessage(),
				'param'   => $e->getFile() . $e->getLine()
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
     * process transaction.
     *
     * @param SubscriptionRequest $request
     * @return array
     */
    public function subscriptionProcessTransaction(SubscriptionRequest $request): array
    {
        $shop = auth('sanctum')->user()?->shop ?? auth('sanctum')->user()?->moderatorShop;

        if (empty($shop)) {
            return ['status' => false, 'code' => ResponseError::ERROR_101];
        }

        /** @var Shop $shop */
        $currency = Currency::currenciesList()->where('active', 1)->where('default', 1)->first()?->title;

        if (empty($currency)) {
            return [
                'status'    => true,
                'code'      => ResponseError::ERROR_431,
                'message'   => 'Active default currency not found'
            ];
        }

        try {
            $paymentProcess = $this->service->subscriptionProcessTransaction($request->all(), $shop, $currency);

            return ['status' => true, 'data' => $paymentProcess];
        } catch (Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function subscriptionResultTransaction(Request $request): RedirectResponse
    {
        $to = config('app.front_url') . "seller/subscriptions/" . (int)$request->input('subscription_id');

        return Redirect::to($to);
    }

    /**
     * @param Request $request
     * @return void
     */
    public function paymentWebHook(Request $request): void
    {
        $token  = $request->input('payload.payment_link.entity.id');
        $status = $request->input('payload.payment_link.entity.status');

        $status = match ($status) {
            'cancelled', 'expired'        => WalletHistory::CANCELED,
            'paid'                        => WalletHistory::PAID,
            default => 'progress',
        };

        $this->service->afterHook($token, $status);
    }

}
