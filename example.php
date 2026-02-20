<?php

function includeUtilities() {
    if (file_exists(__DIR__ . '/utils/class.php')) {
        require_once __DIR__ . '/utils/class.php';
    }
}

includeUtilities();

Modules::enable();

Route::lazyPage('/about/:cat/:id', function($id, $cat, $options) {
    if (!User::isAuthorized()) {
        redirect('/login');
    }

    $t = template('deliveries');

    Route::setMeta([
        'title' => "About Us",
        'h1' => 'About Us 123',
        'description' => 'About Us 123'
    ]);

    $fields = [
        'data' => '123'
    ];

    return view($t['page'], $fields);
});

Entity::register('metal', [
    'NAME' => 'name',
    'S01' => ['sex', 'birthday', 'phone'],
    'S02' => ['phrase', 'registerDate'],
    [
        'age' => function () {
            return date('Y') - $this->birthday;
        },
        'gender' => function () {
            return $this->age['sex'] === 'male' ? 'муж' : 'жен';
        }
    ]
], [
    'table' => PREFIX . "ROWS",
]);

MetalEntity::create([
    'name' => 'Железо',
    'sex' => 'male',
    'birthday' => '2000-01-01',
    'phone' => '123456789',
    'phrase' => 'Железо!!',
    'registerDate' => '2025-01-01'
]);

$jelezo = MetalEntity::getOne(1); // by id

// OR

$jelezo = MetalEntity::getOne([
    'or',
    ['name', '=', 'Железо'],
    ['id', '<', 10]
]);

MetalEntity::update(1 /* id */, [
    'name' => 'Железо',
]);

// OR

MetalEntity::updateAll([
    ['id', '<', 10]
], [
    'name' => 'Железо',
    'sex' => function () {
        return $this->sex === 'male' ? 'female' : 'male';
    }
]);

$allJelezo = MetalEntity::get([
    'or',
    ['name', '=', 'Железо'],
    ['id', '<', 10]
]); // [ [...], ... ]

/*
$jelezo === ['id' => 1, 'name' => 'Железо', 'sex' => 'male', 'birthday' => '2000-01-01', 'phone' => '123456789', 'phrase' => 'Железо!!', 'registerDate' => '2025-01-01', 'age' => 25, 'gender' => 'муж', 'createdAt' => '2020-01-01 00:00:00']
*/

MetalEntity::deleteById(1);