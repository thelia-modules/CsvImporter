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
 * Date: 14/11/2024 09:27
 */
namespace CsvImporter\Hook;

use BestSellers\BestSellers;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Tools\URL;

/**
 *
 */
class HookManager extends BaseHook
{
    protected const MAX_TRACE_SIZE_IN_BYTES = 40000;

    public function onModuleConfiguration(HookRenderEvent $event)
    {
        $logFilePath =  THELIA_LOG_DIR . 'catalog-import-log.txt';

        $traces = @file_get_contents($logFilePath);

        // Limiter la taille des traces à 1MO
        if (strlen($traces) > self::MAX_TRACE_SIZE_IN_BYTES) {
            $traces = substr($traces, strlen($traces) - self::MAX_TRACE_SIZE_IN_BYTES);

            // Cut a first line break;
            if (false !== $lineBreakPos = strpos($traces, "\n")) {
                $traces = substr($traces, $lineBreakPos+1);
            }
        }

        $event->add(
            $this->render(
                'module-configuration.html',
                [ 'trace_content' => nl2br($traces)  ]
            )
        );
    }

    public function onMainTopMenuTools(HookRenderBlockEvent $event)
    {
        $event->add(
            [
                'id' => 'csvimporter_menu',
                'class' => '',
                'url' => URL::getInstance()->absoluteUrl('/admin/module/CsvImporter'),
                'title' =>"Import catalogue CSV"
            ]
        );
    }
}
