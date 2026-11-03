<?php
declare(strict_types=1);

namespace App\Telemedicina;

use App\Database\Connection;
use PDO;

final class LegacyConnection
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        $host = trim((string)($_ENV['TELEMEDICINA_DB_HOST'] ?? ''));
        if ($host === '') return Connection::get();
        if (self::$instance) return self::$instance;
        $port=(string)($_ENV['TELEMEDICINA_DB_PORT']??'1433');
        $name=(string)($_ENV['TELEMEDICINA_DB_NAME']??'');
        $user=(string)($_ENV['TELEMEDICINA_DB_USER']??'');
        $pass=(string)($_ENV['TELEMEDICINA_DB_PASS']??'');
        $instance=trim((string)($_ENV['TELEMEDICINA_DB_INSTANCE']??''));
        $server=$instance!==''?"{$host}\\{$instance}":$host;
        $trust=filter_var($_ENV['TELEMEDICINA_DB_TRUST_SERVER_CERTIFICATE']??'true',FILTER_VALIDATE_BOOLEAN)?'true':'false';
        self::$instance=new PDO("sqlsrv:Server={$server},{$port};Database={$name};TrustServerCertificate={$trust}",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        return self::$instance;
    }
}
