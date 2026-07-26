<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Base case for tests that need a booted application.
 *
 * The panel this suite runs against uses a live database, so no test is ever
 * allowed to reach it. Two guards enforce that:
 *
 *  1. phpunit.xml points the default connection at an in-memory SQLite
 *     database that is deliberately never migrated.
 *  2. setUp() installs a query listener that fails the test outright if
 *     anything issues a query, so a test that quietly grows a database
 *     dependency is caught immediately rather than passing against SQLite.
 *
 * Nothing here migrates, seeds or truncates anything.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'), 'Tests must not run against the real database.');
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        DB::listen(function ($query) {
            $this->fail('A test executed a database query, which is forbidden in this suite: '.$query->sql);
        });
    }
}
