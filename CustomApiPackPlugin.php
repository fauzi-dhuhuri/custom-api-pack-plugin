<?php
/**
 * @file plugins/generic/customApiPack/CustomApiPackPlugin.php
 *
 * Copyright (c) 2026 Muhammad Fauzi Dhuhuri
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CustomApiPackPlugin
 * @brief Otomasi Pembuatan Back Issue + Manajemen Gambar Header Jurnal via API.
 */

namespace APP\plugins\generic\customApiPack;

use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\facades\Repo;

class CustomApiPackPlugin extends GenericPlugin {

    /**
     * Mendaftarkan plugin ke dalam sistem OJS
     */
    public function register($category, $path, $mainContextId = null) {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled()) {
                Hook::add('APIHandler::endpoints', [$this, 'registerApiEndpoints']);
                Hook::add('TemplateManager::display', [$this, 'injectImageToHeader']);
            }
            return true;
        }
        return false;
    }

    /**
     * PENDAFTARAN ENDPOINT API KUSTOM
     */
    public function registerApiEndpoints($hookName, $args) {
        $endpoints =& $args[0];

        $endpoints['POST'][] = [
            'pattern' => '/issues/create-back-issue',
            'handler' => [$this, 'handleCreateBackIssue'],
            'roles' => [ROLE_ID_MANAGER, ROLE_ID_SITE_ADMIN]
        ];

        $endpoints['POST'][] = [
            'pattern' => '/plugins/custom-pack/update-header-image',
            'handler' => [$this, 'handleUpdateHeaderImage'],
            'roles' => [ROLE_ID_MANAGER, ROLE_ID_SITE_ADMIN]
        ];
    }

    /**
     * API 1: LOGIKA PEMBUATAN BACK ISSUE & COVER IMAGE
     */
    public function handleCreateBackIssue($request, $response, $args) {
        $params = $request->getParsedBody();
        $context = $request->getContext();

        if (!$context) {
            return $response->withJson(['error' => 'Journal context not found.'], 404);
        }

        if (empty($params['title']) || empty($params['year'])) {
            return $response->withJson(['error' => 'Missing required fields: title and year are mandatory.'], 400);
        }

        try {
            $issue = Repo::issue()->newDataObject();
            $issue->setJournalId($context->getId());
            $issue->setPublished(1);
            $issue->setYear((int)$params['year']);
            $issue->setVolume(isset($params['volume']) ? (int)$params['volume'] : null);
            $issue->setNumber(isset($params['number']) ? $params['number'] : null);
            
            $primaryLocale = $context->getPrimaryLocale();
            $issue->setTitle([$primaryLocale => $params['title']], $primaryLocale);
            
            if (!empty($params['description'])) {
                $issue->setDescription([$primaryLocale => $params['description']], $primaryLocale);
            }

            if (!empty($params['coverTemporaryFileId'])) {
                $issue->setCoverImage([
                    'temporaryFileId' => (int)$params['coverTemporaryFileId'],
                    'altText' => 'Cover ' . $params['title']
                ], $primaryLocale);
            }

            $issueId = Repo::issue()->add($issue);

            return $response->withJson([
                'status' => 'success',
                'message' => 'Back Issue and Cover created successfully.',
                'data' => [
                    'issue_id' => $issueId,
                    'title' => $params['title'],
                    'published_status' => 'Back Issue'
                ]
            ], 201);

        } catch (\Exception $e) {
            return $response->withJson(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * API 2: LOGIKA MENERIMA KODE DAN MENGUBAH URL GAMBAR HEADER
     */
    public function handleUpdateHeaderImage($request, $response, $args) {
        $params = $request->getParsedBody();
        $context = $request->getContext();

        if (!$context) {
            return $response->withJson(['error' => 'Context not found.'], 404);
        }

        if (empty($params['header_image_url'])) {
            return $response->withJson(['error' => 'Missing header_image_url parameter.'], 400);
        }

        $imgTag = '<div class="custom-journal-header"><img src="' . esc_url($params['header_image_url']) . '" alt="Journal Header Image" style="width:100%; height:auto;"></div>';

        $this->updateSetting($context->getId(), 'customHeaderImgTag', $imgTag, 'string');

        return $response->withJson([
            'status' => 'success',
            'message' => 'Journal header image tag updated successfully.'
        ], 200);
    }

    /**
     * FRONTEND HOOK: MENYUNTIKKAN TAG IMG KE STRUKTUR HALAMAN WEBSITE
     */
    public function injectImageToHeader($hookName, $args) {
        $templateMgr =& $args[0];
        $request = $this->getRequest();
        $context = $request->getContext();

        if (!$context) {
            return false;
        }

        $headerImgTag = $this->getSetting($context->getId(), 'customHeaderImgTag');

        if (empty($headerImgTag)) {
            $defaultImageUrl = $request->getBaseUrl() . '/plugins/generic/customApiPack/default-header.png'; 
            
            $headerImgTag = '<div class="custom-journal-header"><img src="' . esc_url($defaultImageUrl) . '" alt="Default Journal Header" style="width:100%; height:auto;"></div>';
        }

        $existingHeadData = $templateMgr->getTemplateVars('additionalHeadData') ?? '';
        $templateMgr->assign('additionalHeadData', $existingHeadData . $headerImgTag);

        return false; 
    }

    public function getName() { return 'CustomApiPackPlugin'; }
    public function getDisplayName() { return 'Custom API & Header Pack'; }
    public function getDescription() { return 'Otomasi pembuatan Back Issue beserta gambar cover dan pembaruan gambar header jurnal via API.'; }
}