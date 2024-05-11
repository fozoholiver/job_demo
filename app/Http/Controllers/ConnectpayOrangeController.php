<?php

namespace App\Http\Controllers;
use App\Jobs\OrangeJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ConnectpayOrangeController extends Controller
{
    public function index()
    {
        //we get requests one day older
        $oranges = DB::connection('connectpay')
            ->table('oranges')
            ->where('created_at', '>', Carbon::now()->subDays(1)->toDateTimeString())
            ->where('status', '=', 'pending')
            ->get();
        if (!empty($oranges)){
            foreach ($oranges as $orange) {
                OrangeJob::dispatch($orange);
            }
        }

        //jhjh
//        $orange='hello';
//        OrangeJob::dispatch($orange);
    }


}
