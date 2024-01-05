<?php

namespace App\Http\Controllers\Admin;

use App\CPU\Helpers;
use App\Http\Controllers\Controller;
use App\Model\DeliveryMan;
use App\Model\DeliveryManTransaction;
use App\Model\DeliverymanWallet;
use App\Traits\CommonTrait;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use function App\CPU\translate;
use App\CPU\BackEndHelper;

class DeliveryManCashCollectController extends Controller
{
    public function collect_cash($id)
    {
        $delivery_man = DeliveryMan::with('wallet')->find($id);
        $transactions = $delivery_man->transactions()->latest()->paginate(Helpers::pagination_limit());

        return view('admin-views.delivery-man.earning-statement.collect-cash', compact('delivery_man', 'transactions'));
    }

    public function cash_receive(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric|gt:0',
        ]);

        $wallet = DeliverymanWallet::where('delivery_man_id', $id)->first();
        $lang = Helpers::default_lang();

        if (empty($wallet) || BackEndHelper::currency_to_usd($request->input('amount'))  > $wallet->cash_in_hand) {
            Toastr::warning(translate('receive_amount_can_not_be_more_than_cash_in_hand'));
            return back();
        }

        $delivery_man = DeliveryMan::find($id);
        $delivery_man_fcm_token = $delivery_man?->fcm_token;
        if(!empty($delivery_man_fcm_token)) {
            $lang = $delivery_man?->app_language ?? $lang;
            $value_delivery_man = Helpers::push_notificatoin_message('cash_collect_by_admin_message','delivery_man', $lang);
            if ($value_delivery_man != null) {
                $data = [
                    'title' => BackEndHelper::set_symbol((BackEndHelper::currency_to_usd($request->input('amount')))).' '.translate('_cash_deposit'),
                    'description' => $value_delivery_man,
                    'image' => '',
                    'type' => 'notification'
                ];
                Helpers::send_push_notif_to_device($delivery_man_fcm_token, $data);
            }
        }

        $wallet->cash_in_hand -= $request->input('amount');
        DeliveryManTransaction::create([
            'delivery_man_id' => $id,
            'user_id'         => 0,
            'user_type'       => 'admin',
            'credit'           => BackEndHelper::currency_to_usd($request->input('amount')),
            'transaction_type' => 'cash_in_hand'
        ]);

        if ($wallet->save()) {
            Toastr::success(translate('amount_receive_successfully'));
            return back();
        }
        Toastr::error(translate('amount_receive_failed'));
        return back();
    }
}
