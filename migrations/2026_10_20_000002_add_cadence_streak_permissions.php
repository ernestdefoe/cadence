<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'cadence.viewStreaks' => Group::GUEST_ID,
    'cadence.earnStreaks' => Group::MEMBER_ID,
]);
