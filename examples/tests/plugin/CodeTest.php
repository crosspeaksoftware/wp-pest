<?php

test('WordPress loads the plugin test environment', function () {
    expect(wp_pest()->pluginPath())->toBeDirectory();
});
