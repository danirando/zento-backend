<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Qui metterai tutta la logica per la tua dashboard
        return response()->json([
            'status' => 'success',
            'message' => 'Welcome to the protected dashboard!',
            'user_id' => $request->user()->id,
        ]);
    }
}