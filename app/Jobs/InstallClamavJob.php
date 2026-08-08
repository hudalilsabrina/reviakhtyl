<?php

namespace App\Jobs;

use App\Contracts\Repository\SettingsRepositoryInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class InstallClamavJob extends Job implements ShouldQueue
{
    use Dispatchable;

    public int $timeout = 1200;

    public int $tries = 1;

    /**
     * Install clamav (clamscan + freshclam) on the Panel server via apt-get.
     *
     * The queue worker (www-data) must be allowed passwordless sudo for the
     * exact commands below. Add a file in /etc/sudoers.d/ like:
     *
     *     www-data ALL=(root) NOPASSWD: /usr/bin/apt-get update, /usr/bin/apt-get install -y clamav, /usr/bin/apt-get install -y clamav clamav-freshclam, /usr/bin/freshclam
     *
     * Progress is reported through the panel:file_scan:clamav_status and
     * panel:file_scan:clamav_message settings keys.
     */
    public function handle(SettingsRepositoryInterface $settings): void
    {
        $this->setStatus($settings, 'installing', 'Installation started in the background.');

        $run = function (array $command, int $timeout): array {
            $process = new Process($command);
            $process->setTimeout($timeout);

            $process->run();

            return [
                $process->isSuccessful(),
                trim($process->getOutput().$process->getErrorOutput()),
            ];
        };

        [$ok, $output] = $run(['sudo', '-n', 'apt-get', 'update'], 300);
        if (! $ok) {
            if (str_contains($output, 'password is required')) {
                $message = 'Passwordless sudo is not configured for the queue worker. Run as root: '
                    .'echo "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get update, /usr/bin/apt-get install -y clamav, /usr/bin/apt-get install -y clamav clamav-freshclam, /usr/bin/freshclam" > /etc/sudoers.d/reviactyl && chmod 440 /etc/sudoers.d/reviactyl';
                $this->setStatus($settings, 'failed', $message);

                return;
            }

            $this->setStatus($settings, 'failed', 'apt-get update failed: '.$this->tail($output));
            Log::error('clamav install: apt-get update failed', ['output' => $output]);

            return;
        }

        [$ok, $output] = $run(['sudo', '-n', 'apt-get', 'install', '-y', 'clamav', 'clamav-freshclam'], 600);
        if (! $ok) {
            $this->setStatus($settings, 'failed', 'apt-get install failed: '.$this->tail($output));
            Log::error('clamav install: apt-get install failed', ['output' => $output]);

            return;
        }

        [$ok, $output] = $run(['/usr/bin/clamscan', '--version'], 30);
        if (! $ok) {
            $this->setStatus($settings, 'failed', 'clamscan was not found after installation: '.$this->tail($output));
            Log::error('clamav install: clamscan missing after install', ['output' => $output]);

            return;
        }

        $this->setStatus($settings, 'installed', 'Installed: '.$this->tail($output, 500));

        // Download virus definitions once so scanning works immediately. The
        // freshclam daemon keeps them updated afterwards; failures here are
        // non-fatal because the daemon retries on its own.
        [$ok, $output] = $run(['sudo', '-n', 'freshclam'], 300);
        if (! $ok) {
            Log::warning('clamav install: initial freshclam run failed (daemon will retry)', ['output' => $output]);
        }
    }

    private function tail(string $output, int $length = 2000): string
    {
        if (strlen($output) <= $length) {
            return $output;
        }

        return '…'.substr($output, -$length);
    }

    private function setStatus(SettingsRepositoryInterface $settings, string $status, string $message): void
    {
        $settings->set('settings::panel:file_scan:clamav_status', $status);
        $settings->set('settings::panel:file_scan:clamav_message', $message);
    }
}
