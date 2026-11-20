<?php

// Seguimiento temporal. AGRONOMO_AUTH_TRACE=0 lo desactiva.
final class MobileAuthTrace
{
    private static $context = [];

    public static function setContext(string $method, $recordId): void
    {
        self::$context = [
            'metodo'=>substr($method, 0, 100),
            'registro_id'=>is_scalar($recordId) ? substr((string)$recordId, 0, 100) : null,
        ];
    }

    public static function write(string $event, string $token, array $detail = []): void
    {
        if (getenv('AGRONOMO_AUTH_TRACE') === '0') return;
        try {
            // Solo huella SHA-256 truncada: nunca registrar credenciales,
            // cabeceras completas ni el cuerpo de la petición.
            @\InduSoft\rlog(array_merge(self::$context, $detail, [
                'traza'=>'AGRONOMO_AUTH',
                'evento'=>$event,
                'token_huella'=>$token === '' ? null : substr(hash('sha256', $token), 0, 16),
                'token_longitud'=>strlen($token),
            ]));
        } catch (\Throwable $error) {
            // Un problema escribiendo el log no debe afectar la API.
        }
    }
}
