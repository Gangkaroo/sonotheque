<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class AdminAccessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['authorized' => true]);
    }
}
