<?php

namespace main;

use datastar\sse;
use std\time;
use std\http\Context;

function t(int $test)
{
    echo! $test;
}

function main(Context $c)
{
    sse\patch_elements($c, ```
        <div id="hal">I'm sorry, Dave. I'm afraid I can't do that.</div>
    ```);

    time\sleep(1 * time\SECOND);

    sse\patch_elements($c, ```<div id="hal">Waiting for an order...</div>```);
}

#![local]
function testing(DataThing $thing)
{
    echo 'hello';
}