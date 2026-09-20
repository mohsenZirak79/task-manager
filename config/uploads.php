<?php

return [
    'files' => [
        'disk' => 'public',
        'directory' => 'task-attachments',
        'max_kilobytes' => 10 * 1024,
        'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp'],
    ],
];
