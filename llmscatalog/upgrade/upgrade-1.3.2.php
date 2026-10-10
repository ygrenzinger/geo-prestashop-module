<?php

/** Homepage negotiation reuses an already registered hook and needs no migration. */
function upgrade_module_1_3_2($module): bool
{
    return true;
}
