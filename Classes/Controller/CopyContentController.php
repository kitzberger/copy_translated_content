<?php

declare(strict_types=1);

namespace Kitzberger\CopyTranslatedContent\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Controller for copying translated content elements
 */
class CopyContentController implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {}

    /**
     * Get content elements for a page and language
     */
    public function getContentElementsAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $queryParams = $request->getQueryParams();

        $pageId = (int)($parsedBody['pageId'] ?? $queryParams['pageId'] ?? 0);
        $languageId = (int)($parsedBody['languageId'] ?? $queryParams['languageId'] ?? 0);

        if ($pageId <= 0 || $languageId < 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid parameters',
            ], 400);
        }

        try {
            $contentElements = $this->getContentElements($pageId, $languageId);

            return new JsonResponse([
                'success' => true,
                'contentElements' => $contentElements,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Copy translated content elements from source page to target page
     */
    public function copyAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $queryParams = $request->getQueryParams();

        $sourcePid = (int)($parsedBody['sourcePid'] ?? $queryParams['sourcePid'] ?? 0);
        $targetPid = (int)($parsedBody['targetPid'] ?? $queryParams['targetPid'] ?? 0);
        $languageId = (int)($parsedBody['languageId'] ?? $queryParams['languageId'] ?? 0);
        $targetLanguageUid = (int)($parsedBody['targetLanguageUid'] ?? $queryParams['targetLanguageUid'] ?? $languageId);
        $neverHideAtCopy = (bool)($parsedBody['neverHideAtCopy'] ?? $queryParams['neverHideAtCopy'] ?? true);
        $elementUids = $parsedBody['elementUids'] ?? $queryParams['elementUids'] ?? [];

        $this->logger->debug('copyAction called', [
            'sourcePid' => $sourcePid,
            'targetPid' => $targetPid,
            'languageId' => $languageId,
            'targetLanguageUid' => $targetLanguageUid,
            'neverHideAtCopy' => $neverHideAtCopy,
            'elementUids' => $elementUids,
        ]);

        if ($sourcePid <= 0 || $targetPid <= 0 || $languageId < 0 || $targetLanguageUid < 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid parameters',
            ], 400);
        }

        try {
            $copiedCount = $this->copyTranslatedContent($sourcePid, $targetPid, $languageId, $targetLanguageUid, $neverHideAtCopy, $elementUids);

            return new JsonResponse([
                'success' => true,
                'message' => sprintf('Successfully copied %d content element(s) to page %d', $copiedCount, $targetPid),
                'count' => $copiedCount,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get content elements for a page and language
     */
    protected function getContentElements(int $pageId, int $languageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getBackendUser()->workspace));

        $queryBuilder
            ->select('uid', 'header', 'CType', 'colPos')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            )
            ->orderBy('colPos')
            ->addOrderBy('sorting');

        // Exclude container children if EXT:container is loaded
        if (isset($GLOBALS['TCA']['tt_content']['columns']['tx_container_parent'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'tx_container_parent',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            );
        }

        $contentElements = $queryBuilder
            ->executeQuery()
            ->fetchAllAssociative();

        // Group by colPos
        $grouped = [];
        foreach ($contentElements as $element) {
            $colPos = $element['colPos'];
            if (!isset($grouped[$colPos])) {
                $grouped[$colPos] = [];
            }
            $grouped[$colPos][] = $element;
        }

        return $grouped;
    }

    /**
     * Copy translated content elements
     */
    protected function copyTranslatedContent(int $sourcePid, int $targetPid, int $languageId, int $targetLanguageUid, bool $neverHideAtCopy, array $elementUids = []): int
    {
        $backendUser = $this->getBackendUser();

        // Check permissions
        if (!$backendUser->doesUserHaveAccess(BackendUtility::getRecord('pages', $sourcePid), 1)) {
            throw new \RuntimeException('No read access to source page');
        }
        if (!$backendUser->doesUserHaveAccess(BackendUtility::getRecord('pages', $targetPid), 16)) {
            throw new \RuntimeException('No edit access to target page');
        }

        // Get all content elements from source page in the specified language
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $backendUser->workspace));

        $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($sourcePid, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            );

        // If specific element UIDs are provided, filter by them
        if (!empty($elementUids)) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($elementUids, Connection::PARAM_INT_ARRAY)
                )
            );
        }

        // Exclude container children if EXT:container is loaded
        if (isset($GLOBALS['TCA']['tt_content']['columns']['tx_container_parent'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'tx_container_parent',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            );
        }

        $contentElements = $queryBuilder
            ->orderBy('sorting', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $this->logger->debug('Query returned content elements', [
            'count' => count($contentElements),
            'uids' => array_column($contentElements, 'uid'),
        ]);

        if (empty($contentElements)) {
            return 0;
        }

        $copiedCount = 0;
        foreach ($contentElements as $element) {
            $updateFields = [
            ];
            if ($targetLanguageUid !== $languageId) {
                $updateFields['sys_language_uid'] = $targetLanguageUid;
            }

            $cmd = [
                'tt_content' => [
                    $element['uid'] => [
                        'copy' => [
                            'action' => 'paste',
                            'target' => $targetPid,
                            'update' => $updateFields,
                        ],
                    ],
                ],
            ];
            $this->logger->debug('Copy command', $cmd);

            // Fresh DataHandler per copy to avoid internal state issues
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->neverHideAtCopy = $neverHideAtCopy;
            $dataHandler->start([], $cmd, $backendUser);
            $dataHandler->process_cmdmap();

            if ($dataHandler->errorLog !== []) {
                $this->logger->error('DataHandler errors during copy', [
                    'sourceUid' => $element['uid'],
                    'errors' => $dataHandler->errorLog,
                ]);
            }

            $newUid = $dataHandler->copyMappingArray['tt_content'][$element['uid']] ?? null;

            $this->logger->debug('Copy result', [
                'sourceUid' => $element['uid'],
                'newUid' => $newUid,
            ]);

            if ($newUid) {
                $copiedCount++;
            }
        }

        return $copiedCount;
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
