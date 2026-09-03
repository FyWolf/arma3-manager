<?php

namespace FyWolf\Arma3Manager\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use FyWolf\Arma3Manager\Support\ArmaConfigFile;
use Throwable;

/**
 * Read and write Arma config files through Wings.
 *
 * Thin on purpose. All the difficulty is in `ArmaConfigFile`, which is unit
 * tested without a panel; this class only knows how to reach the daemon and
 * how to fail politely when it cannot.
 */
class ConfigService
{
    public function __construct(private DaemonFileRepository $repository) {}

    /**
     * Null when the file does not exist yet.
     *
     * That is a normal state, not an error: Arma writes neither server.cfg nor
     * basic.cfg by itself, and on a freshly provisioned server the egg may not
     * have created them either. The page renders "not created yet" and offers
     * to write a default rather than showing an exception.
     */
    public function read(Server $server, string $path): ?ArmaConfigFile
    {
        try {
            // Capped: a config file is kilobytes, and an unbounded read of
            // something that turned out to be a 4 GB log would take the whole
            // request down with it.
            $contents = $this->repository->setServer($server)->getContent($path, 512 * 1024);
        } catch (Throwable) {
            return null;
        }

        return ArmaConfigFile::parse($contents);
    }

    public function write(Server $server, string $path, ArmaConfigFile $file): void
    {
        $this->repository->setServer($server)->putContent($path, $file->render());
    }

    public function exists(Server $server, string $path): bool
    {
        return $this->read($server, $path) !== null;
    }

    /**
     * Create a config file from a minimal, valid template.
     *
     * Deliberately minimal. A generated server.cfg full of opinionated defaults
     * is a file the customer did not write and cannot tell from one they did —
     * and every value in it is one this plugin would then be silently
     * responsible for. Only the keys with no safe default are written.
     */
    public function scaffold(Server $server, string $path): ArmaConfigFile
    {
        $base = strtolower(basename($path));

        $contents = str_contains($base, 'basic')
            ? "// Created by Arma 3 Manager.\n\nMaxMsgSend = 128;\nMaxSizeGuaranteed = 512;\nMaxSizeNonguaranteed = 256;\nMinBandwidth = 131072;\nMaxCustomFileSize = 0;\n"
            : "// Created by Arma 3 Manager.\n\nhostname = \"" . addslashes($server->name) . "\";\npassword = \"\";\npasswordAdmin = \"\";\nmaxPlayers = 32;\npersistent = 1;\nverifySignatures = 2;\nBattlEye = 1;\n";

        $file = ArmaConfigFile::parse($contents);

        $this->write($server, $path, $file);

        return $file;
    }
}
