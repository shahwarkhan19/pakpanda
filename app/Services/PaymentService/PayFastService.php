<?php

namespace App\Services\PaymentService;

use App\Models\Cart;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\PaymentPayload;
use App\Models\PaymentProcess;
use App\Models\Payout;
use App\Models\Subscription;
use Exception;
use Http;
use Illuminate\Http\Client\Response;

class PayFastService extends BaseService
{
    protected function getModelClass(): string
    {
        return Payout::class;
    }

    /**
     * @param array $data
     * @return Response
     * @throws Exception
     */
    public function orderProcessTransaction(array $data): Response
    {
        $payment = Payment::where('tag', Payment::TAG_PAY_FAST)->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        /** @var ParcelOrder $order */
        $order = data_get($data, 'parcel_id')
			? ParcelOrder::find(data_get($data, 'parcel_id'))
			: Cart::find(data_get($data, 'cart_id'));

        $host = request()->getSchemeAndHttpHost();
        $key  = data_get($data, 'parcel_id') ? 'parcel_id' : 'cart_id';
        $url  = "$host/order-pay-fast-success?$key=$order->id";

        $notifyUrl = "$host/api/v1/webhook/pay-fast/payment?$key=$order->id";

        $body = [
        	'merchant_id' 	=> data_get($payload, 'merchant_id'),
        	'merchant_key' 	=> data_get($payload, 'merchant_key'),
        	'return_url' 	=> $url,
        	'cancel_url' 	=> $url,
        	'notify_url' 	=> $notifyUrl,
        	'amount' 		=> ceil($order->total_price),
        	'item_name' 	=> 'test',
        	'button-cta' 	=> 'pay now',
        	'name_first' 	=> $order?->user?->firstname,
        	'name_last' 	=> $order?->user?->lastname,
        	'email_address' => $order?->user?->email,
        	'cell_number'   => $order?->user?->phone,
		];

        $signature = $this->generateSignature($body);

        $body['signature'] = $signature;

        PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
            'model_id'   => $order->id,
            'model_type' => get_class($order)
        ], [
            'id' => $signature,
            'data' => [
                'url'   	 => $notifyUrl,
                'price'		 => ceil($order->total_price),
				'cart'		 => $data,
				'payment_id' => $payment->id,
            ]
        ]);

        return Http::post('https://sandbox.payfast.co.za/eng/process', $body);
    }

    /**
     * @param array $data
     * @param $shop
     * @return Response
     */
    public function subscriptionProcessTransaction(array $data, $shop): Response
    {
        $payment = Payment::where('tag', Payment::TAG_STRIPE)->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        /** @var Subscription $subscription */
        $subscription = Subscription::find(data_get($data, 'subscription_id'));

        $host = request()->getSchemeAndHttpHost();
        $key  = data_get($data, 'subscription_id');
        $url  = "$host/subscription-pay-fast?$key=$subscription->id";

        $notifyUrl = "$host/api/v1/webhook/pay-fast/payment?$key=$subscription->id";

        $body = [
            'merchant_id'  => data_get($payload, 'merchant_id'),
            'merchant_key' => data_get($payload, 'merchant_key'),
            'return_url'   => $url,
            'cancel_url'   => $url,
            'notify_url'   => $notifyUrl,
            'amount' 	   => ceil($subscription->price),
            'item_name'    => 'test',
            'button-cta'   => 'pay now',
        ];

        $signature = $this->generateSignature($body);

		$body['signature'] = $signature;

        PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
            'model_id'   => $subscription->id,
            'model_type' => get_class($subscription)
        ], [
            'id' => $signature,
            'data' => [
                'shop_id' 		  => $shop->id,
                'url'     		  => $notifyUrl,
                'price'   		  => ceil($subscription->price),
                'subscription_id' => $subscription->id,
				'payment_id' 	  => $payment->id,
            ]
        ]);

        return Http::post('https://sandbox.payfast.co.za/eng/process', $body);
    }

	/**
	 * @param array $data
	 * @param string|null $passPhrase
	 * @return string
	 */
    private function generateSignature(array $data, ?string $passPhrase = null): string
    {
        // Create parameter string
        $pfOutput = '';

        foreach($data as $key => $val ) {

            if ($val !== '') {
                $pfOutput .= $key . '=' . urlencode(trim($val)) . '&';
            }

        }

        $getString = substr($pfOutput, 0, -1);

        if (!empty($passPhrase)) {
            $getString .= '&passphrase='. urlencode(trim($passPhrase));
        }

        return md5( $getString );
    }
}
