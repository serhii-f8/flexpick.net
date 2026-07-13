<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AuthStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['authenticated' => Auth::check()])
            ->header('Cache-Control', 'no-store, private');
    }
}
