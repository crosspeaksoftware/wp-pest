<?php

it('loads the sandbox home page', function () {
    visit('/')
        ->assertSourceHas('<body')
        ->assertNoJavaScriptErrors();
});
