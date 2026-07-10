<?php

it('loads the sandbox home page', function () {
    visit(wp_pest()->url())
        ->assertSourceHas('<body')
        ->assertNoJavaScriptErrors();
});
