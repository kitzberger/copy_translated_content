<?php

declare(strict_types=1);

namespace Kitzberger\CopyTranslatedContent\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
class CopyContentController
{
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
        $elementUids = $parsedBody['elementUids'] ?? $queryParams['elementUids'] ?? [];

        if ($sourcePid <= 0 || $targetPid <= 0 || $languageId < 0 || $targetLanguageUid < 0) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid parameters',
            ], 400);
        }

        try {
            $copiedCount = $this->copyTranslatedContent($sourcePid, $targetPid, $languageId, $targetLanguageUid, $elementUids);

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

        $contentElements = $queryBuilder
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
            ->addOrderBy('sorting')
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
    protected function copyTranslatedContent(int $sourcePid, int $targetPid, int $languageId, int $targetLanguageUid, array $elementUids = []): int
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

        $contentElements = $queryBuilder
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        if (empty($contentElements)) {
            return 0;
        }

        // Prepare DataHandler
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [], $backendUser);

        $copiedCount = 0;
        foreach ($contentElements as $element) {
            // Copy the record using DataHandler
            $newUid = $dataHandler->copyRecord(
                'tt_content',
                $element['uid'],
                $targetPid,
                true,
                [],
                '',
                $languageId,
                true // ignoreLocalization = true, don't copy child localizations
            );

            if ($newUid) {
                // Update sys_language_uid if target language differs from source
                if ($targetLanguageUid !== $languageId) {
                    $this->connectionPool->getConnectionForTable('tt_content')->update(
                        'tt_content',
                        ['sys_language_uid' => $targetLanguageUid],
                        ['uid' => $newUid]
                    );
                }
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
