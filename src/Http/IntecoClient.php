<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Cliente para la API del fabricante de las maquinas de vending
 * ("Intecco/Kabina", https://inteccocorp-services.com/api/v1). Antes las
 * credenciales y todas estas llamadas vivian en el JS del navegador
 * (system/adminVending/js/consultas.js), con la contrasena en texto plano
 * visible a cualquiera que abriera DevTools. Aqui todo corre server-side:
 * el navegador nunca ve INTECCO_API_USER/INTECCO_API_PASSWORD.
 *
 * cURL nativo a proposito (no Guzzle): este proyecto no tiene ningun
 * cliente HTTP vendorizado y se despliega por FTP sin "composer install"
 * en produccion -- agregar una dependencia nueva implicaria generar y
 * subir vendor/ de nuevo solo para esto.
 */
final class IntecoClient
{
    private string $baseUrl;
    private string $usuario;
    private string $password;
    private ?string $token = null;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) ($_ENV['INTECCO_API_BASE_URL'] ?? ''), '/');
        $this->usuario = (string) ($_ENV['INTECCO_API_USER'] ?? '');
        $this->password = (string) ($_ENV['INTECCO_API_PASSWORD'] ?? '');

        if ($this->baseUrl === '' || $this->usuario === '' || $this->password === '') {
            throw new \RuntimeException('Faltan INTECCO_API_BASE_URL/INTECCO_API_USER/INTECCO_API_PASSWORD en .env');
        }
    }

    /**
     * Inventario de carriles de una maquina (y, si $idMachine es null, de
     * todas). withDetails=true trae ademas el agregado por producto
     * (qtyAvailable/totalQty), igual que el legado.
     */
    public function getStock(?int $idMachine = null, bool $withDetails = true): array
    {
        $query = ['withDetails' => $withDetails ? 'true' : 'false'];
        if ($idMachine !== null) {
            $query['idMachine'] = $idMachine;
        }

        return $this->request('GET', '/inventory/getAll', $query);
    }

    /**
     * Actualiza un carril existente. OJO: quantity aqui es un DELTA a
     * sumar (o restar si negativo), no una cantidad absoluta -- asi lo
     * documenta el legado (main.js) para este endpoint especifico de
     * Intecco, es comportamiento de su API, no de este cliente.
     */
    public function updateInventory(array $payload): array
    {
        return $this->request('PUT', '/inventory/update', null, $payload);
    }

    /** Crea un carril nuevo (o re-crea uno vacio). */
    public function saveInventory(array $payload): array
    {
        return $this->request('POST', '/inventory/save', null, $payload);
    }

    /** Vacia un carril por completo. */
    public function removeInventory(array $payload): array
    {
        return $this->request('DELETE', '/inventory/removeInventoryFromMachine', null, $payload);
    }

    /** Descuenta/ajusta un lote del almacen central de Intecco (bookkeeping propio de Intecco, no del ERP). */
    public function updateWarehouseDetail(int $idDetail, array $payload): array
    {
        return $this->request('PUT', '/warehouse/detail/update/' . $idDetail, null, $payload);
    }

    private function login(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $respuesta = $this->request('POST', '/auth/login', null, [
            'userName' => $this->usuario,
            'password' => $this->password,
        ], autenticar: false);

        $token = $respuesta['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Intecco no devolvio un token de autenticacion valido');
        }

        return $this->token = $token;
    }

    /**
     * @param array<string,mixed>|null $query
     * @param array<string,mixed>|null $body
     */
    private function request(string $metodo, string $ruta, ?array $query = null, ?array $body = null, bool $autenticar = true): array
    {
        $url = $this->baseUrl . $ruta;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = ['Content-Type: application/json'];
        if ($autenticar) {
            $headers[] = 'Authorization: Bearer ' . $this->login();
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $respuestaCruda = curl_exec($ch);
        $errorCurl = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($respuestaCruda === false) {
            throw new \RuntimeException("Error de conexion con Intecco ({$metodo} {$ruta}): {$errorCurl}");
        }

        $decodificada = $respuestaCruda !== '' ? json_decode($respuestaCruda, true) : [];
        if ($status < 200 || $status >= 300) {
            $mensaje = is_array($decodificada) ? ($decodificada['message'] ?? $decodificada['msg'] ?? $respuestaCruda) : $respuestaCruda;
            throw new \RuntimeException("Intecco respondio {$status} en {$metodo} {$ruta}: {$mensaje}");
        }

        return is_array($decodificada) ? $decodificada : [];
    }
}
