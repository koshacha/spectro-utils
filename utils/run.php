<?php

if (!defined('IMGPATH')) exit();

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

if (file_exists(IMGPATH . 'config.php')) {
    require_once IMGPATH . 'config.php';
}

function includeUtilities() {
    if (file_exists(__DIR__ . '/class.php')) {
        require_once __DIR__ . '/class.php';
    }
}

includeUtilities();

Modules::enable();

function __shared() {
    Modules::autorun();

    if (file_exists(IMGPATH . 'shared.php')) {
        require_once IMGPATH . 'shared.php';
    }
    foreach (glob(IMGPATH . 'shared.*.php') as $filename) {
        require_once $filename;
    }
}

function ___program() {
    __shared();

    if (file_exists(IMGPATH . 'app.php')) {
        require_once IMGPATH . 'app.php';
    }
    foreach (glob(IMGPATH . 'app.*.php') as $filename) {
        require_once $filename;
    }

    Route::exec();
}

function ___actions() {
    __shared();

    if (file_exists(IMGPATH . 'handlers.php')) {
        require_once IMGPATH . 'handlers.php';
    }
    foreach (glob(IMGPATH . 'handlers.*.php') as $filename) {
        require_once $filename;
    }

    Action::exec();
}

