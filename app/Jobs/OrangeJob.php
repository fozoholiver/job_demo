<?php

namespace App\Jobs;


use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use function Laravel\Prompts\table;

class OrangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//    public $tries = 1;
    public $orange;
    public $new;

    public function __construct($orange)
    {
        $this->orange = $orange;
    }

    public function handle(): void
    {

//             DB::connection('mysql')
//            ->table('test')
//            ->insert(['next'=>'hi']);

        $this->checkOrangeTransactions($this->orange);
//
//}

    }

    function checkOrangeTransactions($orange)
    {

        $token = $this->get_token();//we generate payment token
//we request transaction details
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api-s1.orange.cm/omcoreapis/1.0.2/mp/paymentstatus/' . $orange->payToken,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $token,
                'X-AUTH-TOKEN: ' . env('ORANGE_X_AUTH_TOKEN'),
                'Content-Type: application/json'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $result_data = json_decode($response);
//        we check if transaction was sucessfull

        if ($result_data->data->status == 'SUCCESSFULL') {
//            we convert from XAF to usd
            $frate = DB::connection('connectpay')
                ->table('fiats')
                ->where('code', '=', $orange->currency)
                ->first();
            $rate = $frate->rate;
            $totalAmount_without_charge = $orange->pri_amount / $rate;
            $totalAmount = $totalAmount_without_charge - ((env('ORANGE_CHARGE') / 100) * $totalAmount_without_charge);

//            we update our transactions
            $rtrx = $this->getTrx();


//                we get the receiver account
            $receiver = DB::connection('connectpay')
                ->table('users')
                ->where('id', '=', $orange->user_id)
                ->first();
//we confirm if the payment is comming from a payment link or deposit
            if (!empty($orange->paylink)) {// we check if the payment is coming from a payment link
                $paylinks = DB::connection('connectpay')
                    ->table('paylink')
                    ->where('paylink', '=', $orange->paylink)->first();

                if ($paylinks->status !== "completed") {// we check if the payment hasen't been completed before
//            we save the money in the receiver,wallet
                    DB::connection('connectpay')
                        ->table('users')
                        ->where('id', '=', $orange->user_id)
                        ->update(['balance' => DB::raw('balance + ' . $totalAmount),
                        ]);

//                    we create new transaction
                    DB::connection('connectpay')
                        ->table('transactions')
                        ->insert([
                            'user_id' => $receiver->id,
                            'amount' => $totalAmount,
                            'post_balance' => $receiver->balance,
                            'charge' => env('ORANGE_CHARGE'),
                            'trx_type' => '+',
                            'details' => 'Money Transfer from Orange Cameroon amount XAF' . $totalAmount . ' successful. Transaction id' . $result_data->data->txnid,
                            'trx' => $rtrx,
                        ]);


                    DB::connection('connectpay')
                        ->table('paylink')
                        ->where('paylink', '=', $orange->paylink)
                        ->update([
                            'status' => "completed",
                            'user_id' => $orange->user_id,
                            'updated_at' => date_format(Carbon::now(), "Y-m-d H:i:s")
                        ]);

                    $link = $paylinks->return_url;
                    if (!empty($link)) {
//                here we want to send a CURL get request to the callback url with the success information
                        $curl = curl_init();
                        curl_setopt_array($curl, array(
                            CURLOPT_URL => $link . "?code=200&status=success&txn_ref=" . $paylinks->trx . "&merchant_ref=" . $paylinks->merchant_ref,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING => '',
                            CURLOPT_MAXREDIRS => 10,
                            CURLOPT_SSL_VERIFYHOST => 0,
                            CURLOPT_SSL_VERIFYPEER => 0,
                            CURLOPT_TIMEOUT => 0,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST => 'GET',
                        ));

                        $response = curl_exec($curl);

                        curl_close($curl);
                        return $response;

                    }
                }
            } else {

//            we save the money in the receiver,wallet
                DB::connection('connectpay')->table('users')
                    ->where('id', '=', $orange->user_id)
                    ->update(['balance' => DB::raw('balance + ' . $totalAmount),
                    ]);

                $userId = $orange->user_id;
                DB::connection('connectpay')->table('deposits')
                    ->insertGetId([
                        "user_id" => $userId,
                        "method_code" => 0,
                        "method_currency" => strtoupper($orange->currency),
                        "amount" => $totalAmount,
                        "charge" => 0,
                        "rate" => env('ORANGE_CHARGE'),
                        "final_amo" => $orange->pri_amount,
                        "btc_amo" => 0,
                        "btc_wallet" => '',
                        "trx" => $this->getTrx(),
                        "try" => 0,
                        "status" => 1,
                        "dp" => '',
                    ]);
            }

            DB::connection('connectpay')->table('oranges')
                ->where('payToken', $orange->payToken)
                ->update([
                    'status' => $result_data->data->status,
                    'txnid' => $result_data->data->txnid,
                ]);
        } else {
            DB::connection('connectpay')->table('oranges')
                ->where('payToken', $orange->payToken)
                ->update([
                    'status' => $result_data->data->status,
                    'txnid' => $result_data->data->txnid,
                ]);
        }
    }

    function get_token()
    {


        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api-s1.orange.cm/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization:Basic ' . env('ORANGE_AUTHORIZATION'),
            ),
        ));
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err) {
            return "cURL Error #:" . $err;
        } else {

            $results = json_decode($response);

        }
        return $results->access_token;

    }

    function getTrx($length = 12)
    {
        $characters = '123456789';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public function failed(Throwable $e)
    {
        Log::channel('slack')->error($e->getMessage(), [
            'file' => $e->getFile(),
            'Line' => $e->getLine(),
            'code' => $e->getCode(),
        ]);


        
    }

}





