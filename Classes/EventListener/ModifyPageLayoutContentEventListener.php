<?php

declare(strict_types=1);

namespace Kitzberger\CopyTranslatedContent\EventListener;

use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Event listener to add copy translated content button to page module
 */
final class ModifyPageLayoutContentEventListener
{
    public function __construct(
        private readonly PageRenderer $pageRenderer
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        // Add JavaScript module - it will dynamically add buttons next to translate buttons
        $this->pageRenderer->loadJavaScriptModule('@kitzberger/copy-translated-content/CopyTranslatedContent.js');

        // Add inline language labels
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:copy_translated_content/Resources/Private/Language/locallang.xlf', 'copy_translated_content');
    }
}
