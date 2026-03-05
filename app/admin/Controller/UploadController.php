<?php

declare(strict_types=1);

namespace app\admin\Controller;

use core\Auth;
use core\Controller;
use core\Security;

final class UploadController extends Controller
{
    public function index(): void
    {
        if (!Auth::check()) {
            header('Location: /admin/auth/login');
            return;
        }

        if (!Auth::can('system.upload.manage') && !Auth::can('*')) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }

        $result = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $token = (string) ($_POST['_csrf'] ?? '');
            if (!Security::validateCsrf($token)) {
                $result = 'CSRF 校验失败';
            } elseif (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                $result = '未选择文件';
            } else {
                $result = $this->doUpload($_FILES['file']);
            }
        }

        $this->assign('_csrf', Security::csrfToken());
        $this->assign('result', $result);
        $this->display('admin/upload.html');
    }

    /** @param array<string,mixed> $file */
    private function doUpload(array $file): string
    {
        $name = (string) ($file['name'] ?? '');
        $tmp = (string) ($file['tmp_name'] ?? '');
        $error = (int) ($file['error'] ?? 1);

        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            return '上传失败';
        }

        $mime = (string) mime_content_type($tmp);
        if (!Security::isAllowedUpload($name, $mime)) {
            return '文件类型不允许';
        }

        $dir = __DIR__ . '/../../../upload/' . date('Y/m');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $filename = bin2hex(random_bytes(12)) . '.' . $ext;
        $target = $dir . '/' . $filename;

        if (!move_uploaded_file($tmp, $target)) {
            return '保存文件失败';
        }

        $publicPath = '/upload/' . date('Y/m') . '/' . $filename;
        return '上传成功：' . $publicPath;
    }
}
