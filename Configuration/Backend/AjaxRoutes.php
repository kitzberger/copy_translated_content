<?php

use Kitzberger\CopyTranslatedContent\Controller\CopyContentController;

return [
    'copy_translated_content_get_elements' => [
        'path' => '/copy-translated-content/get-elements',
        'methods' => ['POST', 'GET'],
        'target' => CopyContentController::class . '::getContentElementsAction',
    ],
    'copy_translated_content' => [
        'path' => '/copy-translated-content/copy',
        'methods' => ['POST'],
        'target' => CopyContentController::class . '::copyAction',
    ],
];
