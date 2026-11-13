<?php

declare(strict_types=1);

namespace OCA\Cognos\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;

class FilesService {
    public const MAX_UPLOAD_BYTES = 26214400; // 25 MiB
    public const MAX_TEXT_READ_BYTES = 16384; // 16 KiB excerpt

    private const IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    private const INLINE_SAFE_MIMES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
    ];
    private const TEXT_EXTS = [
        'txt', 'md', 'markdown', 'json', 'csv', 'tsv', 'log',
        'yml', 'yaml', 'xml', 'html', 'htm', 'css', 'scss',
        'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx',
        'py', 'php', 'sh', 'bash', 'ps1', 'sql', 'ini', 'conf', 'toml',
    ];

    public function __construct(
        private IRootFolder $rootFolder,
    ) {
    }

    public function attachmentsRelPath(int $creationId): string {
        return 'Cognos/attachments/' . $creationId;
    }

    /**
     * Browse user's Nextcloud Files or search by filename.
     *
     * @return array{
     *   dir: string,
     *   attachments_dir: string,
     *   breadcrumbs: list<array{name: string, path: string}>,
     *   items: list<array<string, mixed>>
     * }
     */
    public function browse(string $uid, int $creationId, string $dir = '', string $query = ''): array {
        $userFolder = $this->rootFolder->getUserFolder($uid);
        $attachmentsRel = $this->attachmentsRelPath($creationId);
        $query = trim($query);

        if ($query !== '') {
            $nodes = $userFolder->search($query);
            $items = [];
            foreach ($nodes as $node) {
                $rel = ltrim((string)$userFolder->getRelativePath($node->getPath()), '/');
                if ($rel === '' || $this->isHiddenSystemPath($rel)) {
                    continue;
                }
                $items[] = $this->formatNode($userFolder, $node);
                if (count($items) >= 60) {
                    break;
                }
            }
            $this->sortItems($items);
            return [
                'dir' => $this->normalizeRelPath($dir),
                'attachments_dir' => $attachmentsRel,
                'breadcrumbs' => [],
                'items' => $items,
            ];
        }

        if ($dir === '__attachments__') {
            $dir = $attachmentsRel;
        }
        $cleanDir = $this->normalizeRelPath($dir);

        if ($cleanDir === '') {
            $folder = $userFolder;
        } else {
            try {
                $node = $userFolder->get($cleanDir);
            } catch (NotFoundException) {
                // Attachments folder might not exist until the first upload
                if ($cleanDir === $attachmentsRel || $cleanDir === 'Cognos') {
                    return [
                        'dir' => $cleanDir,
                        'attachments_dir' => $attachmentsRel,
                        'breadcrumbs' => $this->breadcrumbs($cleanDir),
                        'items' => [],
                    ];
                }
                throw new \InvalidArgumentException('找不到该目录：/' . $cleanDir);
            }
            if (!$node instanceof Folder) {
                throw new \InvalidArgumentException('所选路径不是文件夹');
            }
            $folder = $node;
        }

        $items = [];
        foreach ($folder->getDirectoryListing() as $node) {
            $name = $node->getName();
            if ($cleanDir === '' && str_starts_with($name, '.')) {
                continue;
            }
            $items[] = $this->formatNode($userFolder, $node);
        }
        $this->sortItems($items);

        return [
            'dir' => $cleanDir,
            'attachments_dir' => $attachmentsRel,
            'breadcrumbs' => $this->breadcrumbs($cleanDir),
            'items' => $items,
        ];
    }

    /**
     * Save an uploaded file into /Cognos/attachments/{creationId}/ inside user's Nextcloud Files.
     *
     * @param array<string, mixed> $uploaded
     * @return array<string, mixed>
     */
    public function upload(string $uid, int $creationId, array $uploaded): array {
        $err = (int)($uploaded['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException($this->uploadErrorMessage($err));
        }
        $size = (int)($uploaded['size'] ?? 0);
        if ($size <= 0) {
            throw new \InvalidArgumentException('不能上传空文件');
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('文件超过 25 MB 上限');
        }
        $tmpName = (string)($uploaded['tmp_name'] ?? '');
        if ($tmpName === '' || !is_readable($tmpName)) {
            throw new \InvalidArgumentException('上传的临时文件不可读');
        }

        $safeName = $this->sanitizeFilename((string)($uploaded['name'] ?? 'attachment'));
        $folder = $this->ensureAttachmentsFolder($uid, $creationId);
        $finalName = $folder->getNonExistingName($safeName);

        $stream = fopen($tmpName, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('无法打开上传文件流');
        }
        try {
            $file = $folder->newFile($finalName, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $userFolder = $this->rootFolder->getUserFolder($uid);
        $meta = $this->formatNode($userFolder, $file);
        if ($meta['is_text']) {
            $excerpt = $this->readTextExcerpt($file, self::MAX_TEXT_READ_BYTES);
            if ($excerpt !== null) {
                $meta['text_excerpt'] = $excerpt['content'];
                $meta['text_truncated'] = $excerpt['truncated'];
            }
        }
        return $meta;
    }

    public function getFileForUser(string $uid, int $fileId): ?File {
        if ($fileId <= 0) {
            return null;
        }
        $userFolder = $this->rootFolder->getUserFolder($uid);
        $node = $userFolder->getFirstNodeById($fileId);
        return $node instanceof File ? $node : null;
    }

    /** @return array<string, mixed> */
    public function describeFile(string $uid, File $file): array {
        $userFolder = $this->rootFolder->getUserFolder($uid);
        return $this->formatNode($userFolder, $file);
    }

    /** @return ?array{content: string, truncated: bool} */
    public function readTextExcerpt(File $file, int $maxBytes = self::MAX_TEXT_READ_BYTES): ?array {
        if (!$this->isTextNode($file)) {
            return null;
        }
        $size = (int)$file->getSize();
        if ($size <= 0) {
            return ['content' => '', 'truncated' => false];
        }
        if ($size > 2 * 1024 * 1024) {
            return null;
        }
        $raw = '';
        $stream = $file->fopen('rb');
        if (is_resource($stream)) {
            try {
                $raw = (string)fread($stream, $maxBytes + 1);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } else {
            $raw = substr((string)$file->getContent(), 0, $maxBytes + 1);
        }
        $truncated = strlen($raw) > $maxBytes;
        if ($truncated) {
            $raw = substr($raw, 0, $maxBytes);
        }
        // Strip UTF-8 BOM
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8,GB18030,GBK,ISO-8859-1');
        }
        if ($truncated) {
            // Trim trailing incomplete multibyte sequence cleanly
            $raw = mb_substr($raw, 0, mb_strlen($raw, 'UTF-8'), 'UTF-8');
        }
        return ['content' => $raw, 'truncated' => $truncated];
    }

    public function isInlineSafeMime(string $mime): bool {
        return in_array(strtolower(trim(strtok($mime, ';') ?: '')), self::INLINE_SAFE_MIMES, true);
    }

    private function ensureAttachmentsFolder(string $uid, int $creationId): Folder {
        $userFolder = $this->rootFolder->getUserFolder($uid);
        $cognos = $userFolder->getOrCreateFolder('Cognos');
        $attachments = $cognos->getOrCreateFolder('attachments');
        return $attachments->getOrCreateFolder((string)$creationId);
    }

    private function normalizeRelPath(string $raw): string {
        $p = str_replace('\\', '/', trim($raw));
        if ($p === '' || $p === '/') {
            return '';
        }
        if (str_contains($p, "\0")) {
            throw new \InvalidArgumentException('无效路径');
        }
        $parts = [];
        foreach (explode('/', trim($p, '/')) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                throw new \InvalidArgumentException('无效路径');
            }
            $parts[] = $seg;
        }
        return implode('/', $parts);
    }

    private function isHiddenSystemPath(string $relPath): bool {
        $first = strtok($relPath, '/');
        return is_string($first) && str_starts_with($first, '.');
    }

    private function sanitizeFilename(string $raw): string {
        $name = basename(str_replace('\\', '/', trim($raw)));
        $name = preg_replace('/[\x00-\x1f\x7f\/:*?"<>|]+/u', '_', $name) ?? 'attachment';
        $name = trim($name, " .\t\r\n");
        if ($name === '' || $name === '.' || $name === '..') {
            return 'attachment-' . date('Ymd-His');
        }
        if (mb_strlen($name, 'UTF-8') > 180) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 140, 'UTF-8');
            $name = $ext !== '' ? ($base . '.' . $ext) : $base;
        }
        return $name;
    }

    /** @return list<array{name: string, path: string}> */
    private function breadcrumbs(string $cleanDir): array {
        $crumbs = [['name' => '全部文件', 'path' => '']];
        if ($cleanDir === '') {
            return $crumbs;
        }
        $acc = '';
        foreach (explode('/', $cleanDir) as $seg) {
            $acc = $acc === '' ? $seg : $acc . '/' . $seg;
            $crumbs[] = ['name' => $seg, 'path' => $acc];
        }
        return $crumbs;
    }

    /** @return array<string, mixed> */
    private function formatNode(Folder $userFolder, Node $node): array {
        $id = (int)$node->getId();
        $rel = ltrim((string)$userFolder->getRelativePath($node->getPath()), '/');
        $isDir = $node instanceof Folder;
        $ext = $isDir ? '' : strtolower((string)$node->getExtension());
        $mime = $isDir ? 'httpd/unix-directory' : (string)$node->getMimeType();
        return [
            'id' => $id,
            'name' => $node->getName(),
            'path' => '/' . $rel,
            'rel_path' => $rel,
            'type' => $isDir ? 'dir' : 'file',
            'size' => (int)$node->getSize(),
            'mtime' => (int)$node->getMTime(),
            'mime' => $mime,
            'ext' => $ext,
            'is_image' => !$isDir && in_array($ext, self::IMAGE_EXTS, true),
            'is_text' => !$isDir && $this->isTextNode($node),
            'files_url' => $isDir
                ? ('/index.php/apps/files/?dir=' . rawurlencode('/' . $rel))
                : ('/index.php/apps/files/files/' . $id . '?openfile=true'),
            'raw_url' => $isDir ? null : ('/index.php/apps/cognos/files/' . $id . '/raw'),
        ];
    }

    private function isTextNode(Node $node): bool {
        if (!$node instanceof File) {
            return false;
        }
        $ext = strtolower((string)$node->getExtension());
        if (in_array($ext, self::TEXT_EXTS, true)) {
            return true;
        }
        $mime = strtolower((string)$node->getMimeType());
        return str_starts_with($mime, 'text/')
            || $mime === 'application/json'
            || $mime === 'application/xml'
            || $mime === 'application/javascript';
    }

    /** @param list<array<string, mixed>> $items */
    private function sortItems(array &$items): void {
        usort($items, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            if ($a['type'] === 'file' && $a['mtime'] !== $b['mtime']) {
                return $b['mtime'] <=> $a['mtime'];
            }
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
    }

    private function uploadErrorMessage(int $code): string {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件超过服务器允许的最大上传限制',
            UPLOAD_ERR_PARTIAL => '文件只上传了一部分，请重试',
            UPLOAD_ERR_NO_FILE => '没有收到文件',
            default => '上传失败（错误码 ' . $code . '）',
        };
    }
}
