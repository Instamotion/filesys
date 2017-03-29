<?php namespace Crip\Filesys\Services;

use Crip\Core\Contracts\ICripObject;
use Crip\Core\Helpers\Slug;
use Crip\Core\Helpers\Str;
use Crip\Core\Support\PackageBase;
use Crip\Filesys\App\File;
use Crip\Filesys\App\Folder;
use Illuminate\Http\UploadedFile;

/**
 * Class FilesysManager
 * @package Crip\Filesys\Services
 */
class FilesysManager implements ICripObject
{
    /**
     * @var Blob
     */
    private $blob = null;

    /**
     * @var PackageBase
     */
    private $package;

    /**
     * @var \Illuminate\Filesystem\FilesystemAdapter
     */
    private $storage;

    private $metadata = null;

    /**
     * FilesysManager constructor.
     * @param PackageBase $package
     * @param string $path
     */
    public function __construct(PackageBase $package, $path = '')
    {
        $this->blob = new Blob($package, $path);
        $this->package = $package;
        $this->storage = app()->make('filesystem');
    }

    /**
     * Write the contents of a file.
     * @param UploadedFile $uploadedFile
     * @return array|File|Folder
     * @throws \Exception
     */
    public function upload(UploadedFile $uploadedFile)
    {
        if ($this->blob === null) {
            throw new \Exception('Blob path is not set yet.');
        }

        $this->makeDirectory();

        $path = $this->blob->path;
        $ext = $uploadedFile->getClientOriginalExtension();
        $name = Slug::make(pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME));
        $uniqueName = $this->getUniqueFileName($path, $name, $ext);

        $fullName = $uniqueName . '.' . $ext;

        $this->storage->putFileAs($path, $uploadedFile, $fullName);

        $path .= '/' . $fullName;

        $this->blob->path = $path;

        if ($this->getMetaData()->isImage()) {
            (new ThumbService($this->package))
                ->resize($this->getMetaData()->getPath());
        }

        return $this->fullDetails();
    }

    /**
     * Rename blob.
     * @param string $name
     * @return File|Folder
     */
    public function rename($name)
    {
        $name = Str::slug($name);
        if ($this->isFile()) {
            return $this->renameFile($name, $this->getMetaData()->getExtension());
        }

        return $this->renameFolder($name);
    }

    /**
     * @return bool
     */
    public function delete()
    {
        $meta = $this->getMetaData();

        if ($meta->isImage() || !$meta->isFile()) {
            $service = new ThumbService($this->package);
            $service->delete($meta->getPath(), !$meta->isFile());
        }

        if ($meta->isFile()) {
            return $this->storage->delete($meta->getPath());
        }

        return $this->storage->deleteDirectory($meta->getPath());
    }

    /**
     * Create a directory.
     * @param string $subDir
     * @return FilesysManager
     * @throws \Exception
     */
    public function makeDirectory($subDir = '')
    {
        if ($this->blob === null) {
            throw new \Exception('Blob path is not set yet.');
        }

        if ($subDir) {
            $name = $this->getUniqueFileName($this->blob->path, Str::slug($subDir));
            $this->blob->path = trim($this->blob->path . '/' . $name, '/\\');
        }

        if ($this->blob->path !== '') {
            $this->storage->makeDirectory($this->blob->path);
        }

        return $this;
    }

    /**
     * Get the content of a file.
     * @return string
     */
    public function fileContent()
    {
        // TODO: here should be placed validation on visibility
        return $this->storage->get($this->blob->path);
    }

    public function folderContent()
    {
        $result = [];
        $list = collect($this->storage->getDriver()->listContents($this->blob->path))
            ->pluck('path');

        $exclude = (new ThumbService($this->package))->getSizes()->all();
        $isExcluded = function ($path) use ($exclude) {
            $parts = explode('/', $path);
            if (count($parts) > 0) {
                return array_key_exists($parts[0], $exclude);
            }

            return false;
        };

        $list->each(function ($glob) use (&$result, $isExcluded) {
            if ($isExcluded($glob)) {
                // skip any thumbs dir and do not show it for users
                return;
            }

            $result[] = (new Blob($this->package, $glob))->fullDetails();
        });

        return $result;
    }

    /**
     * @return bool
     */
    public function blobExists()
    {
        if ($this->blob->path . '' === '') {
            return true;
        }

        return $this->storage->exists($this->blob->path);
    }

    /**
     * @return bool
     */
    public function isFile()
    {
        if ($this->blobExists()) {
            $metadata = $this->storage->getMetaData($this->blob->path);

            return $metadata['type'] === 'file';
        }

        return false;
    }

    /**
     * Get file mime type.
     * @return string
     */
    public function fileMimeType()
    {
        return $this->storage->mimeType($this->blob->path);
    }

    /**
     * Determines is the file safe for upload.
     * @param string $ext
     * @param string $mime
     * @return bool
     */
    public function isSafe($ext, $mime)
    {
        $unsafeExtensions = $this->package->config('block.extensions');
        $unsafeMimes = $this->package->config('block.mimetypes');
        $mimeSearch = function ($mimeValue) use ($mime) {
            return preg_match($mimeValue, $mime);
        };

        if (in_array($ext, $unsafeExtensions)) {
            return false;
        }

        if (collect($unsafeMimes)->search($mimeSearch)) {
            return false;
        }

        return true;
    }

    /**
     * @return BlobMetadata
     */
    public function getMetaData()
    {
        if (!$this->metadata) {
            $this->metadata = new BlobMetadata($this->blob->path);
        }

        return $this->metadata;
    }

    /**
     * @return File|Folder
     */
    public function fullDetails()
    {
        return (new Blob($this->package, $this->blob->path))
            ->fullDetails($this->getMetaData());
    }

    /**
     * Get unique name for a file/folder in system path
     * @param $path string System full path
     * @param $name string File/Folder name
     * @param null $ext File extension
     * @return string Unique name
     */
    private function getUniqueFileName($path, $name, $ext = null)
    {
        $originalName = $name;
        $i = 0;

        do {
            $fullPath = $path . '/' . $name . ($ext ? '.' . $ext : '');
        } while ($this->storage->exists($fullPath) && $name = $originalName . '-' . ++$i);

        return $name;
    }

    /**
     * @param $name
     * @param $extension
     * @return File|Folder
     */
    private function renameFile($name, $extension)
    {
        $meta = $this->getMetaData();
        $newName = $this->getUniqueFileName($meta->getDir(), $name, $extension);
        if ($meta->isImage()) {
            (new ThumbService($this->package))->rename(
                $meta->getPath(),
                $newName,
                $meta->getExtension());
        }

        $newPath = $meta->getDir() . '/' . $newName . '.' . $meta->getExtension();
        $this->blob->path = $newPath;
        $this->storage->move($meta->getPath(), $newPath);

        return $this->fullDetails();
    }

    private function renameFolder($name)
    {

    }
}