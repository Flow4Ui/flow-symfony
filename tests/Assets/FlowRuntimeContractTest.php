<?php

namespace App\Tests\Assets;

use PHPUnit\Framework\TestCase;

class FlowRuntimeContractTest extends TestCase
{
    public function testRequestBatchStateUpdatesAreMirroredToMountedComponentInstances(): void
    {
        $runtime = file_get_contents(__DIR__ . '/../../assets/flow/components.js');

        self::assertNotFalse($runtime);
        self::assertTrue(str_contains(
            $runtime,
            'stateEntry.ctx.instance && stateEntry.ctx.instance !== stateEntry.state',
        ), 'RequestBatch should detect when the mounted component instance is separate from the request state.');
        self::assertTrue(str_contains(
            $runtime,
            'flow.assignState(stateEntry.name, stateEntry.ctx.instance, returnedState)',
        ), 'Returned state should be assigned into the mounted component instance.');
        self::assertTrue(str_contains(
            $runtime,
            "typeof stateEntry.ctx.instance.\$forceUpdate === 'function'",
        ), 'Mounted component instances should be forced to render after mirrored state updates.');
    }
}
