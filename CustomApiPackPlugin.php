<?php
/**
 * @file plugins/generic/customApiPack/CustomApiPackPlugin.php
 *
 * Copyright (c) 2026 Muhammad Fauzi Dhuhuri
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CustomApiPackPlugin
 * @brief Back Issue Creation Automation + Journal Header Image Management via API.
 */

namespace APP\plugins\generic\customApiPack;

use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use APP\facades\Repo;

class CustomApiPackPlugin extends GenericPlugin {

    /** @var bool Flag to prevent duplicate script injection */
    protected bool $injected = false;

    /**
     * Register plugin with OJS system
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
     * Custom API endpoint registration
     */
    public function registerApiEndpoints($hookName, $args) {
        $endpoints =& $args[0];
        $handler = $args[1];

        if ($handler instanceof \APP\API\v1\issues\IssueHandler) {
            $endpoints['POST'][] = [
                'pattern' => $handler->getEndpointPattern() . '/create-back-issue',
                'handler' => [$this, 'handleCreateBackIssue'],
                'roles' => [\PKP\security\Role::ROLE_ID_MANAGER, \PKP\security\Role::ROLE_ID_SITE_ADMIN]
            ];
        }

        if ($handler instanceof \APP\API\v1\contexts\ContextHandler) {
            $endpoints['POST'][] = [
                'pattern' => $handler->getEndpointPattern() . '/custom-pack/update-header-image',
                'handler' => [$this, 'handleUpdateHeaderImage'],
                'roles' => [\PKP\security\Role::ROLE_ID_MANAGER, \PKP\security\Role::ROLE_ID_SITE_ADMIN]
            ];
        }
    }

    /**
     * API 1: Back Issue Creation & Cover Image Logic
     */
    public function handleCreateBackIssue($request, $response, $args) {
        try {
            $params = $request->getParsedBody();
            $context = $this->getRequest()->getContext();

            if (!$context) {
                return $response->withJson(['error' => 'Journal context not found.'], 404);
            }

            if (empty($params['title']) || empty($params['year'])) {
                return $response->withJson(['error' => 'Missing required fields: title and year are mandatory.'], 400);
            }

            $issue = Repo::issue()->newDataObject();
            $issue->setJournalId($context->getId());
            $issue->setPublished(1);
            $issue->setYear((int)$params['year']);
            $issue->setVolume(isset($params['volume']) ? (int)$params['volume'] : null);
            $issue->setNumber(isset($params['number']) ? $params['number'] : null);
            
            $primaryLocale = $context->getPrimaryLocale();
            $issue->setTitle($params['title'], $primaryLocale);
            
            if (!empty($params['description'])) {
                $issue->setDescription($params['description'], $primaryLocale);
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

        } catch (\Throwable $e) {
            return $response->withJson(['error' => 'Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()], 500);
        }
    }

    /**
     * API 2: Logic for receiving code and updating header image URL
     */
    public function handleUpdateHeaderImage($request, $response, $args) {
        try {
            $params = $request->getParsedBody();
            $context = $this->getRequest()->getContext();

            if (!$context) {
                return $response->withJson(['error' => 'Context not found.'], 404);
            }

            if (empty($params['header_image_url'])) {
                return $response->withJson(['error' => 'Missing header_image_url parameter.'], 400);
            }

            $imgTag = '<div class="custom-journal-header"><img src="' . htmlspecialchars($params['header_image_url'], ENT_QUOTES, 'UTF-8') . '" alt="Journal Header Image" style="width:100%; height:auto;"></div>';

            $this->updateSetting($context->getId(), 'customHeaderImgTag', $imgTag, 'string');

            return $response->withJson([
                'status' => 'success',
                'message' => 'Journal header image tag updated successfully.'
            ], 200);
        } catch (\Throwable $e) {
            return $response->withJson(['error' => 'Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()], 500);
        }
    }

    /**
     * FRONTEND HOOK: Inject IMG tag into website page structure via JavaScript
     */
    public function injectImageToHeader($hookName, $args) {
        if (!$this->injected) {
            $this->injected = true;
            $templateMgr =& $args[0];
            $request = $this->getRequest();
            $context = $request->getContext();

            if (!$context) {
                return false;
            }

            $headerImgTag = $this->getSetting($context->getId(), 'customHeaderImgTag');

            if (empty($headerImgTag)) {
                $defaultImageUrl = $request->getBaseUrl() . '/plugins/generic/customApiPack/default-header.png'; 
                $headerImgTag = '<div class="custom-journal-header"><img src="' . htmlspecialchars($defaultImageUrl, ENT_QUOTES, 'UTF-8') . '" alt="Default Journal Header" style="width:100%; height:auto;"></div>';
            }

            $safeHtml = json_encode($headerImgTag, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
            
            $script = "<script>\n" .
                      "document.addEventListener('DOMContentLoaded', function() {\n" .
                      "    var tempDiv = document.createElement('div');\n" .
                      "    tempDiv.innerHTML = " . $safeHtml . ";\n" .
                      "    document.body.insertBefore(tempDiv.firstChild, document.body.firstChild);\n" .
                      "});\n" .
                      "</script>";

            $templateMgr->addHeader('customApiPackHeaderImg', $script);
        }

        return false; 
    }

    public function getName() { return 'CustomApiPackPlugin'; }
    public function getDisplayName() { return __('plugins.generic.customApiPack.displayName'); }
    public function getDescription() { return __('plugins.generic.customApiPack.description'); }
}