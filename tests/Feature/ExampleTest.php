<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_identifies_the_api(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertExactJson(['name' => 'team-ops-api']);
    }
}
