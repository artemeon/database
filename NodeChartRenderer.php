<?php
/*"******************************************************************************************************
 *   (c) ARTEMEON Management Partner GmbH
 *       Published under the GNU LGPL v2.1
 ********************************************************************************************************/

declare(strict_types=1);

namespace Kajona\System\System;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Chart renderer using node for backend converting of charts into images for later use on pdf creation
 *
 * @package module_system
 * @author bernhard.grabietz@artemeon.de
 * @since 7.1
 */
class NodeChartRenderer implements ChartRendererInterface
{
    /**
     * render given graph into image using node
     * @param GraphInterface $chart
     * @return string
     */
    public function render(GraphInterface $chart): string
    {
        $process = new Process('/usr/local/bin/node ' . _realpath_ . 'bin/node/renderGraphImage.js');
        $process->setInput(json_encode($chart));
        $process->run();

        $process->getExitCode();
        $filename = $process->getOutput();

        return $filename;
    }
}
