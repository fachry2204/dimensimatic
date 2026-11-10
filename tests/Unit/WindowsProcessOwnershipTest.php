<?php

namespace Tests\Unit;

use App\Services\Automation\LocalSystemManager;
use Tests\TestCase;

class WindowsProcessOwnershipTest extends TestCase
{
    public function test_same_queue_and_relative_worker_path_do_not_claim_other_installations(): void
    {
        $method = new \ReflectionMethod(LocalSystemManager::class, 'ownsWindowsProcess');
        $manager = app(LocalSystemManager::class);
        foreach ([
            "CommandLine=php.exe artisan queue:work database --queue=release-automation\nExecutablePath=C:\\Programs\\SoundMatic\\runtime\\php\\php.exe",
            "CommandLine=node.exe --env-file=.env dist/server.js\nExecutablePath=C:\\Programs\\SoundMatic\\runtime\\node\\node.exe",
            'CommandLine="'.base_path('bootstrap/app.php').'.other" --queue-runner worker.pid',
        ] as $record) {
            $this->assertFalse($method->invoke($manager, $record));
        }
        $this->assertTrue($method->invoke($manager, 'CommandLine=php.exe artisan queue:work --queue=release-automation'."\nExecutablePath=".base_path('runtime/php/php.exe')));
        $this->assertTrue($method->invoke($manager, 'CommandLine=php.exe "'.base_path('bootstrap/app.php').'" --queue-runner worker.pid'));
    }
}
