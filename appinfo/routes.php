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
    // OCS API (Basic Auth + OCS-APIRequest header)
    'ocs' => [
        // Books
        ['name' => 'ocsApi#books', 'url' => '/books', 'verb' => 'GET'],
        ['name' => 'ocsApi#booksCreate', 'url' => '/books', 'verb' => 'POST'],
        ['name' => 'ocsApi#booksRename', 'url' => '/books/{id}', 'verb' => 'PUT'],
        ['name' => 'ocsApi#booksRename', 'url' => '/books/{id}', 'verb' => 'PATCH'],
        ['name' => 'ocsApi#booksRename', 'url' => '/books/{id}/rename', 'verb' => 'POST'],
        ['name' => 'ocsApi#booksInvite', 'url' => '/books/{id}/invite', 'verb' => 'POST'],
        ['name' => 'ocsApi#booksMembers', 'url' => '/books/{id}/members', 'verb' => 'GET'],
        ['name' => 'ocsApi#booksRemoveMember', 'url' => '/books/{id}/members/{uid}', 'verb' => 'DELETE'],
        ['name' => 'ocsApi#booksDelete', 'url' => '/books/{id}', 'verb' => 'DELETE'],
        // Expenses
        ['name' => 'ocsApi#expensesIndex', 'url' => '/books/{id}/expenses', 'verb' => 'GET'],
        ['name' => 'ocsApi#expensesCreate', 'url' => '/books/{id}/expenses', 'verb' => 'POST'],
        ['name' => 'ocsApi#expensesUpdate', 'url' => '/books/{id}/expenses/{eid}', 'verb' => 'PATCH'],
        ['name' => 'ocsApi#expensesDelete', 'url' => '/books/{id}/expenses/{eid}', 'verb' => 'DELETE'],
    ],
];
