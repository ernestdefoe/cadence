<?php

namespace ErnestDefoe\Cadence\Extend;

use ErnestDefoe\Cadence\Backfill;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\LifecycleInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;

/**
 * When Cadence is enabled, hand any rebuild its migrations asked for to the
 * queue — if there is one. Without one the scheduler, the admin page or
 * `php flarum cadence:rebuild-streaks` takes it; never this request.
 *
 * A lifecycle extender rather than a listener because an extension's own
 * listeners are not registered yet in the request that enables it.
 */
class StartBackfill implements ExtenderInterface, LifecycleInterface
{
    public function extend(Container $container, ?Extension $extension = null): void
    {
    }

    public function onEnable(Container $container, Extension $extension): void
    {
        $backfill = $container->make(Backfill::class);

        if ($backfill->state()) {
            $backfill->dispatch();
        }
    }

    public function onDisable(Container $container, Extension $extension): void
    {
    }
}
