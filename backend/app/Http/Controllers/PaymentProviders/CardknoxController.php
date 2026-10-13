<?php

namespace App\Http\Controllers\PaymentProviders;

use App\Http\Controllers\Controller;
use App\Services\PaymentProviders\Cardknox\CardknoxWebhookHandler;
use Illuminate\Http\Request;

class CardknoxController extends Controller
{
    public function handleWebhook(Request $request, CardknoxWebhookHandler $handler)
    {
        return $handler->handleWebhook($request);
    }
}
