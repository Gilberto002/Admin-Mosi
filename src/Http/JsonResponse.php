<?php

declare(strict_types=1);

namespace App\Http;

use App\Security\Security;
use Throwable;

/**
 * Estandariza la forma en que todos los endpoints devuelven JSON,
 * para que el frontend (fetch/AJAX) siempre reciba la misma forma:
 * { "ok": true/false, "data": ..., "error": ... }
 */
final class JsonResponse
{
    public static function ok(mixed $data = null, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $mensaje, int $status = 400, mixed $data = null): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $respuesta = ['ok' => false, 'error' => $mensaje];
        if ($data !== null) {
            $respuesta['data'] = $data;
        }
        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function serverError(Throwable $e, string $context = 'api'): never
    {
        self::error(Security::publicError($e, $context), 500);
    }
}
