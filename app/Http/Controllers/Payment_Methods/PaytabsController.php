<?php

namespace App\Http\Controllers\Payment_Methods;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Traits\Processor;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Yabacon\Paystack;

class PaystackController extends Controller
{
    use Processor;

    private $paystack;

    public function __construct(PaymentRequest $payment, User $user)
    {
        $this->payment = $payment;
        $this->user = $user;
        $this->paystack = new Paystack(env('PAYSTACK_SECRET_KEY'));
    }

    public function payment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|uuid'
        ]);

        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $payment_data = $this->payment::where(['id' => $request['payment_id']])->where(['is_paid' => 0])->first();
        if (!isset($payment_data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }
        $payer = json_decode($payment_data['payer_information']);

        $reference = Paystack::genTranxRef();

        try {
            $payment = $this->paystack->transaction->initialize([
                'amount' => $payment_data->payment_amount * 100, // amount in kobo
                'email' => $payer->email,
                'reference' => $reference,
                'callback_url' => route('paystack.callback', ['payment_id' => $payment_data->id]),
            ]);

            return redirect($payment->data->authorization_url);
        } catch (\Exception $e) {
            return response()->json(['error' => 'The payment could not be processed.'], 500);
        }
    }

    public function callback(Request $request)
    {
        $reference = $request->query('reference');

        try {
            $paymentDetails = $this->paystack->transaction->verify(['reference' => $reference]);

            if ($paymentDetails->data->status === 'success') {
                $this->payment::where(['id' => $request['payment_id']])->update([
                    'payment_method' => 'paystack',
                    'is_paid' => 1,
                    'transaction_id' => $reference,
                ]);

                $payment_data = $this->payment::where(['id' => $request['payment_id']])->first();
                if (isset($payment_data) && function_exists($payment_data->success_hook)) {
                    call_user_func($payment_data->success_hook, $payment_data);
                }

                return $this->payment_response($payment_data, 'success');
            } else {
                $payment_data = $this->payment::where(['id' => $request['payment_id']])->first();
                if (isset($payment_data) && function_exists($payment_data->failure_hook)) {
                    call_user_func($payment_data->failure_hook, $payment_data);
                }

                return $this->payment_response($payment_data, 'fail');
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred while verifying the payment.'], 500);
        }
    }

    public function response(Request $request)
    {
        return response()->json($this->response_formatter(GATEWAYS_DEFAULT_200), 200);
    }
}
