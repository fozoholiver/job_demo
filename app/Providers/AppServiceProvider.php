<?php

namespace App\Providers;

use App\Notifications\QueueFailed;
use App\Notifications\SlackFailedJob;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {


//        Queue::failing(function (JobFailed $event) {
//
//            Log::channel('slack')->error([
//                "name "=>'oliver',
//                "name3 "=>'oliver',
//                "name33 "=>'oliver',
//                "name333 "=>'oliver',
//                "name3333 "=>'oliver',
//            ]);
//        });

    }
}
