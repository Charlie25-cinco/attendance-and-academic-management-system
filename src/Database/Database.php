<?php

namespace BshsAms\Database;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $sharedConnection = null;

    private $host;
    private $port;
    private $socket;
    private $database;
    private $username;
    private $password;
    private $sslCa;
    private $sslVerifyServerCert;
    private $connection;

    private function loadLocalConfig()
    {
        $localPath = dirname(__DIR__, 2) . '/config/Database.local.php';
        if (!file_exists($localPath)) {
            return [];
        }
        $config = require $localPath;
        return is_array($config) ? $config : [];
    }

    public function __construct()
    {
        $local = $this->loadLocalConfig();

        $this->host = appEnvValue('DB_HOST', (string)($local['host'] ?? '127.0.0.1'));
        $this->port = (int)appEnvValue('DB_PORT', (string)($local['port'] ?? 3306));
        $this->socket = appEnvValue('DB_SOCKET', (string)($local['socket'] ?? ''));
        $this->database = appEnvValue('DB_NAME', (string)($local['database'] ?? 'balingasagshs'));
        $this->username = appEnvValue('DB_USER', (string)($local['username'] ?? 'root'));
        $this->password = appEnvValue('DB_PASS', appEnvValue('DB_PASSWORD', (string)($local['password'] ?? '')));
        $this->sslCa = appEnvValue('DB_SSL_CA', appEnvValue('DB_SSL_CA_PATH', (string)($local['ssl_ca'] ?? '')));
        $sslCaContent = appEnvValue('DB_SSL_CA_CONTENT', '');
        if ($this->sslCa === '' && $sslCaContent !== '') {
            $caPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
                'bshs-tidb-ca-' . substr(hash('sha256', $sslCaContent), 0, 16) . '.pem';
            if (!is_file($caPath)) {
                @file_put_contents($caPath, $sslCaContent);
            }
            if (is_readable($caPath)) {
                $this->sslCa = $caPath;
            }
        }
        $verifyDefault = (string)($local['ssl_verify_server_cert'] ?? '1');
        $this->sslVerifyServerCert = appEnvValue('DB_SSL_VERIFY_SERVER_CERT', $verifyDefault) !== '0';
    }

    public static function resetSharedConnection(): void
    {
        self::$sharedConnection = null;
    }

    public function getConnection()
    {
        if (self::$sharedConnection instanceof PDO) {
            try {
                self::$sharedConnection->query('SELECT 1');
                $this->connection = self::$sharedConnection;
                return $this->connection;
            } catch (PDOException $e) {
                self::$sharedConnection = null;
            }
        }

        if ($this->connection instanceof PDO) {
            try {
                $this->connection->query('SELECT 1');
                self::$sharedConnection = $this->connection;
                return $this->connection;
            } catch (PDOException $e) {
                $this->connection = null;
            }
        }

        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->database . ";charset=utf8mb4";
            if ($this->socket !== '') {
                $dsn .= ";unix_socket=" . $this->socket;
            } else {
                $dsn .= ";port=" . $this->port;
            }

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ];
            if ($this->sslCa !== '' && defined('PDO::MYSQL_ATTR_SSL_CA')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $this->sslCa;
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $this->sslVerifyServerCert;
                }
            }

            $pdo = new PDO($dsn, $this->username, $this->password, $options);
            $pdo->exec("SET NAMES utf8mb4");
            self::$sharedConnection = $pdo;
            $this->connection = $pdo;
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
        }

        return $this->connection;
    }
}
