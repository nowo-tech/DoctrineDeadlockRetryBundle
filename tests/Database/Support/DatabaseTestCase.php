<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function dirname;
use function getenv;
use function is_string;
use function str_starts_with;
use function usleep;

use const PHP_VERSION_ID;

/**
 * Base class of the real-database tests. They run only when the DSN of a server is set:
 * DDRB_MYSQL_URL, DDRB_MARIADB_URL, DDRB_MARIADB118_URL (mysql://…) and DDRB_POSTGRES_URL (pgsql://…).
 * `make test-db` starts the servers.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;

    protected TestManagerRegistry $registry;

    protected ?SecondConnection $other = null;

    protected string $url = '';

    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlFamily(): iterable
    {
        yield 'MySQL 8.4' => ['DDRB_MYSQL_URL'];
        yield 'MariaDB 11.4' => ['DDRB_MARIADB_URL'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allServers(): iterable
    {
        yield from self::mysqlFamily();
        yield 'PostgreSQL 16' => ['DDRB_POSTGRES_URL'];
    }

    protected function isPostgreSql(): bool
    {
        return str_starts_with($this->url, 'pgsql');
    }

    /**
     * Runs one query on a fresh connection (another session) and returns the first column of the first row.
     */
    protected function freshValue(string $sql): mixed
    {
        $parser = new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']);
        $fresh  = DriverManager::getConnection($parser->parse($this->url));
        $value  = $fresh->fetchOne($sql);
        $fresh->close();

        return $value;
    }

    protected function boot(string $environmentVariable): void
    {
        $url = getenv($environmentVariable);

        if (!is_string($url) || $url === '') {
            self::markTestSkipped($environmentVariable . ' is not set (run `make test-db`).');
        }

        $this->url        = $url;
        $parser           = new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']);
        $this->connection = DriverManager::getConnection($parser->parse($url));
        $this->connection->executeStatement('DROP TABLE IF EXISTS ddrb_counter');
        $this->connection->executeStatement('DROP TABLE IF EXISTS ddrb_ballast');
        $this->connection->executeStatement('CREATE TABLE ddrb_counter (id INT NOT NULL PRIMARY KEY, counter_value INT NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE ddrb_ballast (id INT NOT NULL PRIMARY KEY)');
        $this->connection->executeStatement('INSERT INTO ddrb_counter (id, counter_value) VALUES (1, 0), (2, 0)');

        $this->registry = new TestManagerRegistry($this->connection, static function (Connection $connection): EntityManagerInterface {
            $configuration = new Configuration();
            $configuration->setMetadataDriverImpl(new AttributeDriver([__DIR__]));
            $configuration->setMetadataCache(new ArrayAdapter());
            $configuration->setProxyDir(dirname(__DIR__, 3) . '/var/cache/proxies');
            $configuration->setProxyNamespace('DdrbTestProxies');
            $configuration->setAutoGenerateProxyClasses(true);

            if (PHP_VERSION_ID >= 80400) {
                $configuration->enableNativeLazyObjects(true);
            }

            return new EntityManager($connection, $configuration);
        });
    }

    protected function tearDown(): void
    {
        $this->other?->close();
        $this->other = null;

        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    protected function otherSession(): SecondConnection
    {
        return $this->other ??= new SecondConnection($this->url);
    }

    protected function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    protected function service(int $maxRetries = 3, bool $retryLockWaitTimeout = true): DeadlockRetryService
    {
        return new DeadlockRetryService(
            $this->manager(),
            ['default' => new RetryProfile('default', $maxRetries, 0, retryLockWaitTimeout: $retryLockWaitTimeout)],
            'default',
            $this->registry,
        );
    }

    /**
     * Reads a counter through the second session's view of committed data (a fresh connection).
     */
    protected function committedValue(int $id): int
    {
        $parser = new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']);
        $fresh  = DriverManager::getConnection($parser->parse($this->url));
        $value  = $fresh->fetchOne('SELECT counter_value FROM ddrb_counter WHERE id = ?', [$id]);
        $fresh->close();

        return (int) $value;
    }

    /**
     * Makes the second session hold row 2 (and enough other rows to be the heavier transaction, so InnoDB picks the
     * test's connection as the deadlock victim), then makes it wait for row 1, which the test's connection holds.
     */
    protected function startDeadlockingPeer(): void
    {
        $other = $this->otherSession();
        self::assertSame('OK', $other->execute('BEGIN'));
        self::assertSame('OK', $other->execute('UPDATE ddrb_counter SET counter_value = counter_value + 100 WHERE id = 2'));

        for ($i = 1; $i <= 20; ++$i) {
            self::assertSame('OK', $other->execute('INSERT INTO ddrb_ballast (id) VALUES (' . $i . ')'));
        }

        $other->send('UPDATE ddrb_counter SET counter_value = counter_value + 100 WHERE id = 1');
        usleep(300_000);
    }
}
