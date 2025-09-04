<?php

declare(strict_types=1);

namespace OCA\FamilyBudget\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\IRequest;

class PageController extends Controller {
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct($appName, $request);
    }

    /**
     * Render the main app page
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse
    {
        $response = new TemplateResponse('familybudget', 'main', []);
        // Restriktive CSP für die App-Ansicht
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedScriptDomain('self');
        $csp->addAllowedStyleDomain('self');
        $csp->addAllowedImageDomain('self');
        $csp->addAllowedImageDomain('data:');
        $csp->addAllowedConnectDomain('self');
        $csp->addAllowedFontDomain('self');
        $response->setContentSecurityPolicy($csp);
        return $response;
    }
}
