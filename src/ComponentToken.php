<?php

namespace giantbits\htmx;

use Yii;
use yii\web\ForbiddenHttpException;

/**
 * Encodes and decodes HMAC-signed tokens for component endpoint URLs.
 *
 * Token payload: {c: className, p: props, a: action}
 * Signed with the app's cookieValidationKey to prevent tampering.
 */
class ComponentToken
{
    public static function encode(string $class, array $props = [], string $action = 'render'): string
    {
        $payload = json_encode([
            'c' => $class,
            'p' => $props,
            'a' => $action,
        ], JSON_UNESCAPED_SLASHES);

        $sig = self::sign($payload);

        return base64_encode($payload . '.' . $sig);
    }

    public static function decode(string $token): array
    {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            throw new ForbiddenHttpException('Invalid component token.');
        }

        $lastDot = strrpos($decoded, '.');
        if ($lastDot === false) {
            throw new ForbiddenHttpException('Invalid component token.');
        }

        $payload = substr($decoded, 0, $lastDot);
        $sig = substr($decoded, $lastDot + 1);

        $expected = self::sign($payload);
        if (!hash_equals($expected, $sig)) {
            throw new ForbiddenHttpException('Invalid component token signature.');
        }

        $data = json_decode($payload, true);
        if (!is_array($data) || !isset($data['c'])) {
            throw new ForbiddenHttpException('Invalid component token payload.');
        }

        return [
            'class' => $data['c'],
            'props' => $data['p'] ?? [],
            'action' => $data['a'] ?? 'render',
        ];
    }

    private static function sign(string $payload): string
    {
        $key = Yii::$app->request->cookieValidationKey;

        return hash_hmac('sha256', $payload, $key);
    }
}
