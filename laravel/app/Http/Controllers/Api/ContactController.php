<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    public function contact(Request $request)
    {
        $validated = $request->validate([
            'email'      => ['required', 'string', 'max:255'],
            'name'       => ['required', 'string', 'max:255'],
            'text'       => ['required', 'string', 'max:255'],
        ]);
        require_once base_path('functions/locationInfo.php');
        $pdo = DB::connection()->getPdo();
        $useremail = $validated['email'] ?? '';
        $username = $validated['name'] ?? '';
        $commentdata = $validated['text'] ?? '';
        insertContactData($pdo, $useremail, $username , $commentdata);
        return response()->json([
            'status' => 'success',
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}