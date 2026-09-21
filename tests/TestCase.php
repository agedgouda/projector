<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Saves made by tests are logged like any other (see RecordSaveLogger); keep them out of
        // the real record-saves log, which is for what actually happened.
        config(['logging.channels.record_saves.path' => storage_path('logs/testing-record-saves.log')]);
    }
}
