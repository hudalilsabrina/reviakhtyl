<?php

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Only tests under tests/Feature boot the framework. Everything under
| tests/Unit exercises plain objects and runs on PHPUnit's own test case, so
| the application (and therefore the settings table, the cache and the queue)
| is never touched by them at all.
|
*/

pest()->extend(TestCase::class)->in('Feature');

pest()->use(MockeryPHPUnitIntegration::class)->in('Unit');
