<?php

namespace App\DTOs\Documents;

use LogicException;

final class VerifiedDownloadFile
{
    /** @param resource $handle */
    public function __construct(
        public readonly string $path,
        public readonly int $sizeBytes,
        public readonly string $mimeType,
        public readonly string $originalName,
        private mixed $handle,
    ) {
        // Covers PHP exit/fatal shutdown as well as normal response/destructor cleanup.
        // The closed resource check prevents a later shutdown deleting a reused path.
        register_shutdown_function(static function () use ($handle, $path): void {
            if (is_resource($handle)) {
                fclose($handle);
                @unlink($path);
            }
        });
    }

    /** @return resource */
    public function stream(): mixed
    {
        if (! is_resource($this->handle)) {
            throw new LogicException('The verified response has already been closed.');
        }

        return $this->handle;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
            $this->handle = null;
            @unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
