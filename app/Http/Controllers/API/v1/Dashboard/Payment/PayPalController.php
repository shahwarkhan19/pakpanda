<?php

namespace App\Http\Controllers\API\v1\Dashboard\Payment;

use App\Helpers\ResponseError;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StripeRequest;
use App\Models\Order;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\PaymentPayload;
use App\Models\PaymentProcess;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\WalletHistory;
use App\Services\PaymentService\StripeService;
use App\Traits\ApiResponse;
use App\Traits\OnResponse;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Srmklive\PayPal\Services\PayPal;
use Str;
use Throwable;

class PayPalController extends Controller
{
    use OnResponse, ApiResponse;

    public function __construct(private StripeService $service)
    {
        parent::__construct();
    }

    /**
     * @throws Throwable
     */
    public function credential(array|null $payload): PayPal
    {
        $provider = new PayPal;

        $provider->setApiCredentials([
            'mode'    => data_get($payload, 'paypal_mode', 'sandbox'),
            'sandbox' => [
                'client_id'         => data_get($payload, 'paypal_sandbox_client_id'),
                'client_secret'     => data_get($payload, 'paypal_sandbox_client_secret'),
                'app_id'            => data_get($payload, 'paypal_sandbox_app_id'),
            ],
            'live' => [
                'client_id'         => data_get($payload, 'paypal_live_client_id'),
                'client_secret'     => data_get($payload, 'paypal_live_client_secret'),
                'app_id'            => data_get($payload, 'paypal_live_app_id'),
            ],
            'payment_action' => data_get($payload, 'paypal_payment_action', 'Sale'),
            'currency'       => data_get($payload, 'paypal_currency', 'USD'),
            'notify_url'     => data_get($payload, 'paypal_notify_url'),
            'locale'         => (bool)data_get($payload, 'paypal_locale', true),
            'validate_ssl'   => (bool)data_get($payload, 'paypal_validate_ssl', true),
        ]);

        $provider->getAccessToken();

        return $provider;
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
            $host           = request()->getSchemeAndHttpHost();
            $payment        = Payment::where('tag', Payment::TAG_PAY_PAL)->first();
            $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
            $payload        = $paymentPayload?->payload;

			/** @var ParcelOrder $order */
			$order = $request->input('parcel_id')
				? ParcelOrder::find($request->input('parcel_id'))
				: Order::find($request->input('order_id'));

            $totalPrice = ceil($order->rate_total_price * 2 * 100) / 2;

            $order->update([
                'total_price' => ($totalPrice / $order->rate) / 100
            ]);

            $url            = 'https://api-m.sandbox.paypal.com';
            $clientId       = data_get($payload, 'paypal_sandbox_client_id');
            $clientSecret   = data_get($payload, 'paypal_sandbox_client_secret');

            if (data_get($payload, 'paypal_mode', 'sandbox') === 'live') {
                $url            = 'https://api-m.paypal.com';
                $clientId       = data_get($payload, 'paypal_live_client_id');
                $clientSecret   = data_get($payload, 'paypal_live_client_secret');
            }

            $provider = new Client();
            $responseAuth = $provider->post("$url/v1/oauth2/token", [
                'auth' => [
                    $clientId,
                    $clientSecret,
                ],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                ]
            ]);

            $responseAuth = json_decode($responseAuth->getBody(), true);

            $response = $provider->post("$url/v2/checkout/orders", [
                'json' => [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [
                        [
                            'amount' => [
                                'currency_code' => Str::upper($order->currency?->title ?? data_get($payload, 'paypal_currency')),
                                'value' => $totalPrice,
                            ],
                        ],
                    ],
                    'application_context' => [
                        'return_url' =>  "$host/order-stripe-success?" . (
            				$request->input('parcel_id') ? "parcel_id=$order->id" : "order_id=$order->id"
        				),
                        'cancel_url' =>  "$host/order-stripe-success?" . (
            				$request->input('parcel_id') ? "parcel_id=$order->id" : "order_id=$order->id"
        				),
                    ],
                ],
                'headers' => [
                    'Accept-Language' => 'en_US',
                    'Content-Type'    => 'application/json',
                    'Authorization'   => data_get($responseAuth, 'token_type', 'Bearer') . ' ' . data_get($responseAuth, 'access_token'),
                ],
            ]);

            $response = json_decode($response->getBody(), true);

            if (data_get($response, 'error')) {

                $message = data_get($response, 'message', 'Something went wrong');

                return $this->onErrorResponse([
                    'code' => ResponseError::ERROR_400,
                    'data' => is_array($message) ? $message : [$message]
                ]);
            }

            $links = collect(data_get($response, 'links'));

            $checkoutNowUrl = data_get($links->where('rel', 'approve')->first(), 'href');
            $checkoutNowUrl = $checkoutNowUrl ?? data_get($links->where('rel', 'payer-action')->first(), 'href');
            $checkoutNowUrl = $checkoutNowUrl ?? data_get($links->first(), 'href');

            $paymentProcess = PaymentProcess::updateOrCreate([
                'user_id' 	 => auth('sanctum')->id(),
				'model_id'   => $order->id,
				'model_type' => get_class($order)
            ], [
                'id' => data_get($response, 'id'),
                'data' => [
                    'url' 		 => $checkoutNowUrl,
                    'price' 	 => $totalPrice,
					'payment_id' => $payment->id,
                ]
            ]);

            return $this->successResponse('success', $paymentProcess);
        } catch (Throwable $e) {
            return $this->onErrorResponse([
                'code'      => ResponseError::ERROR_400,
                'message'   => $e->getMessage(),
            ]);
        }

    }

	/**
	 * @param Request $request
	 * @param Shop $shop
	 * @return JsonResponse
	 */
	public function subscriptionProcessTransaction(Request $request, Shop $shop): JsonResponse
	{
		try {
			$host           = request()->getSchemeAndHttpHost();
			$payment        = Payment::where('tag', Payment::TAG_PAY_PAL)->first();
			$paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
			$payload        = $paymentPayload?->payload;
			$subscription   = Subscription::find($request->input('subscription_id'));

			$url            = 'https://api-m.sandbox.paypal.com';
			$clientId       = data_get($payload, 'paypal_sandbox_client_id');
			$clientSecret   = data_get($payload, 'paypal_sandbox_client_secret');

			if (data_get($payload, 'paypal_mode', 'sandbox') === 'live') {
				$url            = 'https://api-m.paypal.com';
				$clientId       = data_get($payload, 'paypal_live_client_id');
				$clientSecret   = data_get($payload, 'paypal_live_client_secret');
			}

			$provider = new Client();
			$responseAuth = $provider->post("$url/v1/oauth2/token", [
				'auth' => [
					$clientId,
					$clientSecret,
				],
				'form_params' => [
					'grant_type' => 'client_credentials',
				]
			]);

			$responseAuth = json_decode($responseAuth->getBody(), true);

			$response = $provider->post("$url/v2/checkout/orders", [
				'json' => [
					'intent' => 'CAPTURE',
					'purchase_units' => [
						[
							'amount' => [
								'currency_code' => Str::upper($order->currency?->title ?? data_get($payload, 'paypal_currency')),
								'value' => ceil($subscription->price) * 100,
							],
						],
					],
					'application_context' => [
						'return_url' => "$host/subscription-stripe-success?subscription_id=$subscription->id",
						'cancel_url' => "$host/subscription-stripe-success?subscription_id=$subscription->id",
					],
				],
				'headers' => [
					'Accept-Language' => 'en_US',
					'Content-Type'    => 'application/json',
					'Authorization'   => data_get($responseAuth, 'token_type', 'Bearer') . ' ' . data_get($responseAuth, 'access_token'),
				],
			]);

			$response = json_decode($response->getBody(), true);

			if (data_get($response, 'error')) {

				$message = data_get($response, 'message', 'Something went wrong');

				return $this->onErrorResponse([
					'code' => ResponseError::ERROR_400,
					'data' => is_array($message) ? $message : [$message]
				]);
			}

			$links = collect(data_get($response, 'links'));

			$checkoutNowUrl = data_get($links->where('rel', 'approve')->first(), 'href');
			$checkoutNowUrl = $checkoutNowUrl ?? data_get($links->where('rel', 'payer-action')->first(), 'href');
			$checkoutNowUrl = $checkoutNowUrl ?? data_get($links->first(), 'href');

			$paymentProcess = PaymentProcess::updateOrCreate([
				'user_id' 	 => auth('sanctum')->id(),
				'model_id'   => $subscription->id,
				'model_type' => get_class($subscription)
			], [
				'id' => data_get($response, 'id'),
				'data' => [
					'url' 	          => $checkoutNowUrl,
					'price' 		  => ceil($subscription->price) * 100,
					'shop_id'         => $shop->id,
					'subscription_id' => $subscription->id,
					'payment_id' 	  => $payment->id,
				]
			]);

			return $this->successResponse('success', $paymentProcess);
		} catch (Throwable $e) {
			return $this->onErrorResponse([
				'code' => ResponseError::ERROR_400,
				'data' => $e->getMessage()
			]);
		}
	}

    /**
     * @param Request $request
     * @return void
     */
    public function paymentWebHook(Request $request): void
    {
        $status = $request->input('resource.status');

        $status = match ($status) {
            'APPROVED', 'COMPLETED', 'CAPTURED' => WalletHistory::PAID,
            'VOIDED' => WalletHistory::CANCELED,
            default  => 'progress',
        };

        $token = $request->input('data.object.id');

        $this->service->afterHook($token, $status);
    }

}
