<?php

namespace App\Support;

final class Phone
{
    /**
     * 日本の電話番号を E.164(+81...) に正規化する
     * - 入力例: "090-1234-5678", "09012345678", "+819012345678", "819012345678"
     * - 返り値: "+819012345678"
     * - 変換できない場合は null
     */
    public static function toE164JP(?string $input): ?string
    {
        if ($input === null) return null;

        $s = trim($input);
        if ($s === '') return null;

        // 全角→半角っぽい文字を削る/正規化（数字と+だけ残す）
        $s = preg_replace('/[^\d\+]/u', '', $s) ?? '';

        // すでに + から始まるなら、そのまま使えるかチェック
        if (str_starts_with($s, '+')) {
            // +81xxxxxxxxxx の形だけ許可（ざっくり）
            if (preg_match('/^\+81\d{9,10}$/', $s)) {
                return $s;
            }
            return null;
        }

        // 先頭 00 国際プレフィックスを + にする（例: 0081...）
        if (str_starts_with($s, '00')) {
            $s = '+' . substr($s, 2);
            if (preg_match('/^\+81\d{9,10}$/', $s)) {
                return $s;
            }
            return null;
        }

        // "81..." だけ来た場合も +81... に
        if (str_starts_with($s, '81')) {
            $s = '+'.$s;
            if (preg_match('/^\+81\d{9,10}$/', $s)) {
                return $s;
            }
            return null;
        }

        // 国内表記（0始まり）→ +81（先頭の0を落とす）
        if (str_starts_with($s, '0')) {
            $rest = substr($s, 1);

            // 日本の番号は市外局番等で桁が色々あるので、ここは現実的に「10桁 or 9桁」に絞る
            // 0を取った後が 9〜10桁なら +81 を付ける（例: 90xxxxxxxx (10桁の一部) / 3xxxxxxxx (9桁)）
            if (preg_match('/^\d{9,10}$/', $rest)) {
                return '+81' . $rest;
            }
            return null;
        }

        // それ以外は不明
        return null;
    }
}
