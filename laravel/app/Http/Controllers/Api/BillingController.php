<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class BillingController extends Controller
{
    public function createCheckout(Request $request)
    {
        $request->validate([
            'user_id' => ['required','integer'],
            'plan_id' => ['required','integer'],
        ]);

        $user = User::findOrFail($request->integer('user_id'));
        $plan = DB::table('plan_data')->where('id', $request->integer('plan_id'))->where('is_deleted',0)->first();
        if (!$plan || empty($plan->stripe_price_id)) {
            return response()->json(['error' => 'プランが不正です'], 422);
        }

        // まだCustomerが無ければ作る（Cashier）
        if (!$user->stripe_id) {
            $user->createAsStripeCustomer([
                // emailはStripe customerに入れると後で便利
                'email' => $user->email ?: null,
                'name'  => $user->username,
            ]);
        }

        // あなたのNext.jsに合わせて戻り先URL（環境変数推奨）
        $successUrl = config('app.frontend_url') . '/done?session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl  = config('app.frontend_url') . '/signUp?canceled=1';

        // ✅ subscription checkout session
        $checkout = $user->newSubscription('default', $plan->stripe_price_id)
            ->checkout([
                'success_url' => $successUrl,
                'cancel_url'  => $cancelUrl,
                'metadata' => [
                    'user_id' => (string)$user->id,
                    'plan_id' => (string)$plan->id,
                ],
            ]);

        // $checkout は RedirectResponse ではなく配列形式（Cashier版により）になる場合があるので
        // 返り値を確認して URL を返す形に寄せるのが安全
        return response()->json([
            'checkout_url' => $checkout->url ?? $checkout['url'] ?? null,
        ]);
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'plan_type' => ['required', 'string'],
        ]);
        require_once base_path('functions/user.php');
        $pdo = DB::connection()->getPdo();
        $planData = planPrice($pdo, $request->plan_type);
        if (!$planData || $planData['stripe_price_id'] == '' || $planData['stripe_price_id'] == null) {
            return response()->json(
                ['status' => 'error', 'error' => 'ページ対象外です。'],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        } else {
            $planId = $planData['stripe_price_id'];
        }

        $user = Auth::user();

        if ($user->subscribed('default')) {
            return response()->json(
                ['status' => 'error', 'error' => ''],
                404,
                [],
                JSON_UNESCAPED_UNICODE
            );
        }

        return $user
            ->newSubscription('default', $planId)
            ->checkout([
                'success_url' => config('app.url') . '/billing/success',
                'cancel_url'  => config('app.url') . '/billing/cancel',
            ]);
    }
}
