<?php

namespace Imanghafoori\LaravelMicroscope\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\View;
use Imanghafoori\LaravelMicroscope\BladeFiles;
use Imanghafoori\LaravelMicroscope\Checks\CheckView;
use Imanghafoori\LaravelMicroscope\Checks\CheckViewFilesExistence;
use Imanghafoori\LaravelMicroscope\ErrorReporters\ErrorPrinter;
use Imanghafoori\LaravelMicroscope\ErrorTypes\BladeFile;
use Imanghafoori\LaravelMicroscope\ForPsr4LoadedClasses;
use Imanghafoori\LaravelMicroscope\SpyClasses\RoutePaths;
use Imanghafoori\TokenAnalyzer\FunctionCall;

class CheckViews extends Command
{
    public static $checkedCallsNum = 0;

    public static $skippedCallsNum = 0;

    protected $signature = 'check:views {--detailed : Show files being checked} {--f|file=} {--d|folder=}';

    protected $description = 'Checks the validity of blade files';

    public function handle(ErrorPrinter $errorPrinter)
    {
        event('microscope.start.command');
        $this->info('Checking views...');

        $fileName = ltrim($this->option('file'), '=');
        $folder = ltrim($this->option('folder'), '=');

        $errorPrinter->printer = $this->output;
        $this->checkRoutePaths($fileName, $folder);
        ForPsr4LoadedClasses::check([CheckView::class], [], $fileName, $folder);
        $this->checkBladeFiles();

        $this->getOutput()->writeln(' - '.self::$checkedCallsNum.' view references were checked to exist. ('.self::$skippedCallsNum.' skipped)');
        event('microscope.finished.checks', [$this]);

        return $errorPrinter->hasErrors() ? 1 : 0;
    }

    private function checkForViewMake($absPath, $staticCalls)
    {
        $tokens = \token_get_all(\file_get_contents($absPath));

        foreach ($tokens as $i => $token) {
            if (FunctionCall::isGlobalCall('view', $tokens, $i)) {
                $this->checkViewParams($absPath, $tokens, $i, 0);
                continue;
            }

            foreach ($staticCalls as $class => $method) {
                if (FunctionCall::isStaticCall($method[0], $tokens, $i, $class)) {
                    $this->checkViewParams($absPath, $tokens, $i, $method[1]);
                }
            }
        }
    }

    private function checkViewParams($absPath, &$tokens, $i, $index)
    {
        $params = FunctionCall::readParameters($tokens, $i);

        // it should be a hard-coded string which is not concatinated like this: 'hi'. $there
        $paramTokens = $params[$index] ?? ['_', '_', '_'];

        if (FunctionCall::isSolidString($paramTokens)) {
            self::$checkedCallsNum++;
            $viewName = \trim($paramTokens[0][1], '\'\"');

            $viewName && ! View::exists($viewName) && BladeFile::warn($absPath, $paramTokens[0][2], $viewName);
        } else {
            self::$skippedCallsNum++;
        }
    }

    private function checkRoutePaths($fileName, $folder)
    {
        foreach (RoutePaths::get($fileName, $folder) as $filePath) {
            $this->checkForViewMake($filePath, [
                'View' => ['make', 0],
                'Route' => ['view', 1],
            ]);
        }
    }

    private function checkBladeFiles()
    {
        BladeFiles::check([CheckViewFilesExistence::class]);
    }
}
