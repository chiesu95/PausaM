<?php

test('guests are redirected to login from home', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
});
