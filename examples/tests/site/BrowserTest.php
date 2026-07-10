<?php

it('loads the home page', function () {
    visit(wp_pest()->url())
        ->assertSourceHas('<body')
        ->assertNoJavaScriptErrors();
});
