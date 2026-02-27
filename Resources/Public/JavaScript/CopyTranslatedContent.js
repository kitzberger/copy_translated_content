import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

class CopyTranslatedContent {
    constructor() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.initialize());
        } else {
            this.initialize();
        }

        // Watch for dynamically added content
        this.observePageChanges();
    }

    initialize() {
        this.addCopyButtons();
    }

    observePageChanges() {
        const observer = new MutationObserver(() => this.addCopyButtons());
        observer.observe(document.body, { childList: true, subtree: true });
    }

    addCopyButtons() {
        const langLabelCells = document.querySelectorAll('td.t3-page-column.t3-page-lang-label.nowrap');

        langLabelCells.forEach(cell => {
            const btnGroup = cell.querySelector('.btn-group');
            if (!btnGroup || btnGroup.querySelector('[data-copy-translated-content]')) {
                return;
            }

            const languageId = this.getLanguageIdFromCell(cell);
            if (languageId === null) {
                return;
            }

            btnGroup.appendChild(this.createCopyButton(languageId));
        });
    }

    getLanguageIdFromCell(cell) {
        // The language UID is on the same column in the preceding row (td.t3-page-column-lang-name)
        const cellIndex = Array.from(cell.parentNode.children).indexOf(cell);
        const table = cell.closest('table');
        if (!table) {
            return null;
        }

        const langNameRow = table.querySelector('tr:first-child');
        if (!langNameRow) {
            return null;
        }

        const langNameCell = langNameRow.children[cellIndex];
        if (!langNameCell || !langNameCell.dataset.languageUid) {
            return null;
        }

        return parseInt(langNameCell.dataset.languageUid);
    }

    createCopyButton(languageId) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-default btn-sm';
        const title = languageId === 0 ? 'Copy content to another page' : 'Copy translated content to another page';
        button.title = title;
        button.setAttribute('data-copy-translated-content', '');
        button.setAttribute('data-language-id', languageId);

        button.innerHTML = `
            <span class="t3js-icon icon icon-size-small icon-state-default icon-actions-document-duplicates">
                <span class="icon-markup">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">
                        <path d="M13 3H8L6 1H3C2.4 1 2 1.4 2 2v9c0 .6.4 1 1 1h10c.6 0 1-.4 1-1V4c0-.6-.4-1-1-1zM3 2h2.6l2 2H13v7H3V2zm10 11H4v1h9c.6 0 1-.4 1-1V6h-1v7z"/>
                    </svg>
                </span>
            </span>
        `;

        button.addEventListener('click', (e) => {
            e.preventDefault();
            this.showModal(languageId);
        });

        return button;
    }

    async showModal(languageId) {
        const currentUrl = new URL(window.location.href);
        const pageId = parseInt(currentUrl.searchParams.get('id'));

        if (!pageId || languageId === undefined || languageId < 0) {
            Notification.error('Cannot copy content', 'Invalid page or language');
            return;
        }

        // Fetch content elements
        const elementsData = await this.fetchContentElements(pageId, languageId);
        if (!elementsData) {
            return;
        }

        // Create content as DOM element
        const contentDiv = document.createElement('div');
        const languageLabel = languageId === 0 ? 'default language' : `language ${languageId}`;
        contentDiv.innerHTML = `
            <div class="alert alert-info" role="alert">
                <p>This will copy selected content elements from <strong>${languageLabel}</strong> of the current page (PID: ${pageId}) to another page.</p>
                <p class="mb-0">Please select the content elements to copy and provide the target page ID below.</p>
            </div>
            ${this.renderContentElementCheckboxes(elementsData)}
            <div class="form-group">
                <label for="targetPid" class="form-label">
                    ${TYPO3.lang['copy_translated_content.modal.targetPid'] || 'Target Page ID'}
                </label>
                <input type="number" class="form-control" id="targetPid" min="1" required placeholder="Enter target page ID">
            </div>
        `;

        const modalTitle = languageId === 0
            ? (TYPO3.lang['copy_translated_content.modal.title_default'] || 'Copy Content')
            : (TYPO3.lang['copy_translated_content.modal.title'] || 'Copy Translated Content');

        const modal = Modal.advanced({
            title: modalTitle,
            content: contentDiv,
            severity: Modal.types.default,
            buttons: [
                {
                    text: TYPO3.lang['copy_translated_content.modal.cancel'] || 'Cancel',
                    active: true,
                    btnClass: 'btn-default',
                    trigger: () => {
                        modal.hideModal();
                    }
                },
                {
                    text: TYPO3.lang['copy_translated_content.modal.copy'] || 'Copy',
                    btnClass: 'btn-primary',
                    trigger: (e) => {
                        const modalElement = e.target.closest('typo3-backend-modal');
                        const targetInput = modalElement.querySelector('#targetPid');
                        const targetPid = targetInput ? parseInt(targetInput.value) : 0;
                        if (targetPid > 0) {
                            this.copyContent(pageId, targetPid, languageId, modal, modalElement);
                        }
                    }
                }
            ]
        });
    }

    async fetchContentElements(pageId, languageId) {
        try {
            const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.copy_translated_content_get_elements).post({
                pageId: pageId,
                languageId: languageId
            });
            const data = await response.resolve();
            return data.success ? data.contentElements : null;
        } catch (error) {
            Notification.error('Error', 'Failed to fetch content elements');
            return null;
        }
    }

    renderContentElementCheckboxes(elementsData) {
        let html = '<div class="form-group"><label>Content Elements:</label>';

        // Iterate through colPos groups
        for (const [colPos, elements] of Object.entries(elementsData)) {
            html += `<div class="mb-3"><strong>Column ${colPos}:</strong><div class="ms-3">`;

            elements.forEach(element => {
                const title = element.header || `[${element.CType}]`;
                html += `
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" checked
                               value="${element.uid}" id="element_${element.uid}"
                               name="elements[]">
                        <label class="form-check-label" for="element_${element.uid}">
                            ${this.escapeHtml(title)} <small class="text-muted">(${element.CType})</small>
                        </label>
                    </div>
                `;
            });

            html += '</div></div>';
        }

        html += '</div>';
        return html;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async copyContent(sourcePid, targetPid, languageId, modal, modalElement) {
        // Disable buttons and show loading state
        const copyButton = modalElement.querySelector('.modal-footer .btn-primary');
        const cancelButton = modalElement.querySelector('.modal-footer .btn-default');
        const originalCopyText = copyButton.textContent;

        copyButton.disabled = true;
        cancelButton.disabled = true;
        copyButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Copying...';

        try {
            // Get selected element UIDs from the modal element
            let checkboxes = modalElement.querySelectorAll('input[name="elements[]"]:checked');
            console.log('Found checkboxes in modalElement:', checkboxes.length);

            const elementUids = Array.from(checkboxes).map(cb => parseInt(cb.value));
            console.log('Element UIDs:', elementUids);

            if (elementUids.length === 0) {
                // Re-enable buttons
                copyButton.disabled = false;
                cancelButton.disabled = false;
                copyButton.textContent = originalCopyText;

                Notification.warning('No elements selected', 'Please select at least one content element to copy');
                return;
            }

            const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.copy_translated_content).post({
                sourcePid: sourcePid,
                targetPid: targetPid,
                languageId: languageId,
                elementUids: elementUids
            });

            const data = await response.resolve();

            if (data.success) {
                Notification.success(
                    TYPO3.lang['copy_translated_content.notification.success'] || 'Success',
                    data.message
                );
                modal.hideModal();
                // Reload the page module
                if (window.location.reload) {
                    setTimeout(() => window.location.reload(), 1000);
                }
            } else {
                // Re-enable buttons on error
                copyButton.disabled = false;
                cancelButton.disabled = false;
                copyButton.textContent = originalCopyText;

                Notification.error(
                    TYPO3.lang['copy_translated_content.notification.error'] || 'Error',
                    data.message
                );
            }
        } catch (error) {
            // Re-enable buttons on error
            copyButton.disabled = false;
            cancelButton.disabled = false;
            copyButton.textContent = originalCopyText;

            Notification.error(
                TYPO3.lang['copy_translated_content.notification.error'] || 'Error',
                error.message || 'An error occurred while copying content'
            );
        }
    }
}

export default new CopyTranslatedContent();
