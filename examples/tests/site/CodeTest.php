<?php

test('WordPress is available', function () {
    expect(get_bloginfo('name'))->not->toBe('');
});
