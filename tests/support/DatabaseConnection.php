<?php

declare(strict_types=1);

namespace yii2\extensions\softdelete\tests\support;

use yii\db\Connection;

enum DatabaseConnection: string
{
    case MSSQL = 'sqlsrv';
    case MYSQL = 'mysql';
    case ORACLE = 'oci';
    case PGSQL = 'pgsql';
    case SQLITE = 'sqlite';

    /**
     * @return array{class: class-string<Connection>, dsn: string, username?: string, password?: string}
     */
    public function configuration(): array
    {
        $connection = match ($this) {
            self::MSSQL => [
                'class' => Connection::class,
                'dsn' => 'sqlsrv:Server=127.0.0.1,1433;Database=yiitest;Encrypt=no',
                'username' => 'SA',
                'password' => 'YourStrong!Passw0rd',
            ],
            self::MYSQL => [
                'class' => Connection::class,
                'dsn' => 'mysql:host=127.0.0.1;dbname=yiitest;charset=utf8mb4',
                'username' => 'root',
                'password' => 'root',
            ],
            self::ORACLE => [
                'class' => Connection::class,
                'dsn' => 'oci:dbname=localhost/FREEPDB1;charset=AL32UTF8;',
                'username' => 'system',
                'password' => 'root',
            ],
            self::PGSQL => [
                'class' => Connection::class,
                'dsn' => 'pgsql:host=localhost;port=5432;dbname=yiitest',
                'username' => 'root',
                'password' => 'root',
            ],
            self::SQLITE => [
                'class' => Connection::class,
                'dsn' => 'sqlite::memory:',
            ],
        };

        foreach (['dsn', 'username', 'password'] as $key) {
            $value = getenv("SOFT_DELETE_TEST_{$this->name}_" . strtoupper($key));

            if ($value !== false) {
                $connection[$key] = $value;
            }
        }

        return $connection;
    }
}
