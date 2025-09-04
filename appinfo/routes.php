<?php

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        // Books
        ['name' => 'book#index', 'url' => '/books', 'verb' => 'GET'],
        ['name' => 'book#create', 'url' => '/books', 'verb' => 'POST'],
        ['name' => 'book#rename', 'url' => '/books/{id}', 'verb' => 'PUT'],
        ['name' => 'book#rename', 'url' => '/books/{id}', 'verb' => 'PATCH'],
        ['name' => 'book#rename', 'url' => '/books/{id}/rename', 'verb' => 'POST'],
        ['name' => 'book#invite', 'url' => '/books/{id}/invite', 'verb' => 'POST'],
        ['name' => 'book#members', 'url' => '/books/{id}/members', 'verb' => 'GET'],
        ['name' => 'book#removeMember', 'url' => '/books/{id}/members/{uid}', 'verb' => 'DELETE'],
        // Expenses
        ['name' => 'expense#index', 'url' => '/books/{id}/expenses', 'verb' => 'GET'],
        ['name' => 'expense#create', 'url' => '/books/{id}/expenses', 'verb' => 'POST'],
        ['name' => 'expense#update', 'url' => '/books/{id}/expenses/{eid}', 'verb' => 'PATCH'],
        ['name' => 'expense#delete', 'url' => '/books/{id}/expenses/{eid}', 'verb' => 'DELETE'],
        ['name' => 'book#delete', 'url' => '/books/{id}', 'verb' => 'DELETE'],
    ],
];
