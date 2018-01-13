<?php
declare(strict_types=1);
namespace Airship\Cabin\Bridge\Controller\Proto;

use Airship\Alerts\FileSystem\{
    FileNotFound,
    UploadError
};
use Airship\Cabin\Bridge\Model\Files;
use Airship\Cabin\Bridge\Filter\FileManager\{
    MoveDirFilter,
    MoveFileFilter,
    NewDirFilter
};
use Airship\Engine\Bolt\Get;
use Airship\Cabin\Bridge\Controller\LoggedInUsersOnly;
use Airship\Engine\Model;
use Psr\Log\LogLevel;

/**
 * Implements <most> of the file manager. Several Controllers will exist that are
 * customized for different use-cases.
 */
class FileManager extends LoggedInUsersOnly
{
    use Get;

    /**
     * @var array
     */
    protected $attribution = []; // For uploads

    /**
     * @var string
     */
    protected $root_dir = '';

    /**
     * @var string
     */
    protected $path_middle = '';

    /**
     * @var Files
     */
    protected $files;

    /**
     * This function is called after the dependencies have been injected by
     * AutoPilot. Think of it as a user-land constructor.
     */
    public function airshipLand(): void
    {
        parent::airshipLand();
        $files = $this->model('Files');
        if (!($files instanceof Files)) {
            throw new \TypeError(Model::TYPE_ERROR);
        }
        $this->files = $files;
        $this->includeAjaxToken();
    }

    /**
     * Permissions check -- override this in base classes
     *
     * @return bool
     * @throws \Error
     */
    protected function permCheck(): bool
    {
        throw new \Error('NOT IMPLEMENTED IN DERIVED CLASS!');
    }

    /**
     * Confirm directory deletion
     *
     * @param string $path
     * @param string $cabin
     */
    protected function commonConfirmDeleteDir(string $path, string $cabin): void
    {
        if (!$this->permCheck()) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        list($publicPath, $root) = $this->loadCommonData($path, $cabin);
        if (empty($root)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $forParent = \Airship\chunk($path);
        \array_pop($forParent);
        $parent = \implode('/', $forParent);

        $contents = $this->files->getContentsTree($cabin, $this->root_dir, $path);

        $post = $this->post(/* No data is passed */);
        if (!empty($post)) {
            $this->files->deleteDir($cabin, $this->root_dir, $path);
            \Airship\redirect(
                $this->airship_cabin_prefix . '/' . $this->path_middle . '/' . $cabin,
                [
                    'dir' => $parent
                ]
            );
        }

        $this->view('files/delete_dir', [
            'cabins' => $this->getCabinNamespaces(),
            'root_dir' => $this->root_dir,
            'dir_contents' => $contents,
            // Untrusted, from the end user:
            'parent_dir' => $parent,
            'dir' => $this->root_dir . '/' . $path,
            'cabin' => $cabin,
            'pathinfo' => $publicPath
        ]);
    }

    /**
     * Confirm file deletion
     *
     * @param string $file
     * @param string $path
     * @param string $cabin
     */
    protected function commonConfirmDeleteFile(string $file, string $path, string $cabin): void
    {
        if (!$this->permCheck()) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        list($publicPath, $root) = $this->loadCommonData($path, $cabin);
        if (empty($root)) {
            $fileInfo = $this->files->getFileInfo($cabin, null, $file);
        } else {
            $fileInfo = $this->files->getFileInfo($cabin, $root, $file);
        }
        $post = $this->post(/* No data is passed */);
        if (!empty($post)) {
            $this->files->deleteFile($fileInfo);
            \Airship\redirect(
                $this->airship_cabin_prefix . '/' . $this->path_middle . '/' . $cabin,
                [
                    'dir' => $path
                ]
            );
        }

        $this->view('files/delete', [
            'cabins' => $this->getCabinNamespaces(),
            'file' => $fileInfo,
            'root_dir' => $this->root_dir,
            // Untrusted, from the end user:
            'dir' => $this->root_dir . '/' . $path,
            'cabin' => $cabin,
            'pathinfo' => $publicPath
        ]);
    }

    /**
     * Get information about a file
     *
     * @param string $file
     * @param string $path
     * @param string $cabin
     */
    protected function commonGetFileInfo(string $file, string $path, string $cabin): void
    {
        $file = \rtrim($file, '/');
        list($publicPath, $root) = $this->loadCommonData($path, $cabin);
        if (empty($root)) {
            $fileInfo = $this->files->getFileInfo($cabin, null, $file);
        } else {
            $fileInfo = $this->files->getFileInfo($cabin, $root, $file);
        }

        $this->view('files/info', [
            'cabins' => $this->getCabinNamespaces(),
            'file' => $fileInfo,
            'root_dir' => $this->root_dir,
            'all_dirs' => $this->files->getDirectoryTree($cabin, $this->root_dir),
            // Untrusted, from the end user:
            'dir' => $path,
            'cabin' => $cabin,
            'pathinfo' => $publicPath
        ]);
    }

    /**
     * Move/Rename a directory
     *
     * @param string $path
     * @param string $cabin
     */
    protected function commonMoveDir(string $path, string $cabin): void
    {
        if (!$this->permCheck()) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        list($publicPath, $root) = $this->loadCommonData($path, $cabin);
        if (empty($root)) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $forParent = \Airship\chunk($path);
        $dir_name = \array_pop($forParent);
        $parent = \implode('/', $forParent);

        $contents = $this->files->getContentsTree(
            $cabin,
            $this->root_dir,
            $path
        );

        $post = $this->post(new MoveDirFilter());
        if (!empty($post)) {
            if ($this->files->moveDir($cabin, $this->root_dir, $path, $post)) {
                \Airship\redirect(
                    $this->airship_cabin_prefix . '/' . $this->path_middle . '/' . $cabin,
                    [
                        'dir' => $parent
                    ]
                );
            }
        }
        $ignore = $path . '/';

        $this->view('files/move_dir', [
            'cabins' => $this->getCabinNamespaces(),
            'root_dir' => $this->root_dir,
            'dir_contents' => $contents,
            'all_dirs' => $this->files->getDirectoryTree(
                $cabin,
                $this->root_dir,
                $ignore
            ),
            // Untrusted, from the end user:
            'parent_dir' => $parent,
            'dir' => $path,
            'dir_name' => $dir_name,
            'cabin' => $cabin,
            'pathinfo' => $publicPath
        ]);
    }

    /**
     * Move/rename a file
     *
     * @param string $file
     * @param string $path
     * @param string $cabin
     */
    protected function commonMoveFile(string $file, string $path, string $cabin): void
    {
        if (!$this->permCheck()) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        list($publicPath, $root) = $this->loadCommonData($path, $cabin);

        if (empty($root)) {
            $fileInfo = $this->files->getFileInfo($cabin, null, $file);
        } else {
            $fileInfo = $this->files->getFileInfo($cabin, $root, $file);
        }
        $post = $this->post(new MoveFileFilter());
        if (!empty($post)) {
            $this->files->moveFile($fileInfo, $post, $cabin);
            \Airship\redirect(
                $this->airship_cabin_prefix . '/' . $this->path_middle . '/' . $cabin,
                [
                    'dir' => $path
                ]
            );
        }

        $this->view('files/move', [
            'cabins' => $this->getCabinNamespaces(),
            'file' => $fileInfo,
            'root_dir' => $this->root_dir,
            'all_dirs' => $this->files->getDirectoryTree($cabin, $this->root_dir),
            // Untrusted, from the end user:
            'dir' => $path,
            'cabin' => $cabin,
            'pathinfo' => $publicPath
        ]);
    }

    /**
     * Process the landing page.
     *
     * @param string $path
     * @param string $cabin
     */
    protected function commonIndex(string $path, string $cabin): void
    {
        list($publicPath, $root) = $this->loadCommonData($path, $cabin);

        $post = $this->post(new NewDirFilter());
        if (!empty($post['submit_btn'])) {
            switch ($post['submit_btn']) {
                case 'new_dir':
                    $status = $this->createDir($root, $cabin, $post);
                    break;
                case 'upload':
                    $status = $this->uploadFiles($root, $cabin);
                    break;
                default:
                    $status = [
                        'status' => 'ERROR',
                        'message' => \__('Unknown operation')
                    ];
            }
            if ($status['status'] === 'SUCCESS') {
                \Airship\redirect(
                    $this->airship_cabin_prefix . '/' . $this->path_middle . '/' . $cabin,
                    [
                        'dir' => $path
                    ]
                );
            }
            $this->storeViewVar('form_status', $status);
        }

        $this->view('files/index', [
            'cabins' => $this->getCabinNamespaces(),
            'subdirs' => $this->files->getChildrenOf($root, $cabin),
            'files' => $this->files->getFilesInDirectory($root, $cabin),
            'pathinfo' => $publicPath,
            // Untrusted, from the end user:
            'current' => $path,
            'cabin' => $cabin
        ]);
    }

    /**
     * Process the landing page.
     */
    public function commonSelectCabin(): void
    {
        if (!$this->permCheck()) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $this->view('files', [
            'cabins' => $this->getCabinNamespaces()
        ]);
    }

    /**
     * Create a new directory for file uploads
     *
     * @param int $directoryId
     * @param string $cabin
     * @param array $post
     * @return array
     */
    protected function createDir($directoryId = null, string $cabin = '', array $post = []): array
    {
        if (!\array_key_exists('directory', $post)) {
            return [
                'status' => 'ERROR',
                'message' => 'Directory names cannot be empty'
            ];
        }
        if (!$this->files->isValidName($post['directory'])) {
            return [
                'status' => 'ERROR',
                'message' => 'Invalid directory name'
            ];
        }
        if ($this->files->dirExists($directoryId, $cabin, $post['directory'])) {
            return [
                'status' => 'ERROR',
                'message' => 'This directory already exists'
            ];
        }
        if ($this->files->createDirectory($directoryId, $cabin, $post['directory'])) {
            return [
                'status' => 'SUCCESS',
                'message' => 'This directory has been created sucessfully'
            ];
        }
        return [
            'status' => 'UNKNOWN',
            'message' => 'An unknown error has occurred.'
        ];
    }

    /**
     * Don't break on bad Apache/nginx configuration (which is common)
     *
     * @param string& $cabin
     * @return string
     */
    protected function determinePath(string &$cabin): string
    {
        $this->httpGetParams($cabin);
        if (!\array_key_exists('dir', $_GET)) {
            return '';
        }
        if (!\is_string($_GET['dir'])) {
            return '';
        }
        return $_GET['dir'];
    }

    /**
     * Given a string (and a predetermined current root directory), get
     * a sequence of folder names to determine the current path
     *
     * @param string $path
     * @return array
     */
    protected function getPath(string $path): array
    {
        if (empty($this->root_dir)) {
            if (empty($path)) {
                return [];
            }
            return \Airship\chunk($path, '/');
        }
        $split = \Airship\chunk($path, '/');
        $base = \Airship\chunk($this->root_dir, '/');
        foreach ($split as $piece) {
            if (!empty($piece)) {
                $base [] = $piece;
            }
        }
        return $base;
    }

    /**
     * Reduce code duplication
     *
     * @param string $path
     * @param string $cabin
     * @return array (array $publicPath, int|null $root)
     */
    protected function loadCommonData(string $path, string $cabin): array
    {
        if (!$this->permCheck()) {
            \Airship\redirect($this->airship_cabin_prefix);
        }
        $root = null;
        $publicPath = \Airship\chunk($path);
        $pathInfo = $this->getPath($path);

        if (!empty($pathInfo)) {
            try {
                $root = $this->files->getDirectoryId($pathInfo, $cabin);
            } catch (FileNotFound $ex) {
                \Airship\redirect($this->airship_cabin_prefix);
            }
        }
        // return [$pathInfo, $publicPath, $root];
        return [$publicPath, $root];
    }

    /**
     * Upload files
     *
     * @param int $directoryId
     * @param string $cabin
     * @return array
     */
    protected function uploadFiles($directoryId = null, string $cabin = ''): array
    {
        $results = [];
        $newFiles = $this->files->isolateFiles($_FILES['new_files']);
        if (empty($newFiles)) {
            return [
                'status' => 'ERROR',
                'message' => 'No files were uploaded.'
            ];
        }
        foreach ($newFiles as $file) {
            try {
                $results[] = $this->files->processUpload(
                    $directoryId,
                    $cabin,
                    $file,
                    $this->attribution
                );
            } catch (UploadError $ex) {
                $this->log(
                    'File upload failed',
                    LogLevel::ERROR,
                    \Airship\throwableToArray($ex)
                );
            }
        }
        return [
            'status' => 'SUCCESS',
            'message' => 'Upload successful'
        ];
    }
}
