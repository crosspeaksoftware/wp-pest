<?php

it('loads the home page', function () {
    visit('/')
        ->assertSourceHas('<body')
        ->assertNoJavaScriptErrors();
});
