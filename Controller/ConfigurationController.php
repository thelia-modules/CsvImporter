<?php
/*************************************************************************************/
/*      Copyright (c) Open Studio                                                    */
/*      web : https://open.studio                                                    */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE      */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

/**
 * Created by Franck Allimant, OpenStudio <fallimant@openstudio.fr>
 * Date: 14/11/2024 09:23
 */
namespace CsvImporter\Controller;

use CsvImporter\Service\CsvProductImporterService;
use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Log\Destination\TlogDestinationFile;
use Thelia\Log\Destination\TlogDestinationRotatingFile;
use Thelia\Log\Tlog;
use Thelia\Tools\URL;

/**
 * @Route("/admin/module/csvimporter")
 */
class ConfigurationController extends BaseAdminController
{
    protected string $logFile = THELIA_LOG_DIR . 'catalog-import-log.txt';

    /**
     * Import manuel du catalogue
     *
     * @Route("/import", methods="GET")
     */
    public function import(CsvProductImporterService $csvProductImporterService): Response
    {
        @unlink($this->logFile);

        Tlog::getInstance()
            ->setLevel(Tlog::INFO)
            ->setDestinations(TlogDestinationFile::class)
            ->setConfig(TlogDestinationFile::class, TlogDestinationFile::VAR_PATH_FILE, $this->logFile)

            ->setFiles('*')
            ->setPrefix('[#LEVEL] #DATE #HOUR:');

        // Pas de log des requetes SQL
        Propel::getConnection('TheliaMain')->useDebug(false);

        $catalogDir = THELIA_LOCAL_DIR . 'Catalogue';

        if (! is_dir($catalogDir)) {
            return $this->generateRedirect(
                URL::getInstance()?->absoluteUrl(
                    '/admin/module/CsvImporter',
                    [ 'error' => 'Répertoire ' . $catalogDir . ' non trouvé.' ]
                )
            );
        }

        $csvProductImporterService->importFromDirectory($catalogDir);

        return $this->generateRedirect(URL::getInstance()?->absoluteUrl('/admin/module/CsvImporter', [ 'done' => 1 ]));
    }

    /**
     * Import manuel du catalogue
     *
     * @Route("/log", methods="GET")
     */
    public function getLogFile(): Response
    {
        return new Response(
            @file_get_contents($this->logFile),
            200,
            array(
                'Content-type' => "text/plain",
                'Content-Disposition' => 'Attachment;filename=csv-import-log.txt'
            )
        );
    }
}
