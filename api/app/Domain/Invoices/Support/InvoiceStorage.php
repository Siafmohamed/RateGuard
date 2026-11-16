<?php

namespace App\Domain\Invoices\Support;

use App\Models\Organization;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Stores raw carrier-invoice files on a private disk.
 *
 * The disk is resolved exclusively through config('rateguard.invoice_disk'), so
 * no other class in the codebase names a disk and the storage backend can be
 * swapped with an env change alone.
 *
 * Layout: invoices/{organization_id}/{uuid}.csv — one folder per tenant.
 */
class InvoiceStorage
{
    /**
     * Persist an uploaded invoice file and return its path on the disk.
     */
    public function store(UploadedFile $file, Organization $organization): string
    {
        $directory = 'invoices/'.$organization->getKey();

        return $this->disk()->putFileAs(
            $directory,
            $file,
            Str::uuid()->toString().'.csv',
            ['visibility' => 'private'],
        );
    }

    /**
     * Read the whole file as a string.
     */
    public function read(string $path): string
    {
        return $this->disk()->get($path);
    }

    /**
     * Open a read stream for the file, for chunked/streamed readers.
     *
     * @return resource
     */
    public function stream(string $path)
    {
        return $this->disk()->readStream($path);
    }

    /**
     * Remove a stored file. Safe to call twice.
     */
    public function delete(string $path): bool
    {
        return $this->disk()->delete($path);
    }

    /**
     * The disk invoices are stored on. The only disk name in the application.
     */
    protected function disk(): Filesystem
    {
        return \Illuminate\Support\Facades\Storage::disk(config('rateguard.invoice_disk'));
    }
}
